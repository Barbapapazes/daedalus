<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ImplementationRequest;
use App\Services\QueueIntegration;
use App\Support\PayloadHash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ImplementationEventController extends Controller
{
    public function __invoke(Request $request, ImplementationRequest $implementation): JsonResponse
    {
        $event = $request->validate([
            'eventId' => ['required', 'string', 'max:255'],
            'sequence' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::in(['progress', 'decision', 'execution', 'completed', 'blocked', 'failed'])],
            'payload' => ['required', 'array'],
            'createdAt' => ['required', 'date'],
        ]);
        $this->validatePayload($event, $implementation);
        $hash = PayloadHash::make($event);

        return DB::transaction(function () use ($implementation, $event, $hash): JsonResponse {
            $implementation = ImplementationRequest::whereKey($implementation->id)->lockForUpdate()->firstOrFail();
            $existing = $implementation->events()->where('event_id', $event['eventId'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'Event ID already has a different payload.');

                return response()->json(['eventId' => $existing->event_id, 'sequence' => $existing->sequence]);
            }

            abort_if(in_array($implementation->status, ['completed', 'blocked', 'failed'], true), 409, 'Execution already ended.');
            $next = ($implementation->events()->max('sequence') ?? 0) + 1;
            abort_unless($event['sequence'] === $next, 409, 'Events must arrive in sequence.');
            $implementation->events()->create([
                'event_id' => $event['eventId'],
                'sequence' => $event['sequence'],
                'type' => $event['type'],
                'payload' => $event['payload'],
                'payload_hash' => $hash,
                'occurred_at' => $event['createdAt'],
            ]);
            $implementation->update(['status' => match ($event['type']) {
                'completed', 'blocked', 'failed' => $event['type'],
                default => 'running',
            }]);

            if ($event['type'] === 'completed') {
                app(QueueIntegration::class)->handle($implementation, $event['payload']);
            }

            return response()->json(['eventId' => $event['eventId'], 'sequence' => $event['sequence']], 201);
        });
    }

    private function validatePayload(array $event, ImplementationRequest $implementation): void
    {
        $report = [
            'summary' => ['required', 'string', 'max:16000'],
            'outcome' => ['required', Rule::in(['ready', 'blocked'])],
            'acceptanceCriteria' => ['required', 'array', 'list'],
            'acceptanceCriteria.*' => ['required', 'array:criterion,status,evidence'],
            'acceptanceCriteria.*.criterion' => ['required', 'string'],
            'acceptanceCriteria.*.status' => ['required', Rule::in(['met', 'unmet', 'unverified'])],
            'acceptanceCriteria.*.evidence' => ['required', 'string', 'max:8000'],
            'blockers' => ['present', 'array', 'list'],
            'blockers.*' => ['required', 'string', 'max:8000'],
        ];
        $assignments = $implementation->assignedRepositories();
        $publicationRules = [
            'publications' => ['required', 'array', 'list', 'size:'.count($assignments)],
            'publications.*' => ['required', 'array:repository,branch,commit,changed,changedFiles'],
            'publications.*.repository' => ['required', 'string', 'distinct', Rule::in(array_column($assignments, 'name'))],
            'publications.*.branch' => ['required', Rule::in([$implementation->branch])],
            'publications.*.commit' => ['required', 'string', 'regex:/^[a-f0-9]{40}$/'],
            'publications.*.changed' => ['required', 'boolean'],
            'publications.*.changedFiles' => ['sometimes', 'array', 'list'],
            'publications.*.changedFiles.*' => [function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value)) {
                    return;
                }
                $validator = Validator::make(['file' => $value], [
                    'file' => ['required', 'array:path,status,insertions,deletions'],
                    'file.path' => ['required', 'string'],
                    'file.status' => ['required', Rule::in(['A', 'M', 'D'])],
                    'file.insertions' => ['required', 'integer', 'min:0'],
                    'file.deletions' => ['required', 'integer', 'min:0'],
                ]);
                if ($validator->fails()) {
                    $fail('Changed files must be paths or valid Git diff summaries.');
                }
            }],
        ];
        $rules = match ($event['type']) {
            'decision' => [
                'decision' => ['required', 'string', 'max:8000'],
                'justification' => ['required', 'string', 'max:8000'],
                'alternatives' => ['present', 'array', 'list', 'max:20'],
                'alternatives.*' => ['string', 'max:2000'],
                'intentReferences' => ['present', 'array', 'list', 'max:20'],
                'intentReferences.*' => ['string', 'max:2000'],
            ],
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
            'progress' => [
                'stage' => ['required', Rule::in(['preparing', 'implementing', 'publishing'])],
                'branch' => ['required', Rule::in([$implementation->branch])],
                'repository' => ['sometimes', Rule::in(array_column($assignments, 'name'))],
            ],
            'completed' => $report + ($implementation->repositories !== null ? $publicationRules : [
                'branch' => ['required', Rule::in([$implementation->branch])],
                'repository' => ['required', Rule::in([$implementation->repository['name'] ?? null])],
                'commit' => ['required', 'string', 'regex:/^[a-f0-9]{40}$/'],
            ]),
            'blocked' => $report,
            'failed' => ['summary' => ['required', 'string', 'max:16000']],
        };
        Validator::make($event['payload'], $rules)->validate();
        if ($event['type'] === 'completed' && $implementation->repositories !== null) {
            $publications = $event['payload']['publications'];
            abort_unless(in_array(true, array_column($publications, 'changed'), true), 422, 'At least one repository must have implementation changes.');
            $bases = array_column($assignments, 'baseCommit', 'name');
            foreach ($publications as $publication) {
                abort_unless($publication['changed'] === ($publication['commit'] !== $bases[$publication['repository']]),
                    422, 'Unchanged repositories must retain their assigned base commit.');
            }
        }
        if (in_array($event['type'], ['completed', 'blocked'], true)) {
            abort_unless(array_column($event['payload']['acceptanceCriteria'], 'criterion') === $implementation->intent['acceptanceCriteria'],
                422, 'Acceptance criteria must preserve the reference intent verbatim and in order.');
            if ($event['type'] === 'completed') {
                abort_unless($event['payload']['outcome'] === 'ready' && $event['payload']['blockers'] === [] &&
                    ! in_array('unmet', array_column($event['payload']['acceptanceCriteria'], 'status'), true), 422, 'Completed reports cannot contain unmet criteria or blockers.');
            } else {
                abort_unless($event['payload']['outcome'] === 'blocked' && count($event['payload']['blockers']) > 0, 422, 'Explain why implementation is blocked.');
            }
        }
    }
}
