<?php

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Mail\PaymentAlert;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Paystack;
use App\Support\Notifier;
use App\Support\SubscriptionPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A trader choosing a plan and paying for it.
 *
 * Choosing a plan creates a pending subscription with the price frozen on it.
 * It goes active when the money is confirmed — by Paystack, or by an admin
 * who has seen a bank transfer land.
 */
class SubscriptionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return SubscriptionResource::collection(
            $request->user()->subscriptions()->with('plan')->get()
        );
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $subscription = $this->find($request->user(), $reference);

        return $this->respond($subscription);
    }

    public function store(Request $request, Paystack $paystack): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', Rule::exists('plans', 'slug')->where('is_active', true)],
            'payment_method' => ['required', Rule::in([Subscription::METHOD_PAYSTACK, Subscription::METHOD_TRANSFER])],
        ], [
            'plan.exists' => 'That plan is not available.',
        ]);

        $this->ensureMethodAvailable($data['payment_method'], $paystack);

        $user = $request->user();
        $plan = Plan::where('slug', $data['plan'])->firstOrFail();

        // One open bill at a time. An earlier one the trader walked away from
        // is closed — unless they have told us they paid it, in which case it
        // waits for an admin rather than vanishing.
        $user->subscriptions()
            ->where('status', Subscription::STATUS_PENDING)
            ->whereNull('transfer_reported_at')
            ->whereNull('amount_paid')
            ->update([
                'status' => Subscription::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

        $subscription = $user->subscriptions()->create([
            'plan_id' => $plan->id,
            'reference' => Subscription::generateReference(),
            'plan_name' => $plan->name,
            'amount' => $plan->price,
            'duration_days' => $plan->duration_days,
            'status' => Subscription::STATUS_PENDING,
            'payment_method' => $data['payment_method'],
        ]);

        $paymentUrl = $data['payment_method'] === Subscription::METHOD_PAYSTACK
            ? $this->startPaystack($subscription, $paystack)
            : null;

        return $this->respond($subscription, $paymentUrl, 201);
    }

    /** Pay a pending bill by card — a first try, or another after an abandoned one. */
    public function paystack(Request $request, string $reference, Paystack $paystack): JsonResponse
    {
        $subscription = $this->findPayable($request->user(), $reference);

        $this->ensureMethodAvailable(Subscription::METHOD_PAYSTACK, $paystack);

        $subscription->forceFill(['payment_method' => Subscription::METHOD_PAYSTACK])->save();

        return $this->respond($subscription, $this->startPaystack($subscription, $paystack));
    }

    /**
     * Called when the trader lands back from Paystack.
     *
     * Arriving here proves nothing on its own; the server asks Paystack what
     * actually happened. The webhook may well have got there first, in which
     * case this simply reports the plan is already active.
     */
    public function verifyPayment(Request $request, string $reference, Paystack $paystack): JsonResponse
    {
        $subscription = $this->find($request->user(), $reference);

        if ($subscription->status !== Subscription::STATUS_PENDING || blank($subscription->payment_reference)) {
            return $this->respond($subscription);
        }

        try {
            $transaction = $paystack->verify($subscription->payment_reference);
        } catch (RuntimeException $e) {
            Log::warning('Could not verify a subscription payment', [
                'subscription' => $subscription->reference,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'We could not confirm the payment with Paystack just yet. If you were charged, your plan will switch on automatically within a few minutes.',
            ], 502);
        }

        if (($transaction['status'] ?? null) === 'success') {
            $subscription = SubscriptionPayments::paidViaPaystack($subscription, $transaction);
        }

        return $this->respond($subscription->fresh());
    }

    /** The trader says they have made the bank transfer. */
    public function transferSent(Request $request, string $reference): JsonResponse
    {
        $subscription = $this->findPayable($request->user(), $reference);

        if (! Setting::bankDetails()) {
            throw ValidationException::withMessages([
                'payment_method' => 'Bank transfer is not available right now.',
            ]);
        }

        $firstReport = $subscription->transfer_reported_at === null;

        $subscription->forceFill([
            'payment_method' => Subscription::METHOD_TRANSFER,
            'transfer_reported_at' => now(),
        ])->save();

        // Repeat clicks refresh the timestamp but do not repeat the email.
        if ($firstReport) {
            Notifier::send(Notifier::team(), new PaymentAlert($subscription->load('user'), 'transfer_reported'), 'transfer alert');
        }

        return $this->respond($subscription);
    }

    public function cancel(Request $request, string $reference): JsonResponse
    {
        $subscription = $this->findPayable($request->user(), $reference);

        if ($subscription->transfer_reported_at || $subscription->amount_paid) {
            throw ValidationException::withMessages([
                'subscription' => 'This payment is being checked by our team, so it can no longer be cancelled here. Contact support if you need help.',
            ]);
        }

        $subscription->forceFill([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();

        return $this->respond($subscription);
    }

    /** Looked up through the trader's own subscriptions, so a reference alone never exposes someone else's. */
    private function find(User $user, string $reference): Subscription
    {
        return $user->subscriptions()->with('plan')->where('reference', $reference)->firstOrFail();
    }

    private function findPayable(User $user, string $reference): Subscription
    {
        $subscription = $this->find($user, $reference);

        if ($subscription->status !== Subscription::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'subscription' => 'This subscription is no longer awaiting payment.',
            ]);
        }

        return $subscription;
    }

    private function ensureMethodAvailable(string $method, Paystack $paystack): void
    {
        $available = match ($method) {
            Subscription::METHOD_PAYSTACK => $paystack->configured(),
            Subscription::METHOD_TRANSFER => Setting::bankDetails() !== null,
            default => false,
        };

        if (! $available) {
            throw ValidationException::withMessages([
                'payment_method' => $method === Subscription::METHOD_PAYSTACK
                    ? 'Card payment is not available right now. Please pay by bank transfer.'
                    : 'Bank transfer is not available right now. Please pay by card.',
            ]);
        }
    }

    private function startPaystack(Subscription $subscription, Paystack $paystack): string
    {
        $subscription->forceFill(['payment_reference' => $subscription->newPaymentReference()])->save();

        try {
            $transaction = $paystack->initialize(
                $subscription,
                config('app.frontend_url').'/dashboard/subscriptions/'.$subscription->reference,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'payment_method' => 'We could not reach Paystack. Please try again, or pay by bank transfer.',
            ]);
        }

        return $transaction['authorization_url'];
    }

    private function respond(Subscription $subscription, ?string $paymentUrl = null, int $status = 200): JsonResponse
    {
        $subscription->loadMissing('plan');

        return response()->json([
            'data' => new SubscriptionResource($subscription),
            'payment_url' => $paymentUrl,
            // Only while there is something to pay: these are not shown to
            // anyone without a bill in hand.
            'bank_transfer' => $subscription->status === Subscription::STATUS_PENDING
                ? Setting::bankDetails()
                : null,
        ], $status);
    }
}
