<?php

declare(strict_types=1);

use App\Services\RepositoryGitInitializer;
use App\Services\RepositoryProvisioner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\RepositoryPayload;

beforeEach(function (): void {
    config()->set('services.cloudflare', ['account_id' => str_repeat('a', 32), 'api_token' => 'test-api-token']);
    $this->repository = RepositoryPayload::create();
    $this->commit = app(RepositoryGitInitializer::class)->commitHash($this->repository);
    $this->info = [
        'id' => 'repo_123', 'description' => 'Daedalus onboarding '.$this->repository->id,
        'default_branch' => 'main', 'remote' => 'https://'.str_repeat('a', 32).'.artifacts.cloudflare.net/git/daedalus/example-project.git',
    ];
    $this->git = $this->mock(RepositoryGitInitializer::class);
    $this->git->shouldReceive('commitHash')->andReturn($this->commit);
});

function artifactEnvelope(array $result): array
{
    return ['success' => true, 'result' => $result, 'errors' => [], 'messages' => []];
}

it('creates the repository, revokes credentials, initializes main and records verified readiness', function (): void {
    Http::fakeSequence()
        ->push([], 404)
        ->push(artifactEnvelope(array_merge($this->info, ['token' => 'creation-secret'])), 201)
        ->push(artifactEnvelope([['id' => 'creation-token-id']]))
        ->push(artifactEnvelope(['id' => 'creation-token-id']))
        ->push(artifactEnvelope([]))
        ->push([], 404)
        ->push(artifactEnvelope(['id' => 'write-token-id', 'plaintext' => 'write-secret']), 201)
        ->push(artifactEnvelope(['id' => 'write-token-id']))
        ->push(artifactEnvelope([['hash' => $this->commit]]));
    $this->git->shouldReceive('push')->once()->withArgs(fn ($repository, $remote, $token): bool => $repository->id === $this->repository->id && $remote === $this->info['remote'] && $token === 'write-secret');

    app(RepositoryProvisioner::class)->provision($this->repository);
    expect($this->repository->fresh()->status)->toBe('ready')
        ->and($this->repository->fresh()->artifacts_id)->toBe('repo_123')
        ->and($this->repository->fresh()->initial_commit)->toBe($this->commit);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/repos')
        && $request->data() === ['name' => 'example-project', 'description' => 'Daedalus onboarding '.$this->repository->id, 'default_branch' => 'main']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === ['repo' => 'example-project', 'scope' => 'write', 'ttl' => 600]);
    Http::assertSentCount(9);
    expect(json_encode($this->repository->fresh()->toArray()))->not->toContain('secret');
});

it('recovers a lost create or push acknowledgement without recreating or pushing again', function (): void {
    Http::fakeSequence()->push(artifactEnvelope($this->info))->push(artifactEnvelope([]))
        ->push(artifactEnvelope([['hash' => $this->commit]]))->push(artifactEnvelope([['hash' => $this->commit]]));
    $this->git->shouldNotReceive('push');
    app(RepositoryProvisioner::class)->provision($this->repository);
    expect($this->repository->fresh()->status)->toBe('ready');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('refuses to attach another repository or a different default branch', function (array $changes): void {
    Http::fakeSequence()->push(artifactEnvelope(array_merge($this->info, $changes)));
    $this->git->shouldNotReceive('push');
    expect(fn () => app(RepositoryProvisioner::class)->provision($this->repository))->toThrow(RuntimeException::class, 'reserved identity');
    expect($this->repository->fresh()->artifacts_id)->toBeNull();
    Http::assertSentCount(1);
})->with(['ownership marker' => [['description' => 'Someone else']], 'default branch' => [['default_branch' => 'other']]]);

it('refuses an Artifacts identity change', function (): void {
    $this->repository->update(['artifacts_id' => 'original-id']);
    Http::fakeSequence()->push(artifactEnvelope($this->info));
    expect(fn () => app(RepositoryProvisioner::class)->provision($this->repository))->toThrow(RuntimeException::class, 'reserved identity');
});

it('refuses to overwrite a changed main branch', function (): void {
    Http::fakeSequence()->push(artifactEnvelope($this->info))->push(artifactEnvelope([]))->push(artifactEnvelope([['hash' => str_repeat('b', 40)]]));
    $this->git->shouldNotReceive('push');
    expect(fn () => app(RepositoryProvisioner::class)->provision($this->repository))->toThrow(RuntimeException::class, 'refusing to overwrite');
    expect($this->repository->fresh()->status)->toBe('provisioning');
});

it('revokes the temporary write token even when push fails', function (): void {
    Http::fakeSequence()->push(artifactEnvelope($this->info))->push(artifactEnvelope([]))->push(artifactEnvelope([]))
        ->push(artifactEnvelope(['id' => 'write-id', 'plaintext' => 'write-secret']))->push(artifactEnvelope(['id' => 'write-id']));
    $this->git->shouldReceive('push')->once()->andThrow(new RuntimeException('Push failed'));
    expect(fn () => app(RepositoryProvisioner::class)->provision($this->repository))->toThrow(RuntimeException::class, 'Push failed');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/tokens/write-id'));
    expect($this->repository->fresh()->status)->toBe('provisioning');
});

it('does not report readiness until the initial commit is confirmed', function (): void {
    Http::fakeSequence()->push(artifactEnvelope($this->info))->push(artifactEnvelope([]))->push(artifactEnvelope([['hash' => $this->commit]]))->push(artifactEnvelope([]));
    expect(fn () => app(RepositoryProvisioner::class)->provision($this->repository))->toThrow(RuntimeException::class, 'Could not confirm');
    expect($this->repository->fresh()->initial_commit)->toBeNull()->and($this->repository->fresh()->status)->toBe('provisioning');
});

it('retries when a creation token is not yet visible rather than leaving it active', function (): void {
    Http::fakeSequence()->push([], 404)->push(artifactEnvelope($this->info), 201)->push(artifactEnvelope([]));
    $this->git->shouldNotReceive('push');
    expect(fn () => app(RepositoryProvisioner::class)->provision($this->repository))->toThrow(RuntimeException::class, 'Waiting for the creation token');
});
