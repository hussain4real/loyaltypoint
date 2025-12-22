<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class PointBalanceResource extends JsonResource
{
    private ?Provider $provider = null;

    /**
     * Set the provider context for this resource.
     */
    public function forProvider(Provider $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->provider) {
            return [
                'customer_id' => $this->id,
                'provider' => [
                    'id' => $this->provider->id,
                    'name' => $this->provider->name,
                    'slug' => $this->provider->slug,
                ],
                'points_balance' => $this->getBalanceForProvider($this->provider),
                'last_transaction_at' => $this->pointTransactions()
                    ->where('provider_id', $this->provider->id)
                    ->latest()
                    ->first()?->created_at?->toIso8601String(),
            ];
        }

        // Return all provider balances if no specific provider
        $balances = $this->providerBalances()->with('provider')->get();

        return [
            'customer_id' => $this->id,
            'balances' => $balances->map(fn ($balance) => [
                'provider' => [
                    'id' => $balance->provider->id,
                    'name' => $balance->provider->name,
                    'slug' => $balance->provider->slug,
                ],
                'points_balance' => $balance->balance,
            ])->values()->all(),
        ];
    }
}
