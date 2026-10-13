<?php

namespace App\Services;

use App\Models\Repository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RepositoryGitInitializer
{
    public function commitHash(Repository $repository): string
    {
        $identity = 'Maia <maia@daedalus.invalid> '.$repository->created_at->timestamp.' +0000';
        $content = "tree 4b825dc642cb6eb9a060e54bf8d69288fbee4904\nauthor ".$identity."\ncommitter ".$identity."\n\nInitialize repository\n";

        return sha1('commit '.strlen($content)."\0".$content);
    }

    public function push(Repository $repository, string $remote, string $token): void
    {
        $expectedRemote = 'https://'.config('services.cloudflare.account_id').'.artifacts.cloudflare.net/git/'.$repository->artifacts_namespace.'/'.$repository->artifacts_name.'.git';
        if ($remote !== $expectedRemote) {
            throw new RuntimeException('Invalid Cloudflare Artifacts Git remote.');
        }

        $directory = storage_path('app/private/provisioning/'.Str::uuid());
        File::ensureDirectoryExists($directory, 0700);
        $environment = [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_AUTHOR_NAME' => 'Maia',
            'GIT_AUTHOR_EMAIL' => 'maia@daedalus.invalid',
            'GIT_COMMITTER_NAME' => 'Maia',
            'GIT_COMMITTER_EMAIL' => 'maia@daedalus.invalid',
            'GIT_AUTHOR_DATE' => '@'.$repository->created_at->timestamp.' +0000',
            'GIT_COMMITTER_DATE' => '@'.$repository->created_at->timestamp.' +0000',
        ];

        try {
            $this->run($directory, $environment, ['git', 'init', '--object-format=sha1', '--initial-branch=main']);
            $tree = trim($this->run($directory, $environment, ['git', 'mktree']));
            $commit = trim($this->run($directory, $environment, ['git', 'commit-tree', $tree], "Initialize repository\n"));
            if ($commit !== $this->commitHash($repository)) {
                throw new RuntimeException('Unexpected initial Git commit.');
            }

            $this->run($directory, $environment, ['git', 'update-ref', 'refs/heads/main', $commit]);
            // Credentials exist only in this process environment, never in command arguments or Git config on disk.
            $this->run($directory, array_merge($environment, [
                'GIT_CONFIG_COUNT' => '2',
                'GIT_CONFIG_KEY_0' => 'http.extraHeader',
                'GIT_CONFIG_VALUE_0' => 'Authorization: Basic '.base64_encode('x:'.$token),
                'GIT_CONFIG_KEY_1' => 'http.followRedirects',
                'GIT_CONFIG_VALUE_1' => 'false',
            ]), ['git', 'push', $remote, 'refs/heads/main:refs/heads/main']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function run(string $directory, array $environment, array $command, string $input = ''): string
    {
        try {
            $result = Process::path($directory)->env($environment)->timeout(20)->input($input)->run($command);
        } catch (Throwable) {
            throw new RuntimeException('Repository Git initialization could not complete.');
        }

        if (! $result->successful()) {
            throw new RuntimeException('Repository Git initialization failed.');
        }

        return $result->output();
    }
}
