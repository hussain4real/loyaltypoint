<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConsumerEnrollmentIntentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class ConsumerEnrollmentIntent extends Model
{
    /** @use HasFactory<ConsumerEnrollmentIntentFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['reference', 'consumer_identity_id', 'consumer_verification_revision_id', 'purpose', 'policy_reference', 'payload'];

    protected $hidden = ['evidence', 'checks', 'payload', 'payload_hash', 'request_hash', 'idempotency_hash'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $record->reference ??= (string) Str::uuid();
        });
        static::updating(fn () => throw new LogicException('Verification evidence is append-only.'));
        static::deleting(fn () => throw new LogicException('Verification evidence is append-only.'));
    }
}
