<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AdminPlanResource extends PlanResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'subscriptions_count' => $this->whenCounted('subscriptions'),
            'active_count' => $this->whenHas('active_count', fn () => (int) $this->active_count),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
