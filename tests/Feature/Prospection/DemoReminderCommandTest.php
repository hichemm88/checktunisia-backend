<?php

namespace Tests\Feature\Prospection;

use App\Models\Prospection\Establishment;
use App\Models\Prospection\ProspectionUser;
use App\Services\Prospection\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Prospection\FakeWebPushSender;
use Tests\TestCase;

class DemoReminderCommandTest extends TestCase
{
    use RefreshDatabase;

    private function subscribedUser(bool $enabled = true): ProspectionUser
    {
        return ProspectionUser::create([
            'name' => 'Hichem', 'email' => 'hichem@qayed.tn', 'password' => Hash::make('x'),
            'notif_demo_reminder_enabled' => $enabled,
        ]);
    }

    public function test_notifies_everyone_enabled_about_a_demo_within_the_hour(): void
    {
        $user = $this->subscribedUser();
        $establishment = Establishment::create([
            'name' => 'Dar Zaghouan', 'status' => 'demo_planifiee', 'next_action_at' => now()->addMinutes(30),
        ]);

        $fake = new FakeWebPushSender;
        $this->app->instance(WebPushSender::class, $fake);

        $this->artisan('prospection:send-demo-reminders')->assertSuccessful();

        $this->assertSame([$user->id], $fake->recipientIds());
        $this->assertNotNull($establishment->fresh()->demo_reminder_sent_at);
    }

    public function test_does_not_notify_a_user_who_disabled_demo_reminders(): void
    {
        $this->subscribedUser(enabled: false);
        Establishment::create([
            'name' => 'Dar Zaghouan', 'status' => 'demo_planifiee', 'next_action_at' => now()->addMinutes(30),
        ]);

        $fake = new FakeWebPushSender;
        $this->app->instance(WebPushSender::class, $fake);

        $this->artisan('prospection:send-demo-reminders')->assertSuccessful();

        $this->assertSame([], $fake->recipientIds());
    }

    public function test_does_not_remind_twice_for_the_same_demo(): void
    {
        $this->subscribedUser();
        Establishment::create([
            'name' => 'Dar Zaghouan', 'status' => 'demo_planifiee',
            'next_action_at' => now()->addMinutes(30), 'demo_reminder_sent_at' => now()->subMinutes(5),
        ]);

        $fake = new FakeWebPushSender;
        $this->app->instance(WebPushSender::class, $fake);

        $this->artisan('prospection:send-demo-reminders')->assertSuccessful();

        $this->assertSame([], $fake->recipientIds());
    }

    public function test_ignores_demos_outside_the_one_hour_window(): void
    {
        $this->subscribedUser();
        Establishment::create([
            'name' => 'Demain', 'status' => 'demo_planifiee', 'next_action_at' => now()->addDay(),
        ]);
        Establishment::create([
            'name' => 'Déjà passée', 'status' => 'demo_planifiee', 'next_action_at' => now()->subMinutes(5),
        ]);

        $fake = new FakeWebPushSender;
        $this->app->instance(WebPushSender::class, $fake);

        $this->artisan('prospection:send-demo-reminders')->assertSuccessful();

        $this->assertSame([], $fake->recipientIds());
    }

    public function test_rescheduling_a_demo_through_the_api_clears_the_reminder_flag(): void
    {
        $admin = ProspectionUser::create([
            'name' => 'Hichem', 'email' => 'admin@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'), 'role' => 'admin',
        ]);
        $token = $this->postJson('/api/v1/prospection/auth/login', [
            'email' => $admin->email, 'password' => 'un-mot-de-passe-solide',
        ])->json('data.token');

        $establishment = Establishment::create([
            'name' => 'Dar Zaghouan', 'status' => 'demo_planifiee',
            'next_action_at' => now()->addMinutes(30), 'demo_reminder_sent_at' => now()->subMinutes(5),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/prospection/establishments/{$establishment->id}", [
                'next_action_at' => now()->addHours(3)->toIso8601String(),
            ])
            ->assertOk();

        $this->assertNull($establishment->fresh()->demo_reminder_sent_at);
    }
}
