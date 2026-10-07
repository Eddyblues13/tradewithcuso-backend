<?php

namespace App\Support;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends mail without ever letting a mail problem reach the trader.
 *
 * A payment is a completed piece of business the moment it is written to the
 * database. If the mail host is down, the credentials are wrong or the address
 * bounces, the right outcome is a logged failure and a successful request —
 * not a 500 that leaves a trader believing their payment failed.
 *
 * Mail is sent inline rather than queued, deliberately: this app runs without
 * a queue worker, and a queued mail with nothing draining the queue is a mail
 * that silently never arrives. Adding `ShouldQueue` to the mailables is the
 * only change needed should that change.
 */
class Notifier
{
    /**
     * @param  string|array<int, string>|null  $to
     */
    public static function send(string|array|null $to, Mailable $mailable, string $context = 'mail'): bool
    {
        $recipients = array_values(array_filter((array) $to));

        if ($recipients === []) {
            return false;
        }

        try {
            Mail::to($recipients)->send($mailable);

            return true;
        } catch (\Throwable $e) {
            Log::error("Could not send {$context}", [
                'to' => $recipients,
                'mailable' => $mailable::class,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** The inbox admin alerts go to. */
    public static function team(): ?string
    {
        return config('app.notify_email');
    }
}
