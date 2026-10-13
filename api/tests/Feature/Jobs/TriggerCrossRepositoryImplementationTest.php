<?php

declare(strict_types=1);

use App\Jobs\TriggerImplementation;
use App\Models\ImplementationRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\ImplementationPayload;

it('retries the exact complete assignment after an unconfirmed multi-project dispatch', function (): void {
    config()->set('services.talos', [
        'url' => 'https://talos.example.com',
        'access_client_id' => 'test-id', 'access_client_secret' => 'test-secret',
    ]);
    $payload = ImplementationPayload::crossRepositoryRequest();
    $this->postJson('/internal/implementation-requests', $payload)->assertCreated();
    $implementation = ImplementationRequest::findOrFail($payload['requestId']);
    Http::fakeSequence()->pushFailedConnection()->push(['requestId' => $implementation->id, 'accepted' => true]);

    $job = new TriggerImplementation($implementation->id);
    expect(fn () => $job->handle())->toThrow(ConnectionException::class);
    expect($implementation->fresh()->dispatched_at)->toBeNull();
    $job->handle();
    $requests = Http::recorded()->map(fn (array $pair): array => $pair[0]->data());
    $assignment = [
        'requestId' => $implementation->id,
        'conversationId' => $implementation->conversation_id,
        'intent' => $implementation->intent,
        'messages' => $implementation->messages,
        'repositories' => $payload['repositories'],
    ];
    expect($requests)->toHaveCount(2)
        ->and($requests[0])->toBe($assignment)
        ->and($requests[1])->toBe($assignment)
        ->and($implementation->fresh()->dispatched_at)->not->toBeNull();
    $job->handle();
    Http::assertSentCount(2);
});

it('prevents edits to any project in a stored cross-repository assignment', function (): void {
    $payload = ImplementationPayload::crossRepositoryRequest();
    $this->postJson('/internal/implementation-requests', $payload)->assertCreated();
    $implementation = ImplementationRequest::findOrFail($payload['requestId']);
    $changed = $implementation->repositories;
    $changed[1]['baseCommit'] = str_repeat('d', 40);
    expect(fn () => $implementation->update(['repositories' => $changed]))->toThrow(LogicException::class);
    expect($implementation->fresh()->repositories)->toBe($payload['repositories']);
});
