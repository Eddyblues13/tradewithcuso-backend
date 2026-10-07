<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The signed-in trader's own account. */
class TraderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $current = $this->currentSubscription();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'created_at' => $this->created_at?->toIso8601String(),
            'current_subscription' => $current ? new SubscriptionResource($current->load('plan')) : null,
        ];
    }
}
