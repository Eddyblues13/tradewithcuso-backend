<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminSubscriptionResource;
use App\Http\Resources\UserResource;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $thisMonth = now()->startOfMonth();
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth();

        $revenueThisMonth = $this->revenueBetween($thisMonth, now());
        $revenueLastMonth = $this->revenueBetween($lastMonth, $thisMonth);

        return response()->json([
            'data' => [
                // Revenue is money actually received, dated by when it arrived.
                // A comp granted from the dashboard has amount_paid 0, so it
                // never inflates these figures.
                'revenue_total' => (int) Subscription::whereNotNull('paid_at')->sum('amount_paid'),
                'revenue_this_month' => $revenueThisMonth,
                'revenue_last_month' => $revenueLastMonth,
                'revenue_change' => $this->percentageChange($revenueLastMonth, $revenueThisMonth),

                'users_total' => User::count(),
                'users_this_month' => User::where('created_at', '>=', $thisMonth)->count(),
                'users_suspended' => User::where('is_active', false)->count(),

                'subscriptions_active' => Subscription::current()->count(),
                'subscriptions_pending' => Subscription::inState('pending')->count(),
                'subscriptions_awaiting' => Subscription::inState('awaiting')->count(),
                'subscriptions_expiring' => Subscription::current()
                    ->where('ends_at', '<=', now()->addDays(7))
                    ->count(),

                'plans' => $this->planBreakdown(),
                'recent_subscriptions' => AdminSubscriptionResource::collection(
                    Subscription::with(['user', 'plan'])->latest('id')->limit(6)->get()
                ),
                'recent_users' => UserResource::collection(
                    User::with('runningSubscriptions')->latest('id')->limit(6)->get()
                ),
            ],
        ]);
    }

    /**
     * Who is on each plan today, and what each has brought in.
     *
     * @return list<array<string, mixed>>
     */
    private function planBreakdown(): array
    {
        return Plan::query()
            ->ordered()
            ->withCount(['subscriptions as active_count' => fn ($q) => $q->current()])
            ->withSum(['subscriptions as revenue' => fn ($q) => $q->whereNotNull('paid_at')], 'amount_paid')
            ->get()
            ->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'price' => $plan->price,
                'is_active' => $plan->is_active,
                'active_count' => (int) $plan->active_count,
                'revenue' => (int) $plan->revenue,
            ])
            ->all();
    }

    private function revenueBetween(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return (int) Subscription::whereNotNull('paid_at')
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $to)
            ->sum('amount_paid');
    }

    /**
     * Percentage movement, or null when there is no baseline to compare to.
     */
    private function percentageChange(int $previous, int $current): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
