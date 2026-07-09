<?php

namespace Tests\Feature;

use App\Mail\SystemMail;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * "Forgot password" workflow — request link, no enumeration, token reset.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic: don't let the shared 5/min auth throttle bleed across tests.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_forgot_password_sends_branded_email_for_active_user(): void
    {
        Mail::fake();
        $hotel = Hotel::factory()->withActiveSubscription()->create();
        $user  = User::factory()->hotelAdmin($hotel)->create(['email' => 'reset.me@example.tn']);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'reset.me@example.tn'])
            ->assertOk();

        Mail::assertSent(SystemMail::class);
        $this->assertDatabaseHas('audit_logs', [
            'action'   => 'user.password_reset_requested',
            'actor_id' => $user->id,
        ]);
    }

    public function test_forgot_password_unknown_email_is_silent_and_sends_nothing(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'ghost@example.tn'])
            ->assertOk()
            ->assertJsonPath('data.message', 'If this email exists, a reset link has been sent.');

        Mail::assertNothingSent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset_requested_unknown']);
    }

    public function test_suspended_user_does_not_receive_reset_email(): void
    {
        Mail::fake();
        $hotel = Hotel::factory()->withActiveSubscription()->create();
        User::factory()->hotelAdmin($hotel)->create(['email' => 'susp@example.tn', 'status' => 'suspended']);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'susp@example.tn'])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_reset_password_with_valid_token_updates_the_password(): void
    {
        // The password rules call HaveIBeenPwned; fake HTTP so the check is offline-safe.
        Http::fake(['*' => Http::response('', 200)]);

        $hotel = Hotel::factory()->withActiveSubscription()->create();
        $user  = User::factory()->hotelAdmin($hotel)->create(['email' => 'reset2@example.tn']);
        $token = Password::createToken($user);

        $new = 'NewStr0ng!Passw0rd42';
        $this->postJson('/api/v1/auth/password/reset', [
            'email'                 => 'reset2@example.tn',
            'token'                 => $token,
            'password'              => $new,
            'password_confirmation' => $new,
        ])->assertOk();

        $this->assertTrue(Hash::check($new, $user->fresh()->password));
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $hotel = Hotel::factory()->withActiveSubscription()->create();
        User::factory()->hotelAdmin($hotel)->create(['email' => 'reset3@example.tn']);

        $new = 'NewStr0ng!Passw0rd42';
        $this->postJson('/api/v1/auth/password/reset', [
            'email'                 => 'reset3@example.tn',
            'token'                 => 'totally-invalid-token',
            'password'              => $new,
            'password_confirmation' => $new,
        ])->assertStatus(422);
    }
}
