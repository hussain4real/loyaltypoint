<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get all point transactions for this user.
     *
     * @return HasMany<PointTransaction, $this>
     */
    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class);
    }

    /**
     * Get the user's current point balance.
     * Note: For MVP, expiration filtering is deferred. All points count.
     */
    public function getPointBalanceAttribute(): int
    {
        return (int) $this->pointTransactions()->sum('points');
    }

    /**
     * Get the user's loyalty tier based on total earned points.
     */
    public function getLoyaltyTierAttribute(): string
    {
        $totalEarned = (int) $this->pointTransactions()
            ->where('points', '>', 0)
            ->sum('points');

        return match (true) {
            $totalEarned >= 10000 => 'platinum',
            $totalEarned >= 5000 => 'gold',
            $totalEarned >= 1000 => 'silver',
            default => 'bronze',
        };
    }
}
