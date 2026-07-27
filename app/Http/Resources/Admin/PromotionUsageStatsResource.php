<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionUsageStatsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'promotion_id' => $this->id,
            'code' => $this->code,
            'usage_count' => (int) $this->usages_count,
            'usage_limit' => $this->usage_limit,
            'remaining_uses' => $this->usage_limit === null
                ? null
                : max(0, $this->usage_limit - (int) $this->usages_count),
        ];
    }
}
