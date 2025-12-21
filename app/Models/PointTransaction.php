<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'points',
        'balance_after',
        'description',
        'metadata',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'points' => 'integer',
            'balance_after' => 'integer',
            'metadata' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to filter transactions by user ID.
     *
     * @param  Builder<PointTransaction>  $query
     * @return Builder<PointTransaction>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to only include credit transactions (positive points).
     *
     * @param  Builder<PointTransaction>  $query
     * @return Builder<PointTransaction>
     */
    public function scopeCredits(Builder $query): Builder
    {
        return $query->where('points', '>', 0);
    }

    /**
     * Scope to only include debit transactions (negative points).
     *
     * @param  Builder<PointTransaction>  $query
     * @return Builder<PointTransaction>
     */
    public function scopeDebits(Builder $query): Builder
    {
        return $query->where('points', '<', 0);
    }

    /**
     * Scope to exclude expired transactions.
     *
     * @param  Builder<PointTransaction>  $query
     * @return Builder<PointTransaction>
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Scope to find transactions expiring within given days.
     *
     * @param  Builder<PointTransaction>  $query
     * @return Builder<PointTransaction>
     */
    public function scopeExpiringSoon(Builder $query, int $days = 30): Builder
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays($days));
    }
}
