<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AdminSubscriptionResource extends SubscriptionResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            ...parent::toArray($request),
            'plan_id' => $this->plan_id,
            'payment_reference' => $this->payment_reference,
            'payment_channel' => $this->payment_channel,
            'admin_note' => $this->admin_note,
            'activated_by' => $this->whenLoaded('activator', fn () => $this->activator?->name),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
                'is_active' => $this->user->is_active,
            ]),
        ];
    }
}
