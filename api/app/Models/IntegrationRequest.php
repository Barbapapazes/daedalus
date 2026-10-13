<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['id', 'implementation_request_id', 'assignment', 'status', 'dispatched_at', 'dispatch_error'])]
class IntegrationRequest extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::updating(function (self $integration): void {
            if ($integration->isDirty(['id', 'implementation_request_id', 'assignment'])) {
                throw new LogicException('Integration assignment is immutable.');
            }
        });
    }

    public function events(): HasMany
    {
        return $this->hasMany(IntegrationEvent::class);
    }

    public function summary(): array
    {
        return $this->only(['id', 'status', 'dispatch_error', 'updated_at']);
    }

    /** Read-only workflow projection; publication receipts are never inferred from status. */
    public function details(): array
    {
        $events = $this->events()->orderBy('sequence')->get([
            'event_id', 'sequence', 'type', 'payload', 'occurred_at',
        ]);
        $terminal = $events->last(fn ($event) => in_array($event->type, ['completed', 'blocked', 'failed'], true));

        return $this->summary() + [
            'repositories' => array_map(fn (array $repository): array => [
                'name' => $repository['name'],
                'targetBranch' => $repository['targetBranch'],
                'sourceCommit' => $repository['sourceCommit'],
            ], $this->assignment['repositories']),
            'publications' => $events->flatMap(fn ($event) => $event->payload['publications'] ?? [])
                ->unique('repository')->values()->all(),
            'unconfirmedPublications' => $terminal?->payload['unconfirmedPublications'] ?? [],
            'report' => $terminal?->payload['summary'] ?? null,
            'mergedAt' => $terminal?->type === 'completed' ? $terminal->occurred_at->toISOString() : null,
            'events' => $events,
        ];
    }

    protected function casts(): array
    {
        return ['assignment' => 'array', 'dispatched_at' => 'immutable_datetime'];
    }
}
