<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'repository_id', 'payload_hash'])]
class RepositoryCreationRequest extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['payload_hash'];
}
