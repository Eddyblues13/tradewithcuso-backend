<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_it_issues_a_token_for_valid_credentials(): void
    {
        Admin::factory()->create(['email' => 'owner@example.com']);

        $this->postJson('/api/admin/login', [
            'email' => 'owner@example.com',
            'password' => 'secret-password',
        ])->assertOk()->assertJsonStructure(['token', 'admin' => ['id', 'name', 'email', 'role']]);
    }

    public function test_the_dashboard_is_closed_to_anonymous_callers(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
    }

    public function test_a_deactivated_admin_is_locked_out(): void
    {
        $this->actingAs(Admin::factory()->inactive()->create(), 'admin')
            ->getJson('/api/admin/dashboard')
            ->assertForbidden();
    }

    public function test_a_manager_cannot_reach_team_management(): void
    {
        $this->actingAs(Admin::factory()->manager()->create(), 'admin')
            ->getJson('/api/admin/team')
            ->assertForbidden();
    }

    public function test_the_dashboard_counts_only_money_received_as_revenue(): void
    {
        Subscription::factory()->active()->create(['amount' => 60000, 'amount_paid' => 60000]);
        Subscription::factory()->create(['amount' => 25000]);
        // A complimentary place: active, but nothing paid.
        Subscription::factory()->active()->create(['amount' => 150000, 'amount_paid' => 0, 'payment_method' => 'manual']);

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.revenue_total', 60000)
            ->assertJsonPath('data.subscriptions_active', 2)
            ->assertJsonPath('data.subscriptions_pending', 1);
    }

    public function test_an_admin_can_create_a_plan_with_features_one_per_line(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin')
            ->postJson('/api/admin/plans', [
                'name' => 'VIP Signals',
                'price' => 45000,
                'duration_days' => 30,
                'features' => "Daily signals\n\nLive sessions\n",
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'vip-signals')
            ->assertJsonPath('data.features', ['Daily signals', 'Live sessions']);
    }

    public function test_a_plan_with_subscribers_cannot_be_deleted(): void
    {
        $plan = Plan::factory()->create();
        Subscription::factory()->for($plan)->create();

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->deleteJson("/api/admin/plans/{$plan->id}")
            ->assertStatus(422);

        $this->assertModelExists($plan);
    }

    public function test_confirming_a_transfer_activates_the_plan_and_records_who_did_it(): void
    {
        $admin = Admin::factory()->create();
        $subscription = Subscription::factory()->transferReported()->create(['amount' => 60000, 'duration_days' => 90]);

        $this->actingAs($admin, 'admin')
            ->patchJson("/api/admin/subscriptions/{$subscription->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.amount_paid', 60000)
            ->assertJsonPath('data.activated_by', $admin->name);
    }

    public function test_awaiting_transfers_can_be_filtered(): void
    {
        Subscription::factory()->transferReported()->create();
        Subscription::factory()->create();

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->getJson('/api/admin/subscriptions?state=awaiting')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_paid_subscription_cannot_be_deleted(): void
    {
        $subscription = Subscription::factory()->active()->create();

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->deleteJson("/api/admin/subscriptions/{$subscription->id}")
            ->assertStatus(422);
    }

    public function test_suspending_a_trader_revokes_their_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('website');

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->patchJson("/api/admin/users/{$user->id}/toggle")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_an_admin_can_grant_a_complimentary_plan(): void
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['price' => 150000, 'duration_days' => 180]);

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->postJson("/api/admin/users/{$user->id}/subscriptions", [
                'plan_id' => $plan->id,
                'amount_paid' => 0,
                'note' => 'Giveaway winner',
            ])
            ->assertCreated()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.payment_method', 'manual')
            ->assertJsonPath('data.amount_paid', 0);
    }

    public function test_a_trader_with_payments_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->for($user)->active()->create();

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->deleteJson("/api/admin/users/{$user->id}")
            ->assertStatus(422);
    }

    public function test_settings_are_saved_and_bank_details_unlock_transfers(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin')
            ->putJson('/api/admin/settings', [
                'bank_name' => 'Example Bank',
                'bank_account_number' => '0123456789',
                'bank_account_name' => 'TradeWithCuso Ltd',
            ])
            ->assertOk()
            ->assertJsonPath('data.bank_account_number', '0123456789');

        $this->getJson('/api/settings')->assertJsonPath('data.payment_methods.bank_transfer', true);
    }
}
