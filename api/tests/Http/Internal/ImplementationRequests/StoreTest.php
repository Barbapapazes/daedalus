<?php

declare(strict_types=1);

use App\Jobs\TriggerImplementation;
use App\Models\ImplementationRequest;
use App\Support\PayloadHash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ImplementationPayload;

beforeEach(function (): void {
    $this->payload = ImplementationPayload::request();
});

it('persists the immutable assignment and enqueues it on the dedicated database queue', function (): void {
    $this->postJson('/internal/implementation-requests', $this->payload)
        ->assertCreated()
        ->assertExactJson([
            'requestId' => $this->payload['requestId'],
            'accepted' => true,
            'status' => 'queued',
            'branch' => 'talos/'.$this->payload['requestId'],
            'createdAt' => now()->toISOString(),
        ]);

    $implementation = ImplementationRequest::findOrFail($this->payload['requestId']);
    expect(ImplementationPayload::request($implementation))->toBe($this->payload)
        ->and($implementation->payload_hash)->toBe(PayloadHash::make($this->payload));
    $this->assertDatabaseCount('implementation_requests', 1);
    $this->assertDatabaseCount('jobs', 1);
    $this->assertDatabaseHas('jobs', ['queue' => 'implementations']);
    $job = unserialize(json_decode(DB::table('jobs')->value('payload'), true)['data']['command']);
    expect($job)->toBeInstanceOf(TriggerImplementation::class)
        ->and($job->requestId)->toBe($implementation->id)
        ->and($job->connection)->toBe('implementations');
});

it('accepts identical replays without creating another request or job', function (): void {
    $this->postJson('/internal/implementation-requests', $this->payload)->assertCreated();
    $this->payload['requestId'] = strtoupper($this->payload['requestId']);
    $this->payload['intent'] = array_reverse($this->payload['intent'], true);
    $this->postJson('/internal/implementation-requests', $this->payload)
        ->assertOk()->assertJsonPath('requestId', strtolower($this->payload['requestId']));
    $this->assertDatabaseCount('implementation_requests', 1);
    $this->assertDatabaseCount('jobs', 1);
});

it('rejects reusing an ID with a different assignment', function (string $field, mixed $value): void {
    $this->postJson('/internal/implementation-requests', $this->payload)->assertCreated();
    data_set($this->payload, $field, $value);
    $this->postJson('/internal/implementation-requests', $this->payload)->assertConflict();
    $this->assertDatabaseCount('implementation_requests', 1);
    $this->assertDatabaseCount('jobs', 1);
})->with([
    'intent' => ['intent.goal', 'A different goal'],
    'conversation' => ['conversationId', 'another-conversation'],
    'context whitespace' => ['messages.0.content', 'Keep this context verbatim.'],
    'repository' => ['repository.baseCommit', str_repeat('b', 40)],
]);

it('does not enqueue a replay after execution has started or ended', function (string $status): void {
    $implementation = ImplementationRequest::factory()->create(['status' => $status]);
    $this->postJson('/internal/implementation-requests', ImplementationPayload::request($implementation))
        ->assertOk()->assertJsonPath('status', $status);
    $this->assertDatabaseCount('jobs', 0);
})->with(['running', 'completed', 'blocked', 'failed']);

it('recovers a received request by enqueuing it once', function (): void {
    $implementation = ImplementationRequest::factory()->create(['status' => 'received']);
    $payload = ImplementationPayload::request($implementation);
    $this->postJson('/internal/implementation-requests', $payload)->assertOk()->assertJsonPath('status', 'queued');
    $this->postJson('/internal/implementation-requests', $payload)->assertOk();
    $this->assertDatabaseCount('jobs', 1);
});

it('rolls back intake when enqueueing fails', function (): void {
    Queue::shouldReceive('connection')->once()->with('implementations')->andThrow(new RuntimeException('Queue unavailable'));
    $this->postJson('/internal/implementation-requests', $this->payload)->assertStatus(500);
    $this->assertDatabaseCount('implementation_requests', 0);
    $this->assertDatabaseCount('jobs', 0);
});

it('validates intake without persisting or enqueueing invalid data', function (string $field, mixed $value): void {
    data_set($this->payload, $field, $value);
    $this->postJson('/internal/implementation-requests', $this->payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('implementation_requests', 0);
    $this->assertDatabaseCount('jobs', 0);
})->with([
    'invalid UUID' => ['requestId', 'not-a-uuid'],
    'missing conversation' => ['conversationId', null],
    'long conversation' => ['conversationId', str_repeat('a', 129)],
    'missing tool call' => ['toolCallId', ''],
    'missing task' => ['toolTaskId', ''],
    'missing goal' => ['intent.goal', ''],
    'missing scope' => ['intent.scope', ''],
    'constraints must be a list' => ['intent.constraints', ['key' => 'value']],
    'empty criteria' => ['intent.acceptanceCriteria', []],
    'invalid criterion' => ['intent.acceptanceCriteria.0', 42],
    'messages must be a list' => ['messages', ['key' => 'value']],
    'path traversal' => ['repository.name', '../repo'],
    'missing base ref' => ['repository.baseRef', ''],
    'invalid commit' => ['repository.baseCommit', 'main'],
]);

it('rejects unknown keys inside the intent and repository', function (string $field): void {
    data_set($this->payload, $field.'.unexpected', true);
    $this->postJson('/internal/implementation-requests', $this->payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['intent', 'repository']);

it('allows empty message and constraint lists', function (): void {
    $this->payload['messages'] = [];
    $this->payload['intent']['constraints'] = [];
    $this->postJson('/internal/implementation-requests', $this->payload)->assertCreated();
});

it('requires message and constraint keys even when their lists are empty', function (string $field): void {
    data_forget($this->payload, $field);
    $this->postJson('/internal/implementation-requests', $this->payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['messages', 'intent.constraints']);

it('rejects oversized context without persisting it', function (): void {
    $this->payload['messages'][0]['content'] = str_repeat('a', 2 * 1024 * 1024);
    $this->postJson('/internal/implementation-requests', $this->payload)->assertStatus(413);
    $this->assertDatabaseCount('implementation_requests', 0);
    $this->assertDatabaseCount('jobs', 0);
});
