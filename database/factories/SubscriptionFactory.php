<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_id' => Plan::factory(),
            'reference' => fn () => Subscription::generateReference(),
            'plan_name' => fn (array $attributes) => Plan::find($attributes['plan_id'])?->name ?? 'Plan',
            'amount' => fn (array $attributes) => Plan::find($attributes['plan_id'])?->price ?? 50000,
            'duration_days' => fn (array $attributes) => Plan::find($attributes['plan_id'])?->duration_days ?? 30,
            'status' => Subscription::STATUS_PENDING,
            'payment_method' => Subscription::METHOD_TRANSFER,
        ];
    }

    /** Paid and running today. */
    public function active(int $daysLeft = 20): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Subscription::STATUS_ACTIVE,
            'amount_paid' => $attributes['amount'] ?? 50000,
            'paid_at' => now()->subDays(10),
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays($daysLeft),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Subscription::STATUS_ACTIVE,
            'amount_paid' => $attributes['amount'] ?? 50000,
            'paid_at' => now()->subDays(60),
            'starts_at' => now()->subDays(60),
            'ends_at' => now()->subDays(30),
        ]);
    }

    public function transferReported(): static
    {
        return $this->state(fn (array $attributes) => [
            'transfer_reported_at' => now(),
        ]);
    }
}
