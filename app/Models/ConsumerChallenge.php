<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConsumerChallengeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConsumerChallenge extends Model
{
    /** @use HasFactory<ConsumerChallengeFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'user_id', 'consumer_identity_id', 'purpose', 'method', 'contact_hash', 'device_reference', 'session_reference', 'action_digest', 'proof_hash', 'payload', 'expires_at', 'attempts', 'consumed_at', 'revoked_at'];

    protected $hidden = ['proof_hash', 'payload', 'contact_hash'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
