<?php

use App\Models\ImplementationRequest;
use App\Models\IntegrationRequest;
use App\Services\QueueIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ImplementationPayload;
use Tests\Support\IntegrationPayload;

beforeEach(function (): void {
    $this->implementation = ImplementationRequest::factory()->create();
    $this->completion = ImplementationPayload::event($this->implementation, 'completed');
    $this->completionUrl = '/internal/implementation-requests/'.$this->implementation->id.'/events';
});

it('atomically queues integration once after confirmed implementation completion', function (): void {
    $this->postJson($this->completionUrl, $this->completion)->assertCreated();
    $this->postJson($this->completionUrl, $this->completion)->assertOk();
    $integration = IntegrationRequest::sole();
    $assignment = $integration->assignment;
    expect($assignment['intent'])->toBe($this->implementation->intent)
        ->and($assignment['requestId'])->toBe($integration->id)
        ->and($assignment['implementationRequestId'])->toBe($this->implementation->id)
        ->and($assignment['repositories'][0])->toBe($this->implementation->repository + [
            'sourceBranch' => $this->implementation->branch,
            'sourceCommit' => $this->completion['payload']['commit'],
            'targetBranch' => 'main',
        ])
        ->and($integration->status)->toBe('queued')
        ->and($this->implementation->fresh()->status)->toBe('completed');
    $this->assertDatabaseCount('jobs', 1);
    $this->getJson('/internal/implementation-requests/'.$this->implementation->id)
        ->assertJsonPath('integration.id', $integration->id)
        ->assertJsonPath('integration.status', 'queued');
});

it('rolls back completion when integration enqueue fails', function (): void {
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('Queue unavailable'));
    $this->postJson($this->completionUrl, $this->completion)->assertStatus(500);
    $this->assertDatabaseCount('implementation_events', 0);
    $this->assertDatabaseCount('integration_requests', 0);
    expect($this->implementation->fresh()->status)->toBe('queued');
});

it('does not enqueue integration for progress or unsuccessful implementations', function (string $type): void {
    $this->postJson($this->completionUrl, ImplementationPayload::event($this->implementation, $type))->assertCreated();
    $this->assertDatabaseCount('integration_requests', 0);
    $this->assertDatabaseCount('jobs', 0);
})->with(['progress', 'blocked', 'failed']);

it('assigns only changed repositories while preserving implementation decisions', function (): void {
    $payload = ImplementationPayload::crossRepositoryRequest();
    $this->postJson('/internal/implementation-requests', $payload)->assertCreated();
    $implementation = ImplementationRequest::findOrFail($payload['requestId']);
    $url = '/internal/implementation-requests/'.$implementation->id.'/events';
    $decision = ImplementationPayload::event($implementation, 'decision');
    $this->postJson($url, $decision)->assertCreated();
    $completion = ImplementationPayload::event($implementation, 'completed', 2);
    $completion['payload']['publications'][1]['changed'] = false;
    $completion['payload']['publications'][1]['commit'] = $payload['repositories'][1]['baseCommit'];
    $this->postJson($url, $completion)->assertCreated();
    $assignment = IntegrationRequest::sole()->assignment;
    expect($assignment['repositories'])->toHaveCount(1)
        ->and($assignment['repositories'][0]['name'])->toBe($payload['repositories'][0]['name'])
        ->and($assignment['decisions'])->toBe([$decision['payload']]);
});

it('keeps assignments immutable and integration events append only', function (): void {
    $this->postJson($this->completionUrl, $this->completion)->assertCreated();
    $integration = IntegrationRequest::sole();
    expect(fn () => $integration->update(['assignment' => []]))->toThrow(LogicException::class);
    $integration = $integration->fresh();
    $this->postJson('/internal/integration-requests/'.$integration->id.'/events', IntegrationPayload::event($integration))->assertCreated();
    $event = $integration->events()->sole();
    expect(fn () => $event->update(['payload' => []]))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});

it('commits the integration assignment and database job in the same transaction', function (): void {
    DB::beginTransaction();
    app(QueueIntegration::class)->handle($this->implementation, $this->completion['payload']);
    $this->assertDatabaseCount('integration_requests', 1);
    $this->assertDatabaseCount('jobs', 1);
    DB::rollBack();
    $this->assertDatabaseCount('integration_requests', 0);
    $this->assertDatabaseCount('jobs', 0);
});
