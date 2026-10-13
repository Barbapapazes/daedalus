<?php

declare(strict_types=1);

use App\Models\ImplementationRequest;
use App\Support\PayloadHash;
use Tests\Support\ImplementationPayload;

beforeEach(function (): void {
    $this->payload = ImplementationPayload::crossRepositoryRequest();
});

it('preserves historical unassigned replays without inventing repositories or dispatching work', function (): void {
    $implementation = ImplementationRequest::factory()->make(['repository' => null]);
    $payload = ImplementationPayload::request($implementation);
    unset($payload['repository']);
    $implementation->payload_hash = PayloadHash::make($payload);
    $implementation->save();

    $this->postJson('/internal/implementation-requests', $payload)->assertOk();
    expect($implementation->fresh()->assignedRepositories())->toBe([]);
    $this->assertDatabaseCount('jobs', 0);

    $payload['requestId'] = fake()->uuid();
    $this->postJson('/internal/implementation-requests', $payload)->assertUnprocessable();
    $this->assertDatabaseCount('implementation_requests', 1);
});

it('stores and replays all approved repository assignments as one intent and one job', function (): void {
    $this->postJson('/internal/implementation-requests', $this->payload)->assertCreated();
    $this->postJson('/internal/implementation-requests', $this->payload)->assertOk();

    $implementation = ImplementationRequest::findOrFail($this->payload['requestId']);
    expect($implementation->repository)->toBeNull()
        ->and($implementation->assignedRepositories())->toBe($this->payload['repositories'])
        ->and(ImplementationPayload::request($implementation))->toBe($this->payload)
        ->and($implementation->payload_hash)->toBe(PayloadHash::make($this->payload));
    $this->assertDatabaseCount('implementation_requests', 1);
    $this->assertDatabaseCount('jobs', 1);

    $this->getJson('/internal/implementation-requests/'.$implementation->id)->assertOk()
        ->assertJsonPath('repositories', $this->payload['repositories'])
        ->assertJsonPath('referenceIntent', $this->payload['intent']);
    $this->getJson('/internal/implementation-requests')->assertOk()
        ->assertJsonPath('data.0.repositories.0.name', 'daedalus')
        ->assertJsonPath('data.0.repositories.1.name', 'prism')
        ->assertJsonMissingPath('data.0.repositories.1.projectContext');
});

it('rejects changes to any pinned repository on replay', function (string $mutation): void {
    $this->postJson('/internal/implementation-requests', $this->payload)->assertCreated();
    match ($mutation) {
        'base' => $this->payload['repositories'][1]['baseCommit'] = str_repeat('d', 40),
        'scope' => $this->payload['repositories'][1]['projectContext'] = 'Different brief',
        'removed' => array_pop($this->payload['repositories']),
        'reordered' => $this->payload['repositories'] = array_reverse($this->payload['repositories']),
        'single format' => $this->payload = array_diff_key($this->payload, ['repositories' => true]) + ['repository' => $this->payload['repositories'][0]],
    };
    $this->postJson('/internal/implementation-requests', $this->payload)->assertConflict();
    $this->assertDatabaseCount('jobs', 1);
})->with(['base', 'scope', 'removed', 'reordered', 'single format']);

it('validates every repository and rejects ambiguous or duplicate selections', function (string $field, mixed $value): void {
    data_set($this->payload, $field, $value);
    $this->postJson('/internal/implementation-requests', $this->payload)->assertUnprocessable();
    $this->assertDatabaseCount('implementation_requests', 0);
    $this->assertDatabaseCount('jobs', 0);
})->with([
    ['repositories', []],
    ['repositories', ['key' => ['name' => 'project']]],
    ['repositories.1.name', 'daedalus'],
    ['repositories.1.name', 'DAEDALUS'],
    ['repositories.1.name', '../project'],
    ['repositories.1.baseCommit', 'main'],
    ['repositories.1.baseRef', ''],
    ['repositories.1.unexpected', true],
    ['repository', ['name' => 'other', 'baseRef' => 'main', 'baseCommit' => str_repeat('a', 40)]],
]);

it('bounds cross-repository work to eight projects', function (): void {
    $this->payload['repositories'] = array_map(fn (int $index): array => [
        'name' => 'project-'.$index, 'baseRef' => 'main', 'baseCommit' => str_repeat('a', 40),
    ], range(1, 9));
    $this->postJson('/internal/implementation-requests', $this->payload)->assertUnprocessable()
        ->assertJsonValidationErrors('repositories');
    array_pop($this->payload['repositories']);
    $this->postJson('/internal/implementation-requests', $this->payload)->assertCreated();
});

