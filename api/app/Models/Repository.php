<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'name', 'description', 'context', 'artifacts_name', 'artifacts_id', 'initial_commit', 'status', 'payload_hash', 'provisioning_queued_at', 'provisioning_error'])]
class Repository extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['payload_hash'];
}
