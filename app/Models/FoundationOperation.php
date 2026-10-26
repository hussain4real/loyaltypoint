<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FoundationOperationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FoundationOperation extends Model
{
    /** @use HasFactory<FoundationOperationFactory> */
    use HasFactory;

    protected $fillable = ['identity_hash', 'source', 'reference', 'state', 'observed_at', 'last_event_id'];

    protected $hidden = ['identity_hash', 'payload', 'payload_hash', 'deduplication_key', 'lease_token'];

    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime'];
    }
}
