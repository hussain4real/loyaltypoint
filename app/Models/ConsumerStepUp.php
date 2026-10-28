<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConsumerStepUpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConsumerStepUp extends Model
{
    /** @use HasFactory<ConsumerStepUpFactory> */
    use HasFactory;

    protected $fillable = ['reference', 'consumer_session_id', 'device_id', 'challenge_reference', 'action_digest', 'expires_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
