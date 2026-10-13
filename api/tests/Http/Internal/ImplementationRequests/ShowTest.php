<?php

declare(strict_types=1);

use App\Models\ImplementationRequest;
use Tests\Support\ImplementationPayload;

it('returns reference intent, assignment and events in sequence without internal hashes', function (): void {
    $implementation = ImplementationRequest::factory()->create();
    $other = ImplementationRequest::factory()->create();
    foreach ([$implementation, $other] as $request) {
        $this->postJson('/internal/implementation-requests/'.$request->id.'/events', ImplementationPayload::event($request))->assertCreated();
    }

    $this->postJson('/internal/implementation-requests/'.$implementation->id.'/events', ImplementationPayload::event($implementation, 'decision', 2))->assertCreated();
    $response = $this->getJson('/internal/implementation-requests/'.$implementation->id)->assertOk()
        ->assertJsonPath('requestId', $implementation->id)
        ->assertJsonPath('conversationId', $implementation->conversation_id)
        ->assertJsonPath('status', 'running')
        ->assertJsonPath('referenceIntent', $implementation->intent)
        ->assertJsonPath('repository', $implementation->repository)
        ->assertJsonPath('branch', $implementation->branch)
        ->assertJsonPath('dispatchError', null)
        ->assertJsonCount(2, 'events')
        ->assertJsonPath('events.0.sequence', 1)
        ->assertJsonPath('events.1.sequence', 2)
        ->assertJsonMissingPath('payload_hash')
        ->assertJsonMissingPath('messages')
        ->assertJsonMissingPath('events.0.payload_hash');
    expect(array_keys($response->json('events.0')))->toBe(['event_id', 'sequence', 'type', 'payload', 'occurred_at']);
});

it('renders internal route errors as JSON without an Accept header', function (): void {
    $this->get('/internal/implementation-requests/00000000-0000-4000-8000-000000000000')
        ->assertNotFound()->assertHeader('Content-Type', 'application/json');
    $this->post('/internal/implementation-requests', [])
        ->assertUnprocessable()->assertHeader('Content-Type', 'application/json')->assertJsonValidationErrors('requestId');
});
