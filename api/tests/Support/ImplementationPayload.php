<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\ImplementationRequest;

final class ImplementationPayload
{
    public static function request(?ImplementationRequest $implementation = null): array
    {
        $implementation ??= ImplementationRequest::factory()->make();

        return [
            'requestId' => $implementation->id,
            'conversationId' => $implementation->conversation_id,
            'toolCallId' => $implementation->tool_call_id,
            'toolTaskId' => $implementation->tool_task_id,
            'intent' => $implementation->intent,
            'messages' => $implementation->messages,
            ...($implementation->repositories !== null
                ? ['repositories' => $implementation->repositories]
                : ['repository' => $implementation->repository]),
        ];
    }

    public static function crossRepositoryRequest(): array
    {
        $payload = self::request();
        $repository = $payload['repository'];
        unset($payload['repository']);
        $payload['repositories'] = [$repository, [
            'name' => 'prism', 'baseRef' => 'main', 'baseCommit' => str_repeat('c', 40),
            'projectContext' => 'The user interface.',
        ]];

        return $payload;
    }

    public static function event(ImplementationRequest $implementation, string $type = 'progress', int $sequence = 1): array
    {
        $report = [
            'summary' => 'Implementation report',
            'outcome' => $type === 'blocked' ? 'blocked' : 'ready',
            'acceptanceCriteria' => array_map(fn (string $criterion): array => [
                'criterion' => $criterion, 'status' => 'met', 'evidence' => 'Verified by tests',
            ], $implementation->intent['acceptanceCriteria']),
            'blockers' => $type === 'blocked' ? ['Missing repository access'] : [],
        ];

        return [
            'eventId' => 'event-'.$sequence,
            'sequence' => $sequence,
            'type' => $type,
            'createdAt' => now()->toISOString(),
            'payload' => match ($type) {
                'progress' => ['stage' => 'preparing', 'branch' => $implementation->branch],
                'decision' => ['decision' => 'Use Laravel', 'justification' => 'Existing stack', 'alternatives' => [], 'intentReferences' => []],
                'execution' => ['command' => 'php artisan test', 'backend' => 'shell', 'cwd' => '/workspace', 'exitCode' => 0, 'stdout' => '', 'stderr' => '', 'truncated' => false, 'sync' => 'complete'],
                'completed' => $report + ($implementation->repositories !== null
                    ? ['publications' => array_map(fn (array $repository): array => [
                        'repository' => $repository['name'], 'branch' => $implementation->branch,
                        'commit' => str_repeat('b', 40), 'changed' => true, 'changedFiles' => ['app.ts'],
                    ], $implementation->repositories)]
                    : ['branch' => $implementation->branch, 'repository' => $implementation->repository['name'], 'commit' => str_repeat('b', 40)]),
                'blocked' => $report,
                'failed' => ['summary' => 'Execution failed'],
            },
        ];
    }
}
