<?php

declare(strict_types=1);

use App\Jobs\ProvisionRepository;
use App\Services\RepositoryProvisioner;
use Tests\Support\RepositoryPayload;

it('delegates provisioning to the backend service', function (): void {
    $repository = RepositoryPayload::create(['status' => 'failed', 'provisioning_error' => 'Earlier failure']);
    $provisioner = $this->mock(RepositoryProvisioner::class);
    $provisioner->shouldReceive('provision')->once()->withArgs(fn ($record): bool => $record->id === $repository->id && $record->status === 'provisioning' && $record->provisioning_error === null);
    (new ProvisionRepository($repository->id))->handle($provisioner);
});

it('skips completed jobs and never downgrades a ready repository on failure', function (): void {
    $repository = RepositoryPayload::create(['status' => 'ready']);
    $provisioner = $this->mock(RepositoryProvisioner::class);
    $provisioner->shouldNotReceive('provision');

    $job = new ProvisionRepository($repository->id);
    $job->handle($provisioner);
    $job->failed(new RuntimeException('secret'));
    expect($repository->fresh()->status)->toBe('ready')->and($repository->fresh()->provisioning_error)->toBeNull();
});

it('leaves transient failures pending for automatic retry', function (): void {
    $repository = RepositoryPayload::create();
    $provisioner = $this->mock(RepositoryProvisioner::class);
    $provisioner->shouldReceive('provision')->once()->andThrow(new RuntimeException('API unavailable'));
    expect(fn () => (new ProvisionRepository($repository->id))->handle($provisioner))->toThrow(RuntimeException::class);
    expect($repository->fresh()->status)->toBe('provisioning');
});

it('records a safe error only after retries are exhausted', function (): void {
    $repository = RepositoryPayload::create();
    (new ProvisionRepository($repository->id))->failed(new RuntimeException('secret token'));
    expect($repository->fresh()->status)->toBe('failed')
        ->and($repository->fresh()->provisioning_error)->toContain('Retry the same repository')->not->toContain('secret token');
});

it('uses bounded retries and an overlap lock on the dedicated database queue', function (): void {
    $job = new ProvisionRepository('repository-id');
    expect($job->connection)->toBe('repositories')->and($job->queue)->toBe('repositories')
        ->and($job->tries)->toBe(10)->and($job->timeout)->toBeLessThan(config('queue.connections.repositories.retry_after'))
        ->and($job->backoff())->toBe([10, 30, 60, 120, 300])->and($job->middleware())->toHaveCount(1);
});
