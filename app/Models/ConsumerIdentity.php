<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountRestriction;
use App\Enums\KycState;
use Database\Factories\ConsumerIdentityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ConsumerIdentity extends Model
{
    /** @use HasFactory<ConsumerIdentityFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'reference', 'contact_hash', 'contact_verified_at', 'terms_version', 'privacy_notice_version', 'notices_accepted_at', 'kyc_state', 'restriction', 'verification_revision', 'policy_reference', 'verified_at', 'expires_at'];

    protected $hidden = ['contact_hash'];

    protected static function booted(): void
    {
        static::creating(function (self $identity): void {
            $identity->reference ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['contact_verified_at' => 'immutable_datetime', 'notices_accepted_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'verification_revision' => 'integer', 'kyc_state' => KycState::class, 'restriction' => AccountRestriction::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
