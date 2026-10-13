<?php

declare(strict_types=1);

use Tests\Support\RepositoryPayload;

it('lists the most recently updated repositories first before pagination', function (): void {
    $older = RepositoryPayload::create(['name' => 'older-project', 'artifacts_name' => 'older-project']);
    $older->forceFill(['created_at' => now()->subDays(10), 'updated_at' => now()])->save();

    $newer = RepositoryPayload::create(['name' => 'newer-project', 'artifacts_name' => 'newer-project']);
    $newer->forceFill(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()])->save();

    $this->getJson('/internal/repositories?limit=1')->assertOk()
        ->assertJsonPath('data.0.id', $older->id)
        ->assertJsonPath('total', 2);

    $this->getJson('/internal/repositories?limit=1&page=2')->assertOk()
        ->assertJsonPath('data.0.id', $newer->id);
});

it('uses repository IDs to consistently order equal update timestamps', function (): void {
    $second = RepositoryPayload::create([
        'id' => '00000000-0000-4000-8000-000000000002',
        'name' => 'second-project', 'artifacts_name' => 'second-project',
    ]);
    $first = RepositoryPayload::create([
        'id' => '00000000-0000-4000-8000-000000000001',
        'name' => 'first-project', 'artifacts_name' => 'first-project',
    ]);

    $this->getJson('/internal/repositories')->assertOk()
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.1.id', $second->id);
});
