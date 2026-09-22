<?php

namespace Tests\Feature\Prospection;

use App\Models\Prospection\ProspectionUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_can_update_their_own_notification_preferences_without_being_admin(): void
    {
        $member = ProspectionUser::create([
            'name' => 'Coéquipier', 'email' => 'membre@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'), 'role' => 'membre',
        ]);

        $token = $this->postJson('/api/v1/prospection/auth/login', [
            'email' => $member->email, 'password' => 'un-mot-de-passe-solide',
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/prospection/auth/me', [
                'notif_digest_enabled' => false,
                'notif_digest_hour' => '09:00',
                'notif_demo_reminder_enabled' => false,
                'notif_activity_enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.notifications.digest_enabled', false)
            ->assertJsonPath('data.notifications.digest_hour', '09:00');

        $member->refresh();
        $this->assertFalse($member->notif_digest_enabled);
        $this->assertSame('09:00', $member->notif_digest_hour);
        $this->assertFalse($member->notif_demo_reminder_enabled);
        $this->assertFalse($member->notif_activity_enabled);
    }

    public function test_an_invalid_hour_format_is_rejected(): void
    {
        $member = ProspectionUser::create([
            'name' => 'Coéquipier', 'email' => 'membre@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'), 'role' => 'membre',
        ]);

        $token = $this->postJson('/api/v1/prospection/auth/login', [
            'email' => $member->email, 'password' => 'un-mot-de-passe-solide',
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/prospection/auth/me', ['notif_digest_hour' => 'pas une heure'])
            ->assertStatus(422);
    }
}
