<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Provider
 */
class ProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'trade_name' => $this->trade_name,
            'slug' => $this->slug,
            'category' => $this->category,
            'description' => $this->description,
            'official_logo' => $this->official_logo,
            'web_link' => $this->web_link,
            'is_active' => $this->is_active,
            'points_to_value_ratio' => (float) $this->points_to_value_ratio,
            'transfer_fee_percent' => (float) $this->transfer_fee_percent,
        ];
    }
}
