<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\Paystack;
use App\Support\SubscriptionPayments;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Paystack's server-to-server notification.
 *
 * This, not the trader's return to the site, is what the app relies on: it
 * arrives whether or not the trader waits for the redirect, keeps retrying
 * until it gets a 200, and is signed so it can be trusted.
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, Paystack $paystack): Response
    {
        if (! $paystack->signatureIsValid($request->getContent(), $request->header('x-paystack-signature'))) {
            Log::warning('Rejected a Paystack webhook with a bad signature', [
                'ip' => $request->ip(),
            ]);

            return response()->noContent(401);
        }

        $event = $request->input('event');
        $data = $request->input('data', []);
        $reference = $data['reference'] ?? null;

        if (blank($reference)) {
            return response()->noContent();
        }

        $subscription = Subscription::query()->where('payment_reference', $reference)->first();

        if (! $subscription) {
            // Not ours. Acknowledged so Paystack stops retrying something we
            // will never recognise.
            Log::info('Paystack webhook for an unknown reference', ['reference' => $reference]);

            return response()->noContent();
        }

        if ($event === 'charge.success') {
            SubscriptionPayments::paidViaPaystack($subscription, $data);
        } else {
            Log::info('Unhandled Paystack event', ['event' => $event, 'reference' => $reference]);
        }

        // Always 200 once the signature checks out, even for events we ignore —
        // anything else makes Paystack retry forever.
        return response()->noContent();
    }
}
