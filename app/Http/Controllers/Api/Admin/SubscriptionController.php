<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminSubscriptionResource;
use App\Models\Subscription;
use App\Support\SubscriptionPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubscriptionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'state' => ['nullable', Rule::in([...Subscription::STATES, 'awaiting'])],
            'plan' => ['nullable', 'integer'],
            'method' => ['nullable', Rule::in(Subscription::METHODS)],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $subscriptions = Subscription::query()
            ->with(['user', 'plan'])
            ->when($request->filled('state'), fn ($q) => $q->inState($request->input('state')))
            ->when($request->filled('plan'), fn ($q) => $q->where('plan_id', $request->integer('plan')))
            ->when($request->filled('method'), fn ($q) => $q->where('payment_method', $request->input('method')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.trim($request->input('search')).'%';

                $q->where(fn ($q) => $q
                    ->where('reference', 'like', $term)
                    ->orWhere('payment_reference', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u
                        ->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term)));
            })
            // Transfers waiting on a human float to the top.
            ->orderByRaw('case when status = ? and transfer_reported_at is not null then 0 else 1 end', [Subscription::STATUS_PENDING])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return AdminSubscriptionResource::collection($subscriptions);
    }

    public function show(Subscription $subscription): AdminSubscriptionResource
    {
        return new AdminSubscriptionResource($subscription->load(['user', 'plan', 'activator']));
    }

    /**
     * Confirms the money has arrived — a bank transfer seen in the account,
     * or a payment taken some other way — and switches the plan on.
     */
    public function activate(Request $request, Subscription $subscription): AdminSubscriptionResource
    {
        $data = $request->validate([
            'amount_paid' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($subscription->status === Subscription::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'subscription' => 'This subscription is already active.',
            ]);
        }

        if (filled($data['note'] ?? null)) {
            $subscription->forceFill(['admin_note' => $data['note']])->save();
        }

        $subscription = SubscriptionPayments::activate($subscription, [
            'payment_method' => $subscription->payment_method ?? Subscription::METHOD_TRANSFER,
            'amount_paid' => $data['amount_paid'] ?? $subscription->amount,
        ], $request->user());

        return new AdminSubscriptionResource($subscription->load(['user', 'plan', 'activator']));
    }

    /**
     * Cancelling a running plan ends access straight away. The payment record
     * stays — refunds, if any, are handled outside the app.
     */
    public function cancel(Request $request, Subscription $subscription): AdminSubscriptionResource
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($subscription->status === Subscription::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'subscription' => 'This subscription is already cancelled.',
            ]);
        }

        $subscription->forceFill([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'admin_note' => $data['note'] ?? $subscription->admin_note,
        ])->save();

        return new AdminSubscriptionResource($subscription->load(['user', 'plan', 'activator']));
    }

    public function updateNote(Request $request, Subscription $subscription): AdminSubscriptionResource
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $subscription->forceFill(['admin_note' => $data['note']])->save();

        return new AdminSubscriptionResource($subscription->load(['user', 'plan', 'activator']));
    }

    /** Only bills nobody paid. Anything with money against it stays on the books. */
    public function destroy(Subscription $subscription): JsonResponse
    {
        if ($subscription->paid_at || $subscription->amount_paid) {
            throw ValidationException::withMessages([
                'subscription' => 'A paid subscription cannot be deleted. Cancel it instead.',
            ]);
        }

        $subscription->delete();

        return response()->json(['message' => "{$subscription->reference} was deleted."]);
    }
}
