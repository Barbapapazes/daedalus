<?php

declare(strict_types=1);

use App\Models\ImplementationRequest;
use Tests\Support\ImplementationPayload;

beforeEach(function (): void {
    $this->implementation = ImplementationRequest::factory()->create();
    $this->url = '/internal/implementation-requests/'.$this->implementation->id.'/events';
});

it('appends valid events and updates the execution status', function (string $type, string $status): void {
    $event = ImplementationPayload::event($this->implementation, $type);
    $this->postJson($this->url, $event)->assertCreated()
        ->assertExactJson(['eventId' => $event['eventId'], 'sequence' => 1]);
    $stored = $this->implementation->events()->sole();
    expect($stored->payload)->toBe($event['payload'])
        ->and($stored->occurred_at->toISOString())->toBe($event['createdAt'])
        ->and($this->implementation->fresh()->status)->toBe($status);
})->with([
    ['progress', 'running'], ['decision', 'running'], ['execution', 'running'],
    ['completed', 'completed'], ['blocked', 'blocked'], ['failed', 'failed'],
]);

it('accepts identical event replays even after execution ends', function (): void {
    $event = ImplementationPayload::event($this->implementation, 'completed');
    $this->postJson($this->url, $event)->assertCreated();
    $event['payload'] = array_reverse($event['payload'], true);
    $this->postJson($this->url, $event)->assertOk()
        ->assertExactJson(['eventId' => $event['eventId'], 'sequence' => 1]);
    $this->assertDatabaseCount('implementation_events', 1);
});

it('rejects a replay with a changed payload', function (): void {
    $event = ImplementationPayload::event($this->implementation);
    $this->postJson($this->url, $event)->assertCreated();
    $event['payload']['stage'] = 'implementing';
    $this->postJson($this->url, $event)->assertConflict();
    $this->assertDatabaseCount('implementation_events', 1);
});

it('requires contiguous event sequences', function (): void {
    $this->postJson($this->url, ImplementationPayload::event($this->implementation, sequence: 2))->assertConflict();
    $this->assertDatabaseCount('implementation_events', 0);
    expect($this->implementation->fresh()->status)->toBe('queued');

    $this->postJson($this->url, ImplementationPayload::event($this->implementation))->assertCreated();
    $event = ImplementationPayload::event($this->implementation);
    $event['eventId'] = 'different-event';
    $this->postJson($this->url, $event)->assertConflict();
    $this->postJson($this->url, ImplementationPayload::event($this->implementation, sequence: 2))->assertCreated();
    $this->assertDatabaseCount('implementation_events', 2);
});

it('rejects new events after a terminal event', function (string $type): void {
    $this->postJson($this->url, ImplementationPayload::event($this->implementation, $type))->assertCreated();
    $this->postJson($this->url, ImplementationPayload::event($this->implementation, sequence: 2))->assertConflict();
    $this->assertDatabaseCount('implementation_events', 1);
    expect($this->implementation->fresh()->status)->toBe($type);
})->with(['completed', 'blocked', 'failed']);

it('isolates event IDs and sequences between requests', function (): void {
    $other = ImplementationRequest::factory()->create();
    $this->postJson($this->url, ImplementationPayload::event($this->implementation))->assertCreated();
    $this->postJson('/internal/implementation-requests/'.$other->id.'/events', ImplementationPayload::event($other))->assertCreated();
    expect($this->implementation->events()->count())->toBe(1)
        ->and($other->events()->count())->toBe(1);
});

it('validates the event envelope', function (string $field, mixed $value): void {
    $event = ImplementationPayload::event($this->implementation);
    data_set($event, $field, $value);
    $this->postJson($this->url, $event)->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('implementation_events', 0);
    expect($this->implementation->fresh()->status)->toBe('queued');
})->with([
    ['eventId', ''], ['sequence', 0], ['sequence', 1.5], ['type', 'unknown'],
    ['payload', 'invalid'], ['createdAt', 'not-a-date'],
]);

it('validates type-specific payloads before appending', function (string $type, string $field, mixed $value): void {
    $event = ImplementationPayload::event($this->implementation, $type);
    data_set($event['payload'], $field, $value);
    $this->postJson($this->url, $event)->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('implementation_events', 0);
    expect($this->implementation->fresh()->status)->toBe('queued');
})->with([
    ['progress', 'branch', 'another-branch'],
    ['progress', 'stage', 'unknown'],
    ['decision', 'justification', ''],
    ['decision', 'alternatives', ['key' => 'value']],
    ['execution', 'backend', 'unknown'],
    ['execution', 'exitCode', 'invalid'],
    ['execution', 'stdout', null],
    ['execution', 'sync', 'unknown'],
    ['completed', 'repository', 'other-repository'],
    ['completed', 'commit', 'not-a-commit'],
    ['completed', 'branch', 'another-branch'],
    ['failed', 'summary', ''],
]);

it('preserves reference acceptance criteria verbatim and in order', function (string $type, string $mutation): void {
    $event = ImplementationPayload::event($this->implementation, $type);
    $criteria = &$event['payload']['acceptanceCriteria'];
    match ($mutation) {
        'reordered' => $criteria = array_reverse($criteria),
        'changed' => $criteria[0]['criterion'] = 'Different criterion',
        'missing' => array_pop($criteria),
    };
    $this->postJson($this->url, $event)->assertUnprocessable();
    $this->assertDatabaseCount('implementation_events', 0);
})->with(['completed', 'blocked'])->with(['reordered', 'changed', 'missing']);

it('rejects inconsistent terminal reports', function (string $type, string $field, mixed $value): void {
    $event = ImplementationPayload::event($this->implementation, $type);
    data_set($event['payload'], $field, $value);
    $this->postJson($this->url, $event)->assertUnprocessable();
    $this->assertDatabaseCount('implementation_events', 0);
})->with([
    ['completed', 'outcome', 'blocked'],
    ['completed', 'blockers', ['A blocker']],
    ['completed', 'acceptanceCriteria.0.status', 'unmet'],
    ['blocked', 'outcome', 'ready'],
    ['blocked', 'blockers', []],
]);

it('allows completed reports to explicitly mark unverified criteria', function (): void {
    $event = ImplementationPayload::event($this->implementation, 'completed');
    $event['payload']['acceptanceCriteria'][0]['status'] = 'unverified';
    $this->postJson($this->url, $event)->assertCreated();
});

it('returns not found for an unknown request', function (): void {
    $this->postJson('/internal/implementation-requests/00000000-0000-4000-8000-000000000000/events',
        ImplementationPayload::event($this->implementation))->assertNotFound();
});
