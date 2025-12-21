<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class PointBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'customer_id' => $this->id,
            'points_balance' => $this->point_balance,
            'tier' => $this->loyalty_tier,
            'last_transaction_at' => $this->pointTransactions()->latest()->first()?->created_at?->toIso8601String(),
        ];
    }
}
