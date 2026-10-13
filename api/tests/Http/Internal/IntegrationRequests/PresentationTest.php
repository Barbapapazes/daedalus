<?php

use App\Models\ImplementationRequest;
use App\Models\IntegrationRequest;
use Tests\Support\ImplementationPayload;
use Tests\Support\IntegrationPayload;

beforeEach(function (): void {
    $this->implementation = ImplementationRequest::factory()->create();
    $this->postJson('/internal/implementation-requests/'.$this->implementation->id.'/events',
        ImplementationPayload::event($this->implementation, 'completed'))->assertCreated();
    $this->integration = IntegrationRequest::sole();
    $this->integrationUrl = '/internal/integration-requests/'.$this->integration->id.'/events';
    $this->intentUrl = '/internal/implementation-requests/'.$this->implementation->id;
});

it('exposes lightweight queued integration summaries in intent lists', function (): void {
    $this->getJson('/internal/implementation-requests')->assertOk()
        ->assertJsonPath('data.0.status', 'completed')
        ->assertJsonPath('data.0.integration.id', $this->integration->id)
        ->assertJsonPath('data.0.integration.status', 'queued')
        ->assertJsonMissingPath('data.0.integration.assignment')
        ->assertJsonMissingPath('data.0.integration.events')
        ->assertJsonMissingPath('data.0.integration.implementation_request_id');
    $this->getJson($this->intentUrl)->assertOk()
        ->assertJsonPath('integration.publications', [])
        ->assertJsonPath('integration.mergedAt', null)
        ->assertJsonPath('integration.report', null)
        ->assertJsonPath('integration.repositories.0.targetBranch', 'main');
});

it('exposes integration lifecycle without rewriting implementation status', function (string $type, string $status): void {
    $event = IntegrationPayload::event($this->integration, $type);
    $this->postJson($this->integrationUrl, $event)->assertCreated();
    $this->getJson('/internal/implementation-requests')->assertOk()
        ->assertJsonPath('data.0.status', 'completed')
        ->assertJsonPath('data.0.integration.status', $status);
    $detail = $this->getJson($this->intentUrl)->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('integration.status', $status)
        ->assertJsonPath('integration.events.0.event_id', $event['eventId'])
        ->assertJsonMissingPath('integration.events.0.payload_hash')
        ->assertJsonMissingPath('integration.assignment');
    if ($type === 'completed') {
        $detail->assertJsonPath('integration.mergedAt', $event['createdAt'])
            ->assertJsonPath('integration.report', $event['payload']['summary'])
            ->assertJsonPath('integration.publications.0.commit', $event['payload']['publications'][0]['commit']);
    } else {
        $detail->assertJsonPath('integration.mergedAt', null);
    }
})->with([['progress', 'running'], ['completed', 'integrated'], ['blocked', 'blocked'], ['failed', 'failed']]);

it('shows partial confirmed merges separately from unconfirmed publications', function (): void {
    $payload = ImplementationPayload::crossRepositoryRequest();
    $this->postJson('/internal/implementation-requests', $payload)->assertCreated();
    $implementation = ImplementationRequest::findOrFail($payload['requestId']);
    $this->postJson('/internal/implementation-requests/'.$implementation->id.'/events',
        ImplementationPayload::event($implementation, 'completed'))->assertCreated();
    $integration = IntegrationRequest::where('implementation_request_id', $implementation->id)->sole();
    $publication = IntegrationPayload::publication($integration);
    $event = IntegrationPayload::event($integration, 'progress');
    $event['payload'] = ['stage' => 'publishing', 'publications' => [$publication]];
    $url = '/internal/integration-requests/'.$integration->id.'/events';
    $this->postJson($url, $event)->assertCreated();
    $event = IntegrationPayload::event($integration, 'failed', 2);
    $event['payload']['publications'] = [$publication];
    $event['payload']['unconfirmedPublications'] = [IntegrationPayload::publication($integration, 1)];
    $this->postJson($url, $event)->assertCreated();
    $this->getJson('/internal/implementation-requests/'.$implementation->id)->assertOk()
        ->assertJsonPath('integration.status', 'failed')
        ->assertJsonCount(2, 'integration.repositories')
        ->assertJsonCount(1, 'integration.publications')
        ->assertJsonCount(1, 'integration.unconfirmedPublications')
        ->assertJsonPath('integration.publications.0.repository', $publication['repository'])
        ->assertJsonPath('integration.mergedAt', null)
        ->assertJsonCount(2, 'integration.events');
});

it('includes safe dispatch errors so queued integration failures remain visible', function (): void {
    $this->integration->update(['dispatch_error' => 'Integration dispatch could not be confirmed.']);
    $this->getJson($this->intentUrl)->assertOk()
        ->assertJsonPath('integration.dispatch_error', 'Integration dispatch could not be confirmed.');
    $this->getJson('/internal/implementation-requests?repository='.$this->implementation->repository['name'])->assertOk()
        ->assertJsonPath('data.0.integration.status', 'queued')
        ->assertJsonPath('data.0.integration.dispatch_error', 'Integration dispatch could not be confirmed.');
});
