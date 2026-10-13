<?php

declare(strict_types=1);

use App\Models\ImplementationRequest;
use App\Support\PayloadHash;
use Carbon\CarbonImmutable;
use Tests\Support\ImplementationPayload;

beforeEach(function (): void {
    $implementation = ImplementationRequest::factory()->create();
    $event = ImplementationPayload::event($implementation);
    $this->event = $implementation->events()->create([
        'event_id' => $event['eventId'], 'sequence' => 1, 'type' => $event['type'],
        'payload' => $event['payload'], 'payload_hash' => PayloadHash::make($event), 'occurred_at' => $event['createdAt'],
    ]);
});

it('prevents updating persisted events', function (): void {
    expect(fn () => $this->event->update(['type' => 'failed']))->toThrow(LogicException::class, 'append-only');
    expect($this->event->fresh()->type)->toBe('progress');
});

it('prevents deleting persisted events', function (): void {
    expect(fn () => $this->event->delete())->toThrow(LogicException::class, 'append-only');
    $this->assertDatabaseCount('implementation_events', 1);
});

it('casts event data without an updated timestamp', function (): void {
    $event = $this->event->fresh();
    expect($event->payload)->toBeArray()
        ->and($event->sequence)->toBe(1)
        ->and($event->occurred_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($event->getAttributes())->not->toHaveKey('updated_at');
});
