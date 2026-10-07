<?php

namespace Tests\Feature;

use App\Mail\PaymentAlert;
use App\Mail\SubscriptionActivated;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        Setting::store([
            'bank_name' => 'Example Bank',
            'bank_account_number' => '0123456789',
            'bank_account_name' => 'TradeWithCuso Ltd',
        ]);
    }

    private function enablePaystack(): void
    {
        config(['services.paystack.secret' => 'sk_test_secret']);
    }

    public function test_only_active_plans_are_listed_publicly(): void
    {
        Plan::factory()->create(['name' => 'Visible', 'slug' => 'visible']);
        Plan::factory()->inactive()->create(['name' => 'Hidden', 'slug' => 'hidden']);

        $this->getJson('/api/plans')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'visible');
    }

    public function test_settings_report_which_payment_methods_are_available(): void
    {
        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.payment_methods.bank_transfer', true)
            ->assertJsonPath('data.payment_methods.paystack', false)
            ->assertJsonMissingPath('data.bank_account_number');
    }

    public function test_choosing_a_plan_by_bank_transfer_creates_a_pending_bill_with_the_price_frozen(): void
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['slug' => 'pro', 'price' => 60000, 'duration_days' => 90]);

        $response = $this->actingAs($user, 'customer')->postJson('/api/account/subscriptions', [
            'plan' => 'pro',
            'payment_method' => 'bank_transfer',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.state', 'pending')
            ->assertJsonPath('data.amount', 60000)
            ->assertJsonPath('bank_transfer.account_number', '0123456789')
            ->assertJsonPath('payment_url', null);

        // A later price change does not touch the bill already issued.
        $plan->update(['price' => 99000]);

        $this->assertSame(60000, Subscription::first()->amount);
    }

    public function test_an_inactive_plan_cannot_be_chosen(): void
    {
        $user = User::factory()->create();
        Plan::factory()->inactive()->create(['slug' => 'old']);

        $this->actingAs($user, 'customer')->postJson('/api/account/subscriptions', [
            'plan' => 'old',
            'payment_method' => 'bank_transfer',
        ])->assertStatus(422)->assertJsonValidationErrors('plan');
    }

    public function test_card_payment_is_refused_when_paystack_is_not_configured(): void
    {
        $user = User::factory()->create();
        Plan::factory()->create(['slug' => 'pro']);

        $this->actingAs($user, 'customer')->postJson('/api/account/subscriptions', [
            'plan' => 'pro',
            'payment_method' => 'paystack',
        ])->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }

    public function test_choosing_again_closes_an_abandoned_bill_but_keeps_a_reported_transfer(): void
    {
        $user = User::factory()->create();
        Plan::factory()->create(['slug' => 'pro']);
        $abandoned = Subscription::factory()->for($user)->create();
        $reported = Subscription::factory()->for($user)->transferReported()->create();

        $this->actingAs($user, 'customer')->postJson('/api/account/subscriptions', [
            'plan' => 'pro',
            'payment_method' => 'bank_transfer',
        ])->assertCreated();

        $this->assertSame('cancelled', $abandoned->fresh()->status);
        $this->assertSame('pending', $reported->fresh()->status);
    }

    public function test_a_trader_cannot_see_someone_elses_subscription(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $subscription = Subscription::factory()->for($owner)->create();

        $this->actingAs($stranger, 'customer')
            ->getJson("/api/account/subscriptions/{$subscription->reference}")
            ->assertNotFound();
    }

    public function test_reporting_a_transfer_alerts_the_team_once(): void
    {
        config(['app.notify_email' => 'team@example.com']);

        $user = User::factory()->create();
        $subscription = Subscription::factory()->for($user)->create();

        $this->actingAs($user, 'customer')
            ->postJson("/api/account/subscriptions/{$subscription->reference}/transfer-sent")
            ->assertOk();
        $this->actingAs($user, 'customer')
            ->postJson("/api/account/subscriptions/{$subscription->reference}/transfer-sent")
            ->assertOk();

        $this->assertNotNull($subscription->fresh()->transfer_reported_at);
        Mail::assertSent(PaymentAlert::class, 1);
    }

    public function test_a_reported_transfer_can_no_longer_be_cancelled_by_the_trader(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->for($user)->transferReported()->create();

        $this->actingAs($user, 'customer')
            ->postJson("/api/account/subscriptions/{$subscription->reference}/cancel")
            ->assertStatus(422);
    }

    public function test_paying_by_card_returns_the_paystack_checkout_url(): void
    {
        $this->enablePaystack();

        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'reference' => 'x'],
            ]),
        ]);

        $user = User::factory()->create();
        Plan::factory()->create(['slug' => 'pro', 'price' => 60000]);

        $this->actingAs($user, 'customer')->postJson('/api/account/subscriptions', [
            'plan' => 'pro',
            'payment_method' => 'paystack',
        ])->assertCreated()->assertJsonPath('payment_url', 'https://checkout.paystack.com/abc');

        // Charged in kobo, from the server's price.
        Http::assertSent(fn ($request) => $request['amount'] === 6000000 && $request['currency'] === 'NGN');
    }

    public function test_a_verified_paystack_payment_activates_the_plan(): void
    {
        $this->enablePaystack();

        $user = User::factory()->create();
        $subscription = Subscription::factory()->for($user)->create([
            'amount' => 60000,
            'duration_days' => 90,
            'payment_method' => 'paystack',
            'payment_reference' => 'TWC-TEST-abc123',
        ]);

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'amount' => 6000000, 'currency' => 'NGN', 'channel' => 'card'],
            ]),
        ]);

        $this->actingAs($user, 'customer')
            ->postJson("/api/account/subscriptions/{$subscription->reference}/verify-payment")
            ->assertOk()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.amount_paid', 60000);

        $fresh = $subscription->fresh();
        $this->assertEqualsWithDelta(90, $fresh->starts_at->diffInDays($fresh->ends_at), 0.01);
        Mail::assertSent(SubscriptionActivated::class);
    }

    public function test_a_short_payment_is_recorded_but_does_not_activate(): void
    {
        $this->enablePaystack();

        $user = User::factory()->create();
        $subscription = Subscription::factory()->for($user)->create([
            'amount' => 60000,
            'payment_reference' => 'TWC-TEST-short',
        ]);

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'amount' => 100000, 'currency' => 'NGN', 'channel' => 'card'],
            ]),
        ]);

        $this->actingAs($user, 'customer')
            ->postJson("/api/account/subscriptions/{$subscription->reference}/verify-payment")
            ->assertOk()
            ->assertJsonPath('data.state', 'pending');

        $this->assertSame(1000, $subscription->fresh()->amount_paid);
    }

    public function test_the_webhook_rejects_a_bad_signature(): void
    {
        $this->enablePaystack();

        $this->postJson('/api/paystack/webhook', ['event' => 'charge.success'], ['x-paystack-signature' => 'forged'])
            ->assertUnauthorized();
    }

    public function test_a_signed_webhook_activates_the_plan_exactly_once(): void
    {
        $this->enablePaystack();
        config(['app.notify_email' => 'team@example.com']);

        $subscription = Subscription::factory()->create([
            'amount' => 25000,
            'payment_reference' => 'TWC-TEST-hook',
        ]);

        $body = json_encode([
            'event' => 'charge.success',
            'data' => ['reference' => 'TWC-TEST-hook', 'amount' => 2500000, 'currency' => 'NGN', 'channel' => 'bank'],
        ]);
        $signature = hash_hmac('sha512', $body, 'sk_test_secret');

        foreach ([1, 2] as $attempt) {
            $this->call('POST', '/api/paystack/webhook', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
            ], $body)->assertNoContent();
        }

        $this->assertSame('active', $subscription->fresh()->state());
        Mail::assertSent(SubscriptionActivated::class, 1);
    }

    public function test_renewing_early_queues_the_new_plan_after_the_current_one(): void
    {
        $this->enablePaystack();

        $user = User::factory()->create();
        $running = Subscription::factory()->for($user)->active(daysLeft: 10)->create();
        $renewal = Subscription::factory()->for($user)->create([
            'duration_days' => 30,
            'payment_reference' => 'TWC-TEST-renew',
        ]);

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'amount' => $renewal->amount * 100, 'currency' => 'NGN'],
            ]),
        ]);

        $this->actingAs($user, 'customer')
            ->postJson("/api/account/subscriptions/{$renewal->reference}/verify-payment")
            ->assertOk()
            ->assertJsonPath('data.state', 'scheduled');

        $this->assertTrue($renewal->fresh()->starts_at->equalTo($running->ends_at));
        $this->assertSame($running->id, $user->fresh()->currentSubscription()->id);
    }
}
