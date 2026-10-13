<?php

declare(strict_types=1);

use App\Models\ImplementationRequest;

it('lists six recent intents without exposing implementation context', function (): void {
    ImplementationRequest::factory()->count(6)->create(['updated_at' => now()->subDay()]);
    $latest = ImplementationRequest::factory()->create([
        'created_at' => now()->subWeek(), 'updated_at' => now(),
    ]);

    $response = $this->getJson('/internal/implementation-requests')->assertOk()
        ->assertJsonCount(6, 'data')
        ->assertJsonPath('total', 7)
        ->assertJsonPath('data.0.id', $latest->id)
        ->assertJsonPath('data.0.intent', $latest->intent);

    expect(array_keys($response->json('data.0')))->toBe(['id', 'intent', 'status', 'updated_at', 'repositories', 'integration']);
    $response->assertJsonPath('data.0.repositories', [$latest->repository])
        ->assertJsonPath('data.0.status', $latest->status)
        ->assertJsonPath('data.0.integration', null);
});

it('paginates intents consistently when update timestamps are equal', function (): void {
    $second = ImplementationRequest::factory()->create(['id' => '00000000-0000-4000-8000-000000000002']);
    $first = ImplementationRequest::factory()->create(['id' => '00000000-0000-4000-8000-000000000001']);

    $this->getJson('/internal/implementation-requests?limit=1')->assertOk()
        ->assertJsonPath('data.0.id', $first->id);
    $this->getJson('/internal/implementation-requests?limit=1&page=2')->assertOk()
        ->assertJsonPath('data.0.id', $second->id);
});

it('filters repository intents across legacy and cross-repository assignments with pagination and statuses', function (): void {
    $legacy = ImplementationRequest::factory()->create([
        'status' => 'completed', 'updated_at' => now()->subDay(),
    ]);
    $crossRepository = ImplementationRequest::factory()->create([
        'repository' => null,
        'repositories' => [
            ['name' => 'other', 'baseRef' => 'main', 'baseCommit' => str_repeat('b', 40)],
            $legacy->repository,
        ],
        'status' => 'running',
    ]);
    ImplementationRequest::factory()->create([
        'repository' => ['name' => 'unrelated', 'baseRef' => 'main', 'baseCommit' => str_repeat('c', 40)],
    ]);
    // The newer multi-repository assignment takes precedence over any legacy field.
    ImplementationRequest::factory()->create([
        'repositories' => [['name' => 'other', 'baseRef' => 'main', 'baseCommit' => str_repeat('b', 40)]],
    ]);

    $this->getJson('/internal/implementation-requests?repository=daedalus&limit=1')->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('last_page', 2)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $crossRepository->id)
        ->assertJsonPath('data.0.status', 'running')
        ->assertJsonPath('data.0.repositories', $crossRepository->repositories)
        ->assertJsonMissingPath('data.0.messages')
        ->assertJsonMissingPath('data.0.payload_hash');
    $this->getJson('/internal/implementation-requests?repository=daedalus&limit=1&page=2')->assertOk()
        ->assertJsonPath('data.0.id', $legacy->id)
        ->assertJsonPath('data.0.status', 'completed');
    $this->getJson('/internal/implementation-requests?repository=missing')->assertOk()
        ->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
});

it('rejects invalid intent pagination', function (string $query, string $field): void {
    $this->getJson('/internal/implementation-requests?'.$query)->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    ['limit=0', 'limit'],
    ['limit=201', 'limit'],
    ['page=0', 'page'],
    ['page=invalid', 'page'],
    ['repository=invalid/name', 'repository'],
    ['repository=', 'repository'],
]);
