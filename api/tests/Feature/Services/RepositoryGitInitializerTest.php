<?php

declare(strict_types=1);

use App\Services\RepositoryGitInitializer;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\RepositoryPayload;

beforeEach(function (): void {
    config()->set('services.cloudflare.account_id', str_repeat('a', 32));
    $this->repository = RepositoryPayload::create();
    $this->initializer = app(RepositoryGitInitializer::class);
    $this->remote = 'https://'.str_repeat('a', 32).'.artifacts.cloudflare.net/git/daedalus/example-project.git';
});

it('creates a deterministic empty commit and pushes without storing credentials on disk', function (): void {
    $commit = $this->initializer->commitHash($this->repository);
    Process::fake(fn (PendingProcess $process) => match ($process->command[1]) {
        'mktree' => Process::result(output: "4b825dc642cb6eb9a060e54bf8d69288fbee4904\n"),
        'commit-tree' => Process::result(output: $commit."\n"),
        default => Process::result(),
    });
    $this->initializer->push($this->repository, $this->remote, 'write-secret');
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['git', 'push', $this->remote, 'refs/heads/main:refs/heads/main']
        && $process->environment['GIT_CONFIG_VALUE_0'] === 'Authorization: Basic '.base64_encode('x:write-secret')
        && $process->environment['GIT_CONFIG_VALUE_1'] === 'false'
        && ! File::exists($process->path));
    Process::assertRan(fn (PendingProcess $process): bool => $process->command[1] === 'commit-tree'
        && $process->input === "Initialize repository\n"
        && $process->environment['GIT_AUTHOR_DATE'] === '@'.$this->repository->created_at->timestamp.' +0000');
});

it('produces the expected commit with real local Git while faking the network push', function (): void {
    $expected = $this->initializer->commitHash($this->repository);
    Process::fake(['*push*' => function (PendingProcess $process) use ($expected) {
        $ref = File::get($process->path.'/.git/refs/heads/main');
        expect(trim($ref))->toBe($expected)
            ->and(File::get($process->path.'/.git/config'))->not->toContain('write-secret');

        return Process::result();
    }]);
    $this->initializer->push($this->repository, $this->remote, 'write-secret');
    Process::assertRan(fn (PendingProcess $process): bool => $process->command[1] === 'push' && ! File::exists($process->path));
});

it('rejects unsafe Git remotes before invoking Git', function (string $remote): void {
    Process::fake();
    expect(fn () => $this->initializer->push($this->repository, $remote, 'secret'))->toThrow(RuntimeException::class, 'Invalid Cloudflare');
    Process::assertNothingRan();
})->with(['http://example.com/repo.git', 'https://user:password@example.com/repo.git', 'https://evil.example.com/repo.git']);

it('cleans up temporary files and sanitizes Git failures', function (): void {
    Process::fake(fn () => Process::result(errorOutput: 'private-token', exitCode: 1));
    expect(fn () => $this->initializer->push($this->repository, $this->remote, 'secret'))->toThrow(RuntimeException::class, 'Repository Git initialization failed.');
    Process::assertRan(fn (PendingProcess $process): bool => ! File::exists($process->path));
});

it('preserves the original root commit hash across retries', function (): void {
    $expected = $this->initializer->commitHash($this->repository);
    $this->repository->touch();
    expect($this->initializer->commitHash($this->repository->fresh()))->toBe($expected);
});
