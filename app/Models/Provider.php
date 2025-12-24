<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    /** @use HasFactory<\Database\Factories\ProviderFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'trade_name',
        'slug',
        'category',
        'description',
        'official_logo',
        'web_link',
        'is_active',
        'points_to_value_ratio',
        'transfer_fee_percent',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'points_to_value_ratio' => 'decimal:4',
            'transfer_fee_percent' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    /**
     * Get all point transactions for this provider.
     *
     * @return HasMany<PointTransaction, $this>
     */
    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class);
    }

    /**
     * Get all user balances for this provider.
     *
     * @return HasMany<UserProviderBalance, $this>
     */
    public function userBalances(): HasMany
    {
        return $this->hasMany(UserProviderBalance::class);
    }

    /**
     * Scope to only include active providers.
     *
     * @param  Builder<Provider>  $query
     * @return Builder<Provider>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Get all vendor links for this provider.
     *
     * @return HasMany<VendorUserLink, $this>
     */
    public function vendorLinks(): HasMany
    {
        return $this->hasMany(VendorUserLink::class);
    }
}
