<?php

namespace App\Models;

use Database\Factories\ImplementationRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

#[Fillable(['id', 'conversation_id', 'tool_call_id', 'tool_task_id', 'intent', 'messages', 'payload_hash', 'status', 'repository', 'repositories', 'branch', 'dispatched_at', 'dispatch_error'])]
class ImplementationRequest extends Model
{
    /** @use HasFactory<ImplementationRequestFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::updating(function (self $implementation): void {
            if ($implementation->isDirty(['id', 'conversation_id', 'tool_call_id', 'tool_task_id', 'intent', 'messages', 'payload_hash', 'repository', 'repositories', 'branch'])) {
                throw new LogicException('Reference intent, context and repository assignment are immutable.');
            }
        });
    }

    public function assignedRepositories(): array
    {
        return $this->repositories ?? ($this->repository ? [$this->repository] : []);
    }

    public function integration(): HasOne
    {
        return $this->hasOne(IntegrationRequest::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ImplementationEvent::class);
    }

    protected function casts(): array
    {
        return [
            'intent' => 'array',
            'messages' => 'array',
            'repository' => 'array',
            'repositories' => 'array',
            'dispatched_at' => 'immutable_datetime',
        ];
    }
}
