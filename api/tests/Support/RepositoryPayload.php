<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Repository;
use App\Support\PayloadHash;
use Illuminate\Support\Str;

class RepositoryPayload
{
    public static function request(): array
    {
        return ['requestId' => (string) Str::uuid(), 'name' => 'example-project', 'description' => 'An example project.', 'context' => 'Build a useful first version.'];
    }

    public static function create(array $attributes = []): Repository
    {
        $payload = self::request();
        unset($payload['requestId']);

        return Repository::create(array_merge($payload, [
            'id' => (string) Str::uuid(),
            'artifacts_name' => $payload['name'], 'payload_hash' => PayloadHash::make($payload), 'status' => 'provisioning',
        ], $attributes))->refresh();
    }
}
