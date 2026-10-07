<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminSubscriptionResource;
use App\Http\Resources\UserResource;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\SubscriptionPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Traders, as the dashboard manages them. */
class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['active', 'suspended', 'subscribed', 'unsubscribed'])],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $users = User::query()
            ->with('runningSubscriptions')
            ->withCount('subscriptions')
            ->withSum(['subscriptions as total_paid' => fn ($q) => $q->whereNotNull('paid_at')], 'amount_paid')
            ->when($request->input('status'), fn ($q, $status) => match ($status) {
                'active' => $q->where('is_active', true),
                'suspended' => $q->where('is_active', false),
                'subscribed' => $q->whereHas('subscriptions', fn ($s) => $s->current()),
                'unsubscribed' => $q->whereDoesntHave('subscriptions', fn ($s) => $s->current()),
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.trim($request->input('search')).'%';

                $q->where(fn ($q) => $q
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function show(User $user): JsonResponse
    {
        $user->load('runningSubscriptions')
            ->loadCount('subscriptions')
            ->loadSum(['subscriptions as total_paid' => fn ($q) => $q->whereNotNull('paid_at')], 'amount_paid');

        return response()->json([
            'data' => new UserResource($user),
            'subscriptions' => AdminSubscriptionResource::collection(
                $user->subscriptions()->with(['plan', 'activator'])->get()
            ),
        ]);
    }

    /**
     * Suspends or restores a trader. Suspending signs them out everywhere at
     * once, rather than waiting for their tokens to expire.
     */
    public function toggle(User $user): UserResource
    {
        $user->update(['is_active' => ! $user->is_active]);

        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return new UserResource($user->load('runningSubscriptions'));
    }

    /**
     * Puts a trader on a plan directly — a complimentary place, or a payment
     * taken outside the website. `amount_paid` is what was actually received,
     * so a comp at zero never shows up as revenue.
     */
    public function grant(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'amount_paid' => ['required', 'integer', 'min:0', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $plan = Plan::findOrFail($data['plan_id']);

        $subscription = $user->subscriptions()->create([
            'plan_id' => $plan->id,
            'reference' => Subscription::generateReference(),
            'plan_name' => $plan->name,
            'amount' => $plan->price,
            'duration_days' => $plan->duration_days,
            'status' => Subscription::STATUS_PENDING,
            'payment_method' => Subscription::METHOD_MANUAL,
            'admin_note' => $data['note'] ?? null,
        ]);

        $subscription = SubscriptionPayments::activate($subscription, [
            'payment_method' => Subscription::METHOD_MANUAL,
            'amount_paid' => $data['amount_paid'],
        ], $request->user());

        return (new AdminSubscriptionResource($subscription->load(['user', 'plan', 'activator'])))
            ->response()
            ->setStatusCode(201);
    }

    /** Only traders who never paid. Anyone with payment history is suspended instead. */
    public function destroy(User $user): JsonResponse
    {
        if ($user->subscriptions()->whereNotNull('paid_at')->exists()) {
            throw ValidationException::withMessages([
                'user' => 'This trader has paid for a plan, so their record is kept. Suspend them instead.',
            ]);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => "{$user->name} was deleted."]);
    }
}
