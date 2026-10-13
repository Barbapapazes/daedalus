<?php

namespace App\Services;

use App\Jobs\TriggerIntegration;
use App\Models\ImplementationRequest;
use App\Models\IntegrationRequest;
use Illuminate\Support\Str;

class QueueIntegration
{
    /** Called inside completion ingestion's transaction: assignment and job commit together. */
    public function handle(ImplementationRequest $implementation, array $report): IntegrationRequest
    {
        $sources = $report['publications'] ?? [[
            'repository' => $report['repository'],
            'branch' => $report['branch'],
            'commit' => $report['commit'],
            'changed' => true,
        ]];
        $repositories = [];
        foreach ($implementation->assignedRepositories() as $repository) {
            $publication = collect($sources)->firstWhere('repository', $repository['name']);
            if ($publication['changed']) {
                $repositories[] = $repository + [
                    'sourceBranch' => $publication['branch'],
                    'sourceCommit' => $publication['commit'],
                    'targetBranch' => 'main',
                ];
            }
        }
        $id = (string) Str::uuid();
        $integration = IntegrationRequest::firstOrCreate(
            ['implementation_request_id' => $implementation->id],
            [
                'id' => $id,
                'assignment' => [
                    'requestId' => $id,
                    'implementationRequestId' => $implementation->id,
                    'intent' => $implementation->intent,
                    'repositories' => $repositories,
                    'report' => [
                        'summary' => $report['summary'],
                        'acceptanceCriteria' => $report['acceptanceCriteria'],
                    ],
                    'decisions' => $implementation->events()->where('type', 'decision')->orderBy('sequence')->pluck('payload')->all(),
                ],
                'status' => 'queued',
            ],
        );
        if ($integration->wasRecentlyCreated) {
            TriggerIntegration::dispatch($integration->id);
        }

        return $integration;
    }
}
