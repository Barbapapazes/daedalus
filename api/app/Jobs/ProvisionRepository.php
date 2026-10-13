<?php

namespace App\Jobs;

use App\Models\Repository;
use App\Services\RepositoryProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ProvisionRepository implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $timeout = 180;

    public function __construct(public string $repositoryId)
    {
        // The database queue shares the repository intake transaction.
        $this->onConnection('repositories')->onQueue('repositories');
    }

    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('repository:'.$this->repositoryId))->releaseAfter(10)->expireAfter(210)];
    }

    public function handle(RepositoryProvisioner $provisioner): void
    {
        $repository = Repository::findOrFail($this->repositoryId);
        if ($repository->status === 'ready') {
            return;
        }

        $repository->update(['status' => 'provisioning', 'provisioning_error' => null]);
        $provisioner->provision($repository);
    }

    public function failed(?Throwable $exception): void
    {
        Repository::whereKey($this->repositoryId)->where('status', '!=', 'ready')->update([
            'status' => 'failed',
            'provisioning_error' => 'Repository provisioning could not be confirmed. Retry the same repository and original brief.',
        ]);
    }
}
