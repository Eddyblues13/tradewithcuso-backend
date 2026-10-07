<?php

namespace App\Services;

use App\Models\Subscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Paystack, wrapped so the rest of the app never deals in kobo.
 *
 * Every amount in this codebase — plan prices, subscription totals, revenue —
 * is whole naira. Paystack works in kobo. That conversion happens here and
 * nowhere else; doing it twice, or not at all, is the classic way to charge
 * someone a hundredth or a hundred times the real price.
 */
class Paystack
{
    private const BASE = 'https://api.paystack.co';

    public function configured(): bool
    {
        return filled(config('services.paystack.secret'));
    }

    private function secret(): string
    {
        $secret = config('services.paystack.secret');

        if (blank($secret)) {
            throw new RuntimeException('PAYSTACK_SECRET_KEY is not set.');
        }

        return $secret;
    }

    public static function toKobo(int $naira): int
    {
        return $naira * 100;
    }

    public static function toNaira(int $kobo): int
    {
        return intdiv($kobo, 100);
    }

    /**
     * Starts a transaction and hands back the URL to send the trader to.
     *
     * The amount comes from the subscription row, never the request, so a
     * tampered client payload cannot change what is charged.
     */
    public function initialize(Subscription $subscription, string $callbackUrl): array
    {
        $subscription->loadMissing('user');

        $response = Http::withToken($this->secret())
            ->acceptJson()
            ->timeout(20)
            ->post(self::BASE.'/transaction/initialize', [
                'email' => $subscription->user->email,
                'amount' => self::toKobo($subscription->amount),
                'currency' => 'NGN',
                'reference' => $subscription->payment_reference,
                'callback_url' => $callbackUrl,
                'metadata' => [
                    'subscription_reference' => $subscription->reference,
                    'plan' => $subscription->plan_name,
                    'trader_name' => $subscription->user->name,
                ],
            ]);

        if ($response->failed() || ! $response->json('status')) {
            Log::error('Paystack initialize failed', [
                'subscription' => $subscription->reference,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new RuntimeException($response->json('message') ?? 'Could not start the payment.');
        }

        return $response->json('data');
    }

    /**
     * Asks Paystack what actually happened to a reference.
     *
     * This is the only source of truth the app trusts. A trader returning to
     * the callback URL proves nothing — they can land there by typing it.
     */
    public function verify(string $reference): array
    {
        $response = Http::withToken($this->secret())
            ->acceptJson()
            ->timeout(20)
            ->get(self::BASE.'/transaction/verify/'.urlencode($reference));

        if ($response->failed() || ! $response->json('status')) {
            Log::warning('Paystack verify failed', [
                'reference' => $reference,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new RuntimeException($response->json('message') ?? 'Could not verify the payment.');
        }

        return $response->json('data');
    }

    /**
     * Confirms a webhook really came from Paystack.
     *
     * Compared with hash_equals so the check cannot be timed, and against the
     * raw request body — re-encoding the parsed JSON would change the bytes and
     * every signature would fail.
     */
    public function signatureIsValid(string $rawBody, ?string $signature): bool
    {
        if (blank($signature) || ! $this->configured()) {
            return false;
        }

        return hash_equals(
            hash_hmac('sha512', $rawBody, $this->secret()),
            $signature
        );
    }
}
