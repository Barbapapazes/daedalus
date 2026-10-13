<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['implementation_request_id', 'event_id', 'sequence', 'type', 'payload', 'payload_hash', 'occurred_at'])]
class ImplementationEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['payload' => 'array', 'sequence' => 'integer', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Implementation events are append-only.'));
        static::deleting(fn () => throw new LogicException('Implementation events are append-only.'));
    }
}