it('accepts a one-project assignment using the new format', function (): void {
    array_pop($this->payload['repositories']);
    $this->postJson('/internal/implementation-requests', $this->payload)->assertCreated();
    $implementation = ImplementationRequest::findOrFail($this->payload['requestId']);
    $this->postJson('/internal/implementation-requests/'.$implementation->id.'/events',
        ImplementationPayload::event($implementation, 'completed'))->assertCreated();
});

it('completes one intent with publication receipts for every assigned project', function (): void {
    $this->postJson('/internal/implementation-requests', $this->payload)->assertCreated();
    $implementation = ImplementationRequest::findOrFail($this->payload['requestId']);
    $event = ImplementationPayload::event($implementation, 'completed');
    $url = '/internal/implementation-requests/'.$implementation->id.'/events';
    $this->postJson($url, $event)->assertCreated();
    $this->postJson($url, $event)->assertOk();
    expect($implementation->fresh()->status)->toBe('completed');
    $this->assertDatabaseCount('implementation_events', 1);
});

it('allows an inspected project to remain unchanged at its pinned base', function (): void {
    $this->postJson('/internal/implementation-requests', $this->payload)->assertCreated();
    $implementation = ImplementationRequest::findOrFail($this->payload['requestId']);
    $event = ImplementationPayload::event($implementation, 'completed');
    $event['payload']['publications'][1]['changed'] = false;
    $event['payload']['publications'][1]['commit'] = $this->payload['repositories'][1]['baseCommit'];
    $event['payload']['publications'][1]['changedFiles'] = [];
    $this->postJson('/internal/implementation-requests/'.$implementation->id.'/events', $event)->assertCreated();
});

it('rejects incomplete, foreign or inconsistent publication receipts', function (string $mutation): void {
    $this->postJson('/internal/implementation-requests', $this->payload)->assertCreated();
    $implementation = ImplementationRequest::findOrFail($this->payload['requestId']);
    $event = ImplementationPayload::event($implementation, 'completed');
    $receipts = &$event['payload']['publications'];
    match ($mutation) {
        'missing' => array_pop($receipts),
        'foreign' => $receipts[1]['repository'] = 'other',
        'duplicate' => $receipts[1]['repository'] = $receipts[0]['repository'],
        'branch' => $receipts[1]['branch'] = 'main',
        'commit' => $receipts[1]['commit'] = 'not-a-commit',
        'unchanged with new commit' => $receipts[1]['changed'] = false,
        'changed with base commit' => $receipts[1]['commit'] = $this->payload['repositories'][1]['baseCommit'],
        'all unchanged' => $receipts = array_map(fn (array $repository): array => [
            'repository' => $repository['name'], 'branch' => $implementation->branch,
            'commit' => $repository['baseCommit'], 'changed' => false,
        ], $this->payload['repositories']),
        'legacy receipt' => $event['payload'] = array_diff_key($event['payload'], ['publications' => true]) + [
            'repository' => 'daedalus', 'branch' => $implementation->branch, 'commit' => str_repeat('b', 40),
        ],
    };
    $this->postJson('/internal/implementation-requests/'.$implementation->id.'/events', $event)->assertUnprocessable();
    expect($implementation->fresh()->status)->toBe('queued');
    $this->assertDatabaseCount('implementation_events', 0);
})->with(['missing', 'foreign', 'duplicate', 'branch', 'commit', 'unchanged with new commit', 'changed with base commit', 'all unchanged', 'legacy receipt']);

it('preserves individual publication progress if another repository blocks completion', function (): void {
    $this->postJson('/internal/implementation-requests', $this->payload)->assertCreated();
    $implementation = ImplementationRequest::findOrFail($this->payload['requestId']);
    $url = '/internal/implementation-requests/'.$implementation->id.'/events';
    $event = ImplementationPayload::event($implementation);
    $event['payload'] = [
        'stage' => 'publishing', 'branch' => $implementation->branch,
        'repository' => 'daedalus', 'commit' => str_repeat('b', 40),
    ];
    $this->postJson($url, $event)->assertCreated();
    $this->postJson($url, ImplementationPayload::event($implementation, 'blocked', 2))->assertCreated();
    $this->getJson('/internal/implementation-requests/'.$implementation->id)->assertOk()
        ->assertJsonPath('status', 'blocked')
        ->assertJsonPath('events.0.payload.repository', 'daedalus');
});
