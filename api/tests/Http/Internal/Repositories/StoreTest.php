<?php

declare(strict_types=1);

use App\Jobs\ProvisionRepository;
use App\Models\Repository;
use App\Models\RepositoryCreationRequest;
use App\Support\PayloadHash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\RepositoryPayload;

beforeEach(function (): void {
    $this->payload = RepositoryPayload::request();
});

it('stores the brief and atomically queues background provisioning with an API-owned repository ID', function (): void {
    $response = $this->postJson('/internal/repositories', $this->payload)->assertAccepted()
        ->assertJsonPath('status', 'provisioning')->assertJsonPath('artifacts_id', null)
        ->assertJsonPath('provisioning_error', null)->assertJsonMissingPath('payload_hash');
    $id = $response->json('id');
    expect(Str::isUuid($id))->toBeTrue()->and($id)->not->toBe($this->payload['requestId']);
    $this->assertDatabaseCount('repositories', 1);
    $this->assertDatabaseHas('repository_creation_requests', ['id' => $this->payload['requestId'], 'repository_id' => $id]);
    $this->assertDatabaseHas('jobs', ['queue' => 'repositories']);
    $job = unserialize(json_decode(DB::table('jobs')->value('payload'), true)['data']['command']);
    expect($job)->toBeInstanceOf(ProvisionRepository::class)->and($job->repositoryId)->toBe($id);
    Http::assertNothingSent();
});

it('acknowledges identical requests without duplicating repositories or jobs', function (): void {
    $id = $this->postJson('/internal/repositories', $this->payload)->assertAccepted()->json('id');
    $this->payload['requestId'] = strtoupper($this->payload['requestId']);
    $this->postJson('/internal/repositories', $this->payload)->assertAccepted()->assertJsonPath('id', $id);
    $this->assertDatabaseCount('repositories', 1);
    $this->assertDatabaseCount('repository_creation_requests', 1);
    $this->assertDatabaseCount('jobs', 1);
});

it('resolves the same name and brief from another conversation to the existing repository', function (): void {
    $id = $this->postJson('/internal/repositories', $this->payload)->assertAccepted()->json('id');
    $this->payload['requestId'] = (string) Str::uuid();
    $this->postJson('/internal/repositories', $this->payload)->assertAccepted()->assertJsonPath('id', $id);
    $this->assertDatabaseCount('repositories', 1);
    $this->assertDatabaseCount('repository_creation_requests', 2);
    $this->assertDatabaseCount('jobs', 1);
});

it('rejects changed briefs for the same request key without persisting another repository', function (string $field): void {
    $this->postJson('/internal/repositories', $this->payload)->assertAccepted();
    $this->payload[$field] = 'different';
    $this->postJson('/internal/repositories', $this->payload)->assertConflict();
    $this->assertDatabaseCount('repositories', 1);
    $this->assertDatabaseCount('repository_creation_requests', 1);
    $this->assertDatabaseCount('jobs', 1);
})->with(['description', 'context', 'name']);

it('rejects a different brief for a reserved name even with a new request key', function (string $field): void {
    $this->postJson('/internal/repositories', $this->payload)->assertAccepted();
    $this->payload['requestId'] = (string) Str::uuid();
    $this->payload[$field] = 'different';
    $this->postJson('/internal/repositories', $this->payload)->assertConflict();
    $this->assertDatabaseCount('repositories', 1);
    $this->assertDatabaseCount('repository_creation_requests', 1);
    $this->assertDatabaseCount('jobs', 1);
})->with(['description', 'context']);

it('requeues failed or legacy unqueued creations only once', function (string $status): void {
    $id = $this->postJson('/internal/repositories', $this->payload)->assertAccepted()->json('id');
    DB::table('jobs')->delete();
    Repository::findOrFail($id)->update([
        'status' => $status, 'provisioning_queued_at' => null, 'provisioning_error' => 'Earlier failure',
    ]);
    $this->payload['requestId'] = (string) Str::uuid();
    $this->postJson('/internal/repositories', $this->payload)->assertAccepted()
        ->assertJsonPath('id', $id)->assertJsonPath('status', 'provisioning')->assertJsonPath('provisioning_error', null);
    $this->postJson('/internal/repositories', $this->payload)->assertAccepted();
    $this->assertDatabaseCount('jobs', 1);
})->with(['failed', 'provisioning']);

it('returns ready repositories without reprovisioning them', function (): void {
    $id = $this->postJson('/internal/repositories', $this->payload)->assertAccepted()->json('id');
    DB::table('jobs')->delete();
    Repository::findOrFail($id)->update(['status' => 'ready']);
    $this->payload['requestId'] = (string) Str::uuid();
    $this->postJson('/internal/repositories', $this->payload)->assertOk()->assertJsonPath('id', $id)->assertJsonPath('status', 'ready');
    $this->assertDatabaseCount('jobs', 0);
});

