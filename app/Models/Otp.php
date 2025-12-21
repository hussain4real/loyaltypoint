<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Otp extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'code',
        'purpose',
        'expires_at',
        'verified_at',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * Get the user that owns the OTP.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the OTP has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if the OTP has been verified.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Check if the OTP is still valid (not expired and not verified).
     */
    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isVerified();
    }

    /**
     * Check if max attempts have been exceeded.
     */
    public function hasExceededAttempts(int $maxAttempts = 3): bool
    {
        return $this->attempts >= $maxAttempts;
    }

    /**
     * Increment the attempts counter.
     */
    public function incrementAttempts(): void
    {
        $this->increment('attempts');
    }

    /**
     * Mark the OTP as verified.
     */
    public function markAsVerified(): void
    {
        $this->update(['verified_at' => now()]);
    }

    /**
     * Scope to get valid OTPs (not expired and not verified).
     */
    public function scopeValid($query)
    {
        return $query->whereNull('verified_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Scope to filter by purpose.
     */
    public function scopeForPurpose($query, string $purpose)
    {
        return $query->where('purpose', $purpose);
    }
}
