<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the team: a Paystack payment landed, or a trader says they have sent a
 * bank transfer and is waiting for someone to confirm it.
 */
class PaymentAlert extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  'paid'|'transfer_reported'  $kind
     */
    public function __construct(public Subscription $subscription, public string $kind) {}

    public function envelope(): Envelope
    {
        $who = $this->subscription->user->name;

        return new Envelope(
            subject: $this->kind === 'paid'
                ? "Payment received — {$who}, {$this->subscription->plan_name}"
                : "Transfer to confirm — {$who}, {$this->subscription->plan_name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.payment-alert',
            with: [
                'adminUrl' => config('app.frontend_url').'/admin/subscriptions/'.$this->subscription->id,
            ],
        );
    }
}
