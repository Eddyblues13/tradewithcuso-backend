<?php

namespace App\Http\Resources;

use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A subscription as its own trader sees it. */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'plan_name' => $this->plan_name,
            'plan_slug' => $this->plan?->slug,
            'amount' => $this->amount,
            'duration_days' => $this->duration_days,
            'duration_label' => Plan::durationLabel($this->duration_days),
            'status' => $this->status,
            'state' => $this->state(),
            'days_left' => $this->daysLeft(),
            'payment_method' => $this->payment_method,
            'amount_paid' => $this->amount_paid,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'transfer_reported_at' => $this->transfer_reported_at?->toIso8601String(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'is_payable' => $this->status === Subscription::STATUS_PENDING,
        ];
    }
}
