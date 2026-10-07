<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the trader, once their plan is switched on. */
class SubscriptionActivated extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Subscription $subscription) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your {$this->subscription->plan_name} plan is active",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.subscription-activated',
            with: [
                'dashboardUrl' => config('app.frontend_url').'/dashboard',
            ],
        );
    }
}
