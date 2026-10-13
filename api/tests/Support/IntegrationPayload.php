<?php

namespace Tests\Support;

use App\Models\IntegrationRequest;

final class IntegrationPayload
{
    public static function publication(IntegrationRequest $integration, int $index = 0): array
    {
        $repository = $integration->assignment['repositories'][$index];

        return [
            'repository' => $repository['name'],
            'sourceCommit' => $repository['sourceCommit'],
            'baseCommit' => str_repeat('c', 40),
            'commit' => str_repeat('d', 40),
            'targetBranch' => 'main',
        ];
    }

    public static function event(IntegrationRequest $integration, string $type = 'completed', int $sequence = 1): array
    {
        return [
            'eventId' => 'integration-event-'.$sequence,
            'sequence' => $sequence,
            'type' => $type,
            'createdAt' => now()->toISOString(),
            'payload' => in_array($type, ['completed', 'blocked', 'failed'], true)
                ? ['summary' => 'Integration report', 'publications' => $type === 'completed' ? [self::publication($integration)] : []]
                : ['stage' => 'integrating'],
        ];
    }
}
