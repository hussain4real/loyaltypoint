<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConsumerOtpDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConsumerOtpDelivery extends Model
{
    /** @use HasFactory<ConsumerOtpDeliveryFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'challenge_id', 'payload', 'state', 'attempts', 'available_at', 'expires_at', 'completed_at', 'last_error_code', 'lease_token', 'lease_expires_at'];

    protected $hidden = ['payload', 'lease_token'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'available_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'lease_expires_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
