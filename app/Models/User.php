<?php

declare(strict_types=1);

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
     * Get all provider balances for this user.
     *
     * @return HasMany<UserProviderBalance, $this>
     */
    public function providerBalances(): HasMany
    {
        return $this->hasMany(UserProviderBalance::class);
    }

    /**
     * Get the user's point balance for a specific provider.
     */
    public function getBalanceForProvider(Provider $provider): int
    {
        $balance = $this->providerBalances()
            ->where('provider_id', $provider->id)
            ->first();

        return $balance?->balance ?? 0;
    }

    /**
     * Get or create the user's balance record for a specific provider.
     */
    public function getOrCreateProviderBalance(Provider $provider): UserProviderBalance
    {
        return $this->providerBalances()->firstOrCreate(
            ['provider_id' => $provider->id],
            ['balance' => 0]
        );
    }

    /**
     * Get all vendor links for this user.
     *
     * @return HasMany<VendorUserLink, $this>
     */
    public function vendorLinks(): HasMany
    {
        return $this->hasMany(VendorUserLink::class);
    }
}
