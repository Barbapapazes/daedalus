<?php

use App\Models\ImplementationRequest;
use Tests\Support\ImplementationPayload;

it('accepts and preserves Computer Git diff summaries in completion callbacks', function (): void {
    $payload = ImplementationPayload::crossRepositoryRequest();
    $this->postJson('/internal/implementation-requests', $payload)->assertCreated();
    $implementation = ImplementationRequest::findOrFail($payload['requestId']);
    $event = ImplementationPayload::event($implementation, 'completed');
    $event['payload']['publications'][0]['changedFiles'] = [
        ['path' => 'app.ts', 'status' => 'A', 'insertions' => 10, 'deletions' => 0],
    ];
    $this->postJson('/internal/implementation-requests/'.$implementation->id.'/events', $event)->assertCreated();
    expect($implementation->events()->sole()->payload)->toBe($event['payload']);
    $this->assertDatabaseCount('integration_requests', 1);
});

it('rejects malformed structured diff summaries', function (array $file): void {
    $payload = ImplementationPayload::crossRepositoryRequest();
    $this->postJson('/internal/implementation-requests', $payload)->assertCreated();
    $implementation = ImplementationRequest::findOrFail($payload['requestId']);
    $event = ImplementationPayload::event($implementation, 'completed');
    $event['payload']['publications'][0]['changedFiles'] = [$file];
    $this->postJson('/internal/implementation-requests/'.$implementation->id.'/events', $event)->assertUnprocessable();
    $this->assertDatabaseCount('integration_requests', 0);
})->with([
    [[]],
    [['path' => 'app.ts', 'status' => 'unknown', 'insertions' => 0, 'deletions' => 0]],
    [['path' => 'app.ts', 'status' => 'A', 'insertions' => -1, 'deletions' => 0]],
]);
