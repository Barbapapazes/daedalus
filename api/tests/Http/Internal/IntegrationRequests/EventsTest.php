<?php

use App\Models\ImplementationRequest;
use App\Models\IntegrationRequest;
use Tests\Support\ImplementationPayload;
use Tests\Support\IntegrationPayload;

beforeEach(function (): void {
    $implementation = ImplementationRequest::factory()->create();
    $this->postJson('/internal/implementation-requests/'.$implementation->id.'/events', ImplementationPayload::event($implementation, 'completed'))->assertCreated();
    $this->integration = IntegrationRequest::sole();
    $this->url = '/internal/integration-requests/'.$this->integration->id.'/events';
});

it('tracks integration separately and accepts identical terminal replays', function (string $type, string $status): void {
    $event = IntegrationPayload::event($this->integration, $type);
    $this->postJson($this->url, $event)->assertCreated();
    $this->postJson($this->url, $event)->assertOk();
    expect($this->integration->fresh()->status)->toBe($status);
    $this->assertDatabaseCount('integration_events', 1);
    $this->getJson('/internal/integration-requests/'.$this->integration->id)->assertOk()
        ->assertJsonPath('status', $status)
        ->assertJsonPath('events.0.payload', $event['payload']);
})->with([['completed', 'integrated'], ['blocked', 'blocked'], ['failed', 'failed'], ['progress', 'running']]);

it('rejects changed replays, out of order events and events after completion', function (): void {
    $this->postJson($this->url, IntegrationPayload::event($this->integration, sequence: 2))->assertConflict();
    $event = IntegrationPayload::event($this->integration);
    $this->postJson($this->url, $event)->assertCreated();
    $event['payload']['summary'] = 'Different summary';
    $this->postJson($this->url, $event)->assertConflict();
    $this->postJson($this->url, IntegrationPayload::event($this->integration, 'progress', 2))->assertConflict();
    $this->assertDatabaseCount('integration_events', 1);
});

it('validates the exact assigned source and publication target', function (string $field, mixed $value): void {
    $event = IntegrationPayload::event($this->integration);
    data_set($event, $field, $value);
    $this->postJson($this->url, $event)->assertUnprocessable();
    $this->assertDatabaseCount('integration_events', 0);
    expect($this->integration->fresh()->status)->toBe('queued');
})->with([
    ['payload.publications', []],
    ['payload.publications.0.sourceCommit', str_repeat('e', 40)],
    ['payload.publications.0.repository', 'unassigned'],
    ['payload.publications.0.targetBranch', 'develop'],
    ['payload.publications.0.commit', 'invalid'],
    ['payload.publications.0.commit', str_repeat('c', 40)],
    ['payload.publications.0.unexpected', true],
    ['type', 'unknown'],
    ['sequence', 0],
    ['payload.summary', ''],
]);

it('preserves confirmed partial publications on failure', function (): void {
    $publication = IntegrationPayload::publication($this->integration);
    $progress = IntegrationPayload::event($this->integration, 'progress');
    $progress['payload'] = ['stage' => 'publishing', 'publications' => [$publication]];
    $this->postJson($this->url, $progress)->assertCreated();
    $failed = IntegrationPayload::event($this->integration, 'failed', 2);
    $this->postJson($this->url, $failed)->assertUnprocessable();
    $failed['payload']['publications'] = [$publication];
    $failed['payload']['publications'][0]['commit'] = str_repeat('f', 40);
    $this->postJson($this->url, $failed)->assertConflict();
    $failed['payload']['publications'] = [array_reverse($publication, true)];
    $this->postJson($this->url, $failed)->assertCreated();
    expect($this->integration->fresh()->status)->toBe('failed');
});

it('requires receipts for every changed repository before marking integrated', function (): void {
    $payload = ImplementationPayload::crossRepositoryRequest();
    $this->postJson('/internal/implementation-requests', $payload)->assertCreated();
    $implementation = ImplementationRequest::findOrFail($payload['requestId']);
    $this->postJson('/internal/implementation-requests/'.$implementation->id.'/events', ImplementationPayload::event($implementation, 'completed'))->assertCreated();
    $integration = IntegrationRequest::where('implementation_request_id', $implementation->id)->sole();
    $url = '/internal/integration-requests/'.$integration->id.'/events';
    $event = IntegrationPayload::event($integration);
    $this->postJson($url, $event)->assertUnprocessable();
    $event['payload']['publications'][] = IntegrationPayload::publication($integration, 1);
    $this->postJson($url, $event)->assertCreated();
    expect($integration->fresh()->status)->toBe('integrated');
});
