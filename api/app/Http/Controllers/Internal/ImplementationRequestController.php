<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Jobs\TriggerImplementation;
use App\Models\ImplementationRequest;
use App\Support\PayloadHash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImplementationRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'repository' => ['sometimes', 'required', 'string', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/'],
        ]);

        return response()->json(ImplementationRequest::query()
            ->select(['id', 'intent', 'status', 'repository', 'repositories', 'updated_at'])
            ->with('integration:id,implementation_request_id,status,dispatch_error,updated_at')
            ->when(isset($input['repository']), fn ($query) => $query->where(function ($query) use ($input): void {
                // Assignments contain at most eight repositories. Compare names
                // directly so partial JSON-object containment also works on SQLite.
                $query->where(function ($query) use ($input): void {
                    for ($index = 0; $index < 8; $index++) {
                        $query->orWhere("repositories->[{$index}]->name", $input['repository']);
                    }
                })->orWhere(function ($query) use ($input): void {
                    $query->whereNull('repositories')->where('repository->name', $input['repository']);
                });
            }))
            ->orderByDesc('updated_at')->orderBy('id')
            ->paginate($input['limit'] ?? 6)
            ->through(fn (ImplementationRequest $implementation): array => [
                'id' => $implementation->id,
                'intent' => $implementation->intent,
                'status' => $implementation->status,
                'updated_at' => $implementation->updated_at,
                'repositories' => array_map(fn (array $repository): array => [
                    'name' => $repository['name'],
                    'baseRef' => $repository['baseRef'],
                    'baseCommit' => $repository['baseCommit'],
                ], $implementation->assignedRepositories()),
                'integration' => $implementation->integration?->summary(),
            ]));
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'requestId' => ['required', 'uuid'],
            'conversationId' => ['required', 'string', 'max:128'],
            'toolCallId' => ['required', 'string', 'max:255'],
            'toolTaskId' => ['required', 'string', 'max:255'],
            'intent' => ['required', 'array:goal,scope,constraints,acceptanceCriteria'],
            'intent.goal' => ['required', 'string'],
            'intent.scope' => ['required', 'string'],
            'intent.constraints' => ['present', 'array', 'list'],
            'intent.constraints.*' => ['required', 'string'],
            'intent.acceptanceCriteria' => ['required', 'array', 'list', 'min:1'],
            'intent.acceptanceCriteria.*' => ['required', 'string'],
            'messages' => ['present', 'array', 'list'],
            'repository' => ['sometimes', 'prohibits:repositories', 'array:name,baseRef,baseCommit,projectContext'],
            'repositories' => ['sometimes', 'prohibits:repository', 'array', 'list', 'min:1', 'max:8'],
            'repositories.*' => ['required', 'array:name,baseRef,baseCommit,projectContext'],
            'repositories.*.name' => ['required', 'string', 'distinct:ignore_case', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/'],
            'repositories.*.baseRef' => ['required', 'string', 'max:256'],
            'repositories.*.baseCommit' => ['required', 'string', 'regex:/^[a-f0-9]{40}$/'],
            'repositories.*.projectContext' => ['sometimes', 'string', 'max:100000'],
            'repository.name' => ['required_with:repository', 'string', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/'],
            'repository.baseRef' => ['required_with:repository', 'string', 'max:256'],
            'repository.baseCommit' => ['required_with:repository', 'string', 'regex:/^[a-f0-9]{40}$/'],
            'repository.projectContext' => ['sometimes', 'string', 'max:100000'],
        ]);
        abort_if(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 2 * 1024 * 1024 - 4096, 413, 'Implementation context is too large.');
        $payload['requestId'] = strtolower($payload['requestId']);
        $hash = PayloadHash::make($payload);

        return DB::transaction(function () use ($payload, $hash): JsonResponse {
            if (! isset($payload['repository']) && ! isset($payload['repositories'])) {
                // Old durable Argos calls must replay their original body. Only
                // acknowledge an existing matching legacy request, never infer
                // its repository or dispatch it without a pinned assignment.
                $legacy = ImplementationRequest::whereKey($payload['requestId'])->lockForUpdate()->first();
                abort_unless($legacy, 422, 'New implementation requests require a repository assignment.');
                abort_unless(hash_equals($legacy->payload_hash, $hash), 409, 'Request ID already has a different payload.');

                return response()->json(['requestId' => $legacy->id, 'accepted' => true, 'status' => $legacy->status]);
            }

            $implementation = ImplementationRequest::firstOrCreate(
                ['id' => $payload['requestId']],
                [
                    'conversation_id' => $payload['conversationId'],
                    'tool_call_id' => $payload['toolCallId'],
                    'tool_task_id' => $payload['toolTaskId'],
                    'intent' => $payload['intent'],
                    'messages' => $payload['messages'],
                    'repository' => $payload['repository'] ?? null,
                    'repositories' => $payload['repositories'] ?? null,
                    'branch' => 'talos/'.$payload['requestId'],
                    'payload_hash' => $hash,
                    'status' => 'received',
                ],
            );
            $created = $implementation->wasRecentlyCreated;
            $implementation = ImplementationRequest::whereKey($implementation->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($implementation->payload_hash, $hash), 409,
                'This request ID has already been used with a different payload.');
            if ($implementation->status === 'received') {
                // Dedicated database queue shares this transaction. Neither a
                // crash nor a replay can persist intake without enqueuing work.
                TriggerImplementation::dispatch($implementation->id);
                $implementation->update(['status' => 'queued']);
            }

            return response()->json([
                'requestId' => $implementation->id,
                'accepted' => true,
                'status' => $implementation->status,
                'branch' => $implementation->branch,
                'createdAt' => $implementation->created_at->toISOString(),
            ], $created ? 201 : 200);
        });
    }

    public function show(ImplementationRequest $implementation): JsonResponse
    {
        return response()->json([
            'requestId' => $implementation->id,
            'conversationId' => $implementation->conversation_id,
            'status' => $implementation->status,
            'referenceIntent' => $implementation->intent,
            'repository' => $implementation->repository,
            'repositories' => $implementation->assignedRepositories(),
            'branch' => $implementation->branch,
            'dispatchError' => $implementation->dispatch_error,
            'integration' => $implementation->integration?->details(),
            'events' => $implementation->events()->orderBy('sequence')->get([
                'event_id', 'sequence', 'type', 'payload', 'occurred_at',
            ]),
        ]);
    }
}
