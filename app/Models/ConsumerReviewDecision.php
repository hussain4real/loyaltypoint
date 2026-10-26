<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConsumerReviewDecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class ConsumerReviewDecision extends Model
{
    /** @use HasFactory<ConsumerReviewDecisionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['reference', 'consumer_verification_revision_id', 'actor_id', 'decision', 'reason', 'evidence_reference', 'resolved_checks'];

    protected $hidden = ['evidence', 'checks', 'payload', 'payload_hash', 'request_hash', 'idempotency_hash'];

    protected function casts(): array
    {
        return ['resolved_checks' => 'array'];
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
