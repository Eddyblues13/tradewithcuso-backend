<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A trader as the dashboard lists them. */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // List pages eager-load `runningSubscriptions`, so a page of twenty
        // users is not twenty extra queries.
        $current = $this->currentSubscription();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'subscriptions_count' => $this->whenCounted('subscriptions'),
            'total_paid' => $this->whenHas('total_paid', fn () => (int) $this->total_paid),
            'current_plan' => $current ? [
                'name' => $current->plan_name,
                'ends_at' => $current->ends_at?->toIso8601String(),
            ] : null,
        ];
    }
}