it('rolls back the reservation and request mapping if enqueueing fails', function (): void {
    Queue::shouldReceive('connection')->once()->with('repositories')->andThrow(new RuntimeException('Queue unavailable'));
    $this->postJson('/internal/repositories', $this->payload)->assertStatus(500);
    $this->assertDatabaseCount('repositories', 0);
    $this->assertDatabaseCount('repository_creation_requests', 0);
    $this->assertDatabaseCount('jobs', 0);
});

it('rejects invalid requests without persisting or enqueueing work', function (string $field, mixed $value): void {
    $this->payload[$field] = $value;
    $this->postJson('/internal/repositories', $this->payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('repositories', 0);
    $this->assertDatabaseCount('repository_creation_requests', 0);
    $this->assertDatabaseCount('jobs', 0);
})->with([
    ['requestId', 'invalid'], ['requestId', null], ['name', '../repo'], ['description', ''], ['context', ''], ['context', str_repeat('a', 16001)],
]);

it('requires exactly one request key format', function (): void {
    $this->payload['id'] = (string) Str::uuid();
    $this->postJson('/internal/repositories', $this->payload)->assertUnprocessable();
    unset($this->payload['id'], $this->payload['requestId']);
    $this->postJson('/internal/repositories', $this->payload)->assertUnprocessable();
    $this->assertDatabaseCount('jobs', 0);
});

it('accepts frozen legacy requests and preserves their repository identity', function (): void {
    $this->payload['id'] = $this->payload['requestId'];
    unset($this->payload['requestId']);
    $this->postJson('/internal/repositories', $this->payload)->assertAccepted()->assertJsonPath('id', $this->payload['id']);
    $this->postJson('/internal/repositories', $this->payload)->assertAccepted()->assertJsonPath('id', $this->payload['id']);
    $this->assertDatabaseCount('jobs', 1);
});

it('recovers a legacy repository whose original payload hash included its ID', function (): void {
    $repository = RepositoryPayload::create();
    $legacy = ['id' => $repository->id, 'name' => $repository->name, 'description' => $repository->description, 'context' => $repository->context];
    $repository->update(['payload_hash' => PayloadHash::make($legacy), 'status' => 'failed']);
    $this->postJson('/internal/repositories', $legacy)->assertAccepted()->assertJsonPath('id', $repository->id);
    unset($legacy['id']);
    $legacy['requestId'] = (string) Str::uuid();
    $this->postJson('/internal/repositories', $legacy)->assertAccepted()->assertJsonPath('id', $repository->id);
    $this->assertDatabaseCount('repositories', 1);
    $this->assertDatabaseCount('jobs', 1);
});

it('prevents legacy ID reuse for another repository even before an idempotency mapping exists', function (): void {
    $repository = RepositoryPayload::create();
    $this->payload['id'] = $repository->id;
    $this->payload['name'] = 'different-project';
    unset($this->payload['requestId']);
    $this->postJson('/internal/repositories', $this->payload)->assertConflict();
    $this->assertDatabaseCount('repositories', 1);
    $this->assertDatabaseCount('repository_creation_requests', 0);
    $this->assertDatabaseCount('jobs', 0);
});

it('keeps request keys bound to their original repository', function (): void {
    $original = RepositoryPayload::create(['name' => 'original-project', 'artifacts_name' => 'original-project']);
    RepositoryCreationRequest::create([
        'id' => $this->payload['requestId'], 'repository_id' => $original->id,
        'payload_hash' => PayloadHash::make(['name' => 'original-project', 'description' => $original->description, 'context' => $original->context]),
    ]);
    $this->postJson('/internal/repositories', $this->payload)->assertConflict();
    $this->assertDatabaseCount('repositories', 1);
    $this->assertDatabaseCount('jobs', 0);
});

it('exposes background status and the original brief through get_repository', function (): void {
    $id = $this->postJson('/internal/repositories', $this->payload)->assertAccepted()->json('id');
    Repository::findOrFail($id)->update(['status' => 'failed', 'provisioning_error' => 'Provisioning could not be confirmed.']);
    $this->getJson('/internal/repositories/by-name/'.$this->payload['name'])->assertOk()
        ->assertJsonPath('context', $this->payload['context'])->assertJsonPath('status', 'failed')
        ->assertJsonPath('provisioning_error', 'Provisioning could not be confirmed.');
});

it('does not allow clients to mark a repository ready', function (): void {
    $repository = RepositoryPayload::create();
    $this->patchJson('/internal/repositories/'.$repository->id, ['status' => 'ready'])->assertStatus(405);
    expect($repository->fresh()->status)->toBe('provisioning');
});
