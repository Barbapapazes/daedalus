<?php

namespace App\Services;

use App\Models\Repository;
use RuntimeException;

class RepositoryProvisioner
{
    public function __construct(private readonly CloudflareArtifacts $artifacts, private readonly RepositoryGitInitializer $git) {}

    public function provision(Repository $repository): void
    {
        $namespace = $repository->artifacts_namespace;
        $path = 'repos/'.rawurlencode($repository->artifacts_name);
        $marker = 'Daedalus onboarding '.$repository->id;
        $info = $this->artifacts->request($namespace, 'GET', $path, allowMissing: true);
        $created = $info === null;
        if ($created) {
            // A lost acknowledgement is recovered by the next GET, not another repository name.
            $info = $this->artifacts->request($namespace, 'POST', 'repos', [
                'name' => $repository->artifacts_name, 'description' => $marker, 'default_branch' => 'main',
            ]);
        }

        if (($info['description'] ?? null) !== $marker || ($info['default_branch'] ?? null) !== 'main' ||
            ! is_string($info['id'] ?? null) || ($repository->artifacts_id && $repository->artifacts_id !== $info['id'])) {
            throw new RuntimeException('Artifacts repository does not match the reserved identity.');
        }

        $repository->update(['artifacts_id' => $info['id']]);

        // Clear credentials from create or interrupted push attempts before initializing.
        $tokens = $this->artifacts->request($namespace, 'GET', $path.'/tokens', ['state' => 'active', 'per_page' => 100]);
        if ($created && $tokens === []) {
            throw new RuntimeException('Waiting for the creation token to become visible for revocation.');
        }

        for ($page = 0; $tokens !== [] && $page < 10; $page++) {
            foreach ($tokens as $token) {
                $this->artifacts->request($namespace, 'DELETE', 'tokens/'.rawurlencode($token['id']));
            }

            $tokens = $this->artifacts->request($namespace, 'GET', $path.'/tokens', ['state' => 'active', 'per_page' => 100]);
        }

        if ($tokens !== []) {
            throw new RuntimeException('Could not revoke outstanding repository tokens.');
        }

        $commit = $this->git->commitHash($repository);
        if ($repository->initial_commit && $repository->initial_commit !== $commit) {
            throw new RuntimeException('Reserved initial commit does not match the creation brief.');
        }

        $history = $this->artifacts->request($namespace, 'GET', $path.'/log', ['ref' => 'main', 'limit' => 1], allowMissing: true);
        if ($history && ($history[0]['hash'] ?? null) !== $commit) {
            throw new RuntimeException('Repository changed before initialization; refusing to overwrite it.');
        }

        if (! $history) {
            $token = $this->artifacts->request($namespace, 'POST', 'tokens', [
                'repo' => $repository->artifacts_name, 'scope' => 'write', 'ttl' => 600,
            ]);
            try {
                $this->git->push($repository, $info['remote'], $token['plaintext']);
            } finally {
                $this->artifacts->request($namespace, 'DELETE', 'tokens/'.rawurlencode($token['id']));
            }
        }

        $confirmed = $this->artifacts->request($namespace, 'GET', $path.'/log', ['ref' => 'main', 'limit' => 1]);
        if (($confirmed[0]['hash'] ?? null) !== $commit) {
            throw new RuntimeException('Could not confirm the initial commit.');
        }

        $repository->update(['status' => 'ready', 'initial_commit' => $commit, 'provisioning_error' => null]);
    }
}
