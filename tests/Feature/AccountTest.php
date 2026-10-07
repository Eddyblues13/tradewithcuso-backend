<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Trader',
            'email' => 'ada@example.com',
            'phone' => '+2348012345678',
            'password' => 'long-enough-password',
            'password_confirmation' => 'long-enough-password',
            'accept_terms' => true,
        ], $overrides);
    }

    public function test_a_trader_can_register_and_gets_a_token(): void
    {
        $this->postJson('/api/account/register', $this->registration())
            ->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'phone', 'current_subscription']])
            ->assertJsonPath('user.current_subscription', null);

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com', 'phone' => '+2348012345678']);
    }

    public function test_registration_requires_accepting_the_terms(): void
    {
        $this->postJson('/api/account/register', $this->registration(['accept_terms' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('accept_terms');
    }

    public function test_registration_rejects_a_duplicate_email_and_a_bad_phone(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/account/register', $this->registration(['phone' => 'call me']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'phone']);
    }

    public function test_a_trader_can_sign_in(): void
    {
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'long-enough-password']);

        $this->postJson('/api/account/login', [
            'email' => 'ada@example.com',
            'password' => 'long-enough-password',
        ])->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_the_same_message_is_given_for_an_unknown_email_and_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'long-enough-password']);

        $wrongPassword = $this->postJson('/api/account/login', ['email' => 'ada@example.com', 'password' => 'nope-nope-nope']);
        $unknownEmail = $this->postJson('/api/account/login', ['email' => 'who@example.com', 'password' => 'nope-nope-nope']);

        $this->assertSame($wrongPassword->json('errors.email'), $unknownEmail->json('errors.email'));
    }

    public function test_a_suspended_trader_cannot_sign_in_or_use_an_old_token(): void
    {
        $user = User::factory()->suspended()->create(['email' => 'ada@example.com', 'password' => 'long-enough-password']);

        $this->postJson('/api/account/login', [
            'email' => 'ada@example.com',
            'password' => 'long-enough-password',
        ])->assertStatus(422);

        $this->actingAs($user, 'customer')->getJson('/api/account/me')->assertForbidden();
    }

    public function test_a_trader_token_cannot_reach_the_dashboard(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('website')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/dashboard')->assertUnauthorized();
    }

    public function test_changing_password_requires_the_current_one(): void
    {
        $user = User::factory()->create(['password' => 'long-enough-password']);
        $token = $user->createToken('website')->plainTextToken;

        $this->withToken($token)->putJson('/api/account/password', [
            'current_password' => 'wrong-password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');
    }
}
