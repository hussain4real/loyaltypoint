<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FoundationDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FoundationDelivery extends Model
{
    /** @use HasFactory<FoundationDeliveryFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'user_id', 'deduplication_key', 'payload', 'state', 'attempts', 'available_at', 'lease_token', 'lease_expires_at', 'completed_at', 'last_error_code', 'provider_reference'];

    protected $hidden = ['payload', 'payload_hash', 'deduplication_key', 'lease_token'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'available_at' => 'immutable_datetime', 'lease_expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
