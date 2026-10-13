<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ImplementationRequest;
use App\Support\PayloadHash;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImplementationRequest> */
class ImplementationRequestFactory extends Factory
{
    public function definition(): array
    {
        $id = fake()->uuid();
        $intent = [
            'goal' => 'Add an implementation endpoint',
            'scope' => 'API only',
            'constraints' => ['Keep shell egress disabled'],
            'acceptanceCriteria' => ['Requests are persisted', 'Replays are idempotent'],
        ];
        $messages = [['role' => 'user', 'content' => "  Keep this context verbatim.\n", 'metadata' => ['empty' => '']]];
        $repository = ['name' => 'daedalus', 'baseRef' => 'main', 'baseCommit' => str_repeat('a', 40)];
        $payload = [
            'requestId' => $id,
            'conversationId' => fake()->uuid(),
            'toolCallId' => fake()->uuid(),
            'toolTaskId' => fake()->uuid(),
            'intent' => $intent,
            'messages' => $messages,
            'repository' => $repository,
        ];

        return [
            'id' => $id,
            'conversation_id' => $payload['conversationId'],
            'tool_call_id' => $payload['toolCallId'],
            'tool_task_id' => $payload['toolTaskId'],
            'intent' => $intent,
            'messages' => $messages,
            'repository' => $repository,
            'branch' => 'talos/'.$id,
            'payload_hash' => PayloadHash::make($payload),
            'status' => 'queued',
        ];
    }
}
