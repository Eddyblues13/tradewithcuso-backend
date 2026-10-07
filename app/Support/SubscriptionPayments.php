<?php

namespace App\Support;

use App\Mail\PaymentAlert;
use App\Mail\SubscriptionActivated;
use App\Models\Admin;
use App\Models\Subscription;
use App\Services\Paystack;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turning money received into an active plan.
 *
 * Three things can race to call this: the trader returning from Paystack,
 * Paystack's webhook (which can itself fire more than once), and an admin
 * confirming a bank transfer. So it is safe to call repeatedly — the row is
 * locked, an already-active subscription comes back untouched, and the emails
 * go out exactly once.
 */
class SubscriptionPayments
{
    /**
     * @param  array  $transaction  The `data` block from a Paystack verify or webhook.
     */
    public static function paidViaPaystack(Subscription $subscription, array $transaction): Subscription
    {
        $paid = Paystack::toNaira((int) ($transaction['amount'] ?? 0));
        $currency = strtoupper((string) ($transaction['currency'] ?? 'NGN'));

        // A short or foreign-currency payment should be impossible, since the
        // amount is set server-side. If one arrives anyway the money is
        // recorded but the plan is not switched on — an admin decides.
        if ($currency !== 'NGN' || $paid < $subscription->amount) {
            Log::warning('Paystack payment did not cover the subscription', [
                'subscription' => $subscription->reference,
                'expected' => $subscription->amount,
                'paid' => $paid,
                'currency' => $currency,
            ]);

            $subscription->forceFill([
                'payment_channel' => $transaction['channel'] ?? null,
                'amount_paid' => $paid,
                'admin_note' => trim(($subscription->admin_note ? $subscription->admin_note."\n" : '')
                    ."Paystack received {$currency} {$paid} against ₦{$subscription->amount} — check before activating."),
            ])->save();

            return $subscription;
        }

        return self::activate($subscription, [
            'payment_method' => Subscription::METHOD_PAYSTACK,
            'payment_channel' => $transaction['channel'] ?? null,
            'amount_paid' => $paid,
        ]);
    }

    /**
     * Switches a subscription on.
     *
     * If the trader already has time left on a plan, the new one is queued to
     * start when that ends, so renewing early never throws away paid-for days.
     *
     * @param  array<string, mixed>  $payment
     */
    public static function activate(Subscription $subscription, array $payment = [], ?Admin $by = null): Subscription
    {
        $justActivated = false;

        $subscription = DB::transaction(function () use ($subscription, $payment, $by, &$justActivated) {
            /** @var Subscription $fresh */
            $fresh = Subscription::query()->lockForUpdate()->find($subscription->getKey());

            if ($fresh->status === Subscription::STATUS_ACTIVE) {
                return $fresh;
            }

            $runningUntil = Subscription::query()
                ->where('user_id', $fresh->user_id)
                ->whereKeyNot($fresh->getKey())
                ->where('status', Subscription::STATUS_ACTIVE)
                ->where('ends_at', '>', now())
                ->max('ends_at');

            $startsAt = $runningUntil ? Carbon::parse($runningUntil) : now();

            $fresh->forceFill([
                ...$payment,
                'status' => Subscription::STATUS_ACTIVE,
                'amount_paid' => $payment['amount_paid'] ?? $fresh->amount_paid ?? $fresh->amount,
                'paid_at' => now(),
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addDays($fresh->duration_days),
                'cancelled_at' => null,
                'activated_by' => $by?->getKey(),
            ])->save();

            $justActivated = true;

            return $fresh;
        });

        if ($justActivated) {
            $subscription->load('user');

            Notifier::send($subscription->user->email, new SubscriptionActivated($subscription), 'activation email');

            // Admins confirmed this themselves; only payments that arrived on
            // their own need announcing.
            if (! $by) {
                Notifier::send(Notifier::team(), new PaymentAlert($subscription, 'paid'), 'payment alert');
            }
        }

        return $subscription;
    }
}
