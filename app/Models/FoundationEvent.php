<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FoundationEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FoundationEvent extends Model
{
    /** @use HasFactory<FoundationEventFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['identity_hash', 'id', 'source', 'external_event_id', 'payload_hash', 'payload', 'state', 'attempts', 'available_at', 'lease_token', 'lease_expires_at', 'completed_at', 'last_error_code'];

    protected $hidden = ['identity_hash', 'payload', 'payload_hash', 'deduplication_key', 'lease_token'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'available_at' => 'immutable_datetime', 'lease_expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
