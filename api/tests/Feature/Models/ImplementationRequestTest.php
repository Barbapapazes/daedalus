<?php

declare(strict_types=1);

use App\Models\ImplementationRequest;
use Carbon\CarbonImmutable;

it('prevents changes to reference context and assignment', function (string $field, mixed $value): void {
    $implementation = ImplementationRequest::factory()->create();
    $original = $implementation->fresh()->getAttributes();
    expect(fn () => $implementation->update([$field => $value]))->toThrow(LogicException::class);
    expect(ImplementationRequest::findOrFail($original['id'])->getAttributes())->toBe($original);
})->with([
    ['id', '00000000-0000-4000-8000-000000000000'],
    ['conversation_id', 'other'], ['tool_call_id', 'other'], ['tool_task_id', 'other'],
    ['intent', ['goal' => 'changed']], ['messages', []], ['payload_hash', str_repeat('b', 64)],
    ['repository', ['name' => 'other']], ['repositories', [['name' => 'other']]], ['branch', 'other'],
]);

it('allows lifecycle updates and casts the dispatch timestamp to an immutable date', function (): void {
    $implementation = ImplementationRequest::factory()->create();
    $implementation->update(['status' => 'running', 'dispatched_at' => now(), 'dispatch_error' => 'Retry']);
    expect($implementation->fresh()->status)->toBe('running')
        ->and($implementation->fresh()->dispatched_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($implementation->fresh()->dispatch_error)->toBe('Retry');
});
