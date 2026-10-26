<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KycState;
use Database\Factories\ConsumerVerificationRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ConsumerVerificationRevision extends Model
{
    /** @use HasFactory<ConsumerVerificationRevisionFactory> */
    use HasFactory;

    protected $fillable = ['reference', 'consumer_identity_id', 'customer_id', 'revision', 'policy_reference', 'source', 'operation_reference', 'idempotency_hash', 'request_hash', 'evidence', 'checks', 'unresolved_checks', 'state', 'manual_review_required', 'manual_decided_at', 'provider_observed_at'];

    protected $hidden = ['evidence', 'checks', 'unresolved_checks', 'payload', 'payload_hash', 'request_hash', 'idempotency_hash'];

    /** @return BelongsTo<ConsumerIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(ConsumerIdentity::class, 'consumer_identity_id');
    }

    protected function casts(): array
    {
        return ['revision' => 'integer', 'evidence' => 'encrypted:array', 'checks' => 'encrypted:array', 'unresolved_checks' => 'encrypted:array', 'manual_review_required' => 'boolean', 'manual_decided_at' => 'immutable_datetime', 'provider_observed_at' => 'immutable_datetime', 'state' => KycState::class];
    }

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $record->reference ??= (string) Str::uuid();
        });
    }
}
