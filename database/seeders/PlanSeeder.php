<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Starter plans, so the website has something to show on day one. Prices,
 * names and features are all editable from the dashboard afterwards.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter',
                'tagline' => 'Learn the basics and trade alongside the community.',
                'price' => 25000,
                'duration_days' => 30,
                'features' => [
                    'Beginner trading course',
                    'Daily market outlook',
                    'Access to the private community',
                    'Weekly live Q&A session',
                ],
                'is_popular' => false,
                'sort_order' => 1,
            ],
            [
                'name' => 'Pro',
                'tagline' => 'Signals, analysis and accountability for active traders.',
                'price' => 60000,
                'duration_days' => 90,
                'features' => [
                    'Everything in Starter',
                    'Daily trade signals with entry, stop loss and take profit',
                    'Weekly chart breakdowns',
                    'Risk-management templates',
                    'Priority support',
                ],
                'is_popular' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Elite Mentorship',
                'tagline' => 'One-on-one mentorship to build your own trading plan.',
                'price' => 150000,
                'duration_days' => 180,
                'features' => [
                    'Everything in Pro',
                    'Monthly one-on-one mentorship calls',
                    'Personal trading-journal reviews',
                    'Advanced strategy masterclasses',
                    'Direct line to Cuso',
                ],
                'is_popular' => false,
                'sort_order' => 3,
            ],
        ];

        // The slug is set here rather than left to the model, because
        // DatabaseSeeder runs without model events.
        foreach ($plans as $plan) {
            Plan::firstOrCreate(['slug' => Str::slug($plan['name'])], [...$plan, 'is_active' => true]);
        }
    }
}
