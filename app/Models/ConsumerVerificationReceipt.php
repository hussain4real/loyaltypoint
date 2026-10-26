<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConsumerVerificationReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class ConsumerVerificationReceipt extends Model
{
    /** @use HasFactory<ConsumerVerificationReceiptFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['reference', 'consumer_verification_revision_id', 'source', 'event_reference', 'identity_hash', 'payload_hash', 'payload', 'occurred_at'];

    protected $hidden = ['evidence', 'checks', 'payload', 'payload_hash', 'request_hash', 'idempotency_hash'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'occurred_at' => 'immutable_datetime'];
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
