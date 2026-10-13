<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['integration_request_id', 'event_id', 'sequence', 'type', 'payload', 'payload_hash', 'occurred_at'])]
class IntegrationEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['payload' => 'array', 'sequence' => 'integer', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Integration events are append-only.'));
        static::deleting(fn () => throw new LogicException('Integration events are append-only.'));
    }
}
