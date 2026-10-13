<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\IntegrationRequest;
use App\Support\PayloadHash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    public function show(IntegrationRequest $integration): JsonResponse
    {
        return response()->json([
            'requestId' => $integration->id,
            'implementationRequestId' => $integration->implementation_request_id,
            'assignment' => $integration->assignment,
            'status' => $integration->status,
            'dispatchError' => $integration->dispatch_error,
            'events' => $integration->events()->orderBy('sequence')->get(),
        ]);
    }

    public function event(Request $request, IntegrationRequest $integration): JsonResponse
    {
        $event = $request->validate([
            'eventId' => ['required', 'string', 'max:255'],
            'sequence' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::in(['progress', 'decision', 'execution', 'completed', 'blocked', 'failed'])],
            'payload' => ['required', 'array'],
            'createdAt' => ['required', 'date'],
        ]);
        $terminal = in_array($event['type'], ['completed', 'blocked', 'failed'], true);
        $repositories = array_column($integration->assignment['repositories'], null, 'name');
        $rules = $terminal ? [
            'summary' => ['required', 'string', 'max:16000'],
            'publications' => ['present', 'array', 'list'],
        ] : match ($event['type']) {
            'progress' => ['stage' => ['required', Rule::in(['preparing', 'integrating', 'publishing'])]],
            'decision' => ['decision' => ['required', 'string', 'max:8000'], 'justification' => ['required', 'string', 'max:8000']],
            'execution' => [
                'command' => ['required', 'string', 'max:16000'],
                'backend' => ['required', Rule::in(['shell', 'sandbox'])],
                'cwd' => ['required', 'string'],
                'exitCode' => ['required', 'integer'],
                'stdout' => ['present', 'string', 'max:65536'],
                'stderr' => ['present', 'string', 'max:65536'],
                'truncated' => ['required', 'boolean'],
                'sync' => ['required', Rule::in(['complete', 'pending'])],
            ],
        };
        if ($event['type'] === 'completed') {
            $rules['publications'][] = 'size:'.count($repositories);
        }
        $rules += [
            'publications' => ['sometimes', 'array', 'list'],
            'publications.*' => ['required', 'array:repository,sourceCommit,baseCommit,commit,targetBranch'],
            'publications.*.repository' => ['required', 'distinct', Rule::in(array_keys($repositories))],
            'publications.*.sourceCommit' => ['required', 'regex:/^[a-f0-9]{40}$/'],
            'publications.*.baseCommit' => ['required', 'regex:/^[a-f0-9]{40}$/'],
            'publications.*.commit' => ['required', 'regex:/^[a-f0-9]{40}$/'],
            'publications.*.targetBranch' => ['required', Rule::in(['main'])],
        ];
        Validator::make($event['payload'], $rules)->validate();
        foreach ($event['payload']['publications'] ?? [] as $publication) {
            abort_unless($publication['sourceCommit'] === $repositories[$publication['repository']]['sourceCommit'], 422, 'Publication must identify the assigned implementation commit.');
            abort_if($publication['commit'] === $publication['baseCommit'], 422, 'Integration must create a squash commit.');
        }
        $hash = PayloadHash::make($event);

        return DB::transaction(function () use ($integration, $event, $hash): JsonResponse {
            $integration = IntegrationRequest::whereKey($integration->id)->lockForUpdate()->firstOrFail();
            $existing = $integration->events()->where('event_id', $event['eventId'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'Event ID already has a different payload.');

                return response()->json(['eventId' => $existing->event_id, 'sequence' => $existing->sequence]);
            }
            abort_if(in_array($integration->status, ['integrated', 'blocked', 'failed'], true), 409, 'Integration already ended.');
            abort_unless($event['sequence'] === ($integration->events()->max('sequence') ?? 0) + 1, 409, 'Events must arrive in sequence.');
            // Once publication is confirmed, later reports may neither omit nor rewrite it.
            $confirmed = $integration->events()->orderBy('sequence')->get()->flatMap(fn ($stored) => $stored->payload['publications'] ?? []);
            $incoming = collect($event['payload']['publications'] ?? [])->keyBy('repository');
            foreach ($confirmed as $publication) {
                if (isset($incoming[$publication['repository']])) {
                    abort_unless(PayloadHash::make($incoming[$publication['repository']]) === PayloadHash::make($publication), 409, 'Confirmed publication cannot change.');
                } elseif (in_array($event['type'], ['completed', 'blocked', 'failed'], true)) {
                    abort(422, 'Terminal reports must preserve partial publications.');
                }
            }
            $integration->events()->create([
                'event_id' => $event['eventId'], 'sequence' => $event['sequence'],
                'type' => $event['type'], 'payload' => $event['payload'],
                'payload_hash' => $hash, 'occurred_at' => $event['createdAt'],
            ]);
            $integration->update(['status' => match ($event['type']) {
                'completed' => 'integrated',
                'blocked', 'failed' => $event['type'],
                default => 'running',
            }]);

            return response()->json(['eventId' => $event['eventId'], 'sequence' => $event['sequence']], 201);
        });
    }
}
