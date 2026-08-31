<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\User;
use App\Models\WhatsappSendLog;
use App\Services\Whatsapp\WhatsappOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Envoi direct (Phase 3) : un établissement AVEC des agents assignés envoie à
 * ceux-ci (1 fiche par voyageur × destinataire) ; SANS, il retombe sur le
 * numéro global. Interrupteur de secours whatsapp.direct_routing.
 */
class WhatsappDirectRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;
    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hotel = Hotel::factory()->withActiveSubscription()->create(['name' => 'Dar Test']);
        $this->receptionist = User::factory()->receptionist($this->hotel)->create();
        config([
            'whatsapp.enabled' => true,
            'whatsapp.recipient' => '21600000000@c.us',
            'whatsapp.direct_routing' => true,
            // Ces tests portent sur le relais WhatsApp Web (endpoints du worker,
            // format JID, session appairée). Le canal par défaut est désormais
            // « cloud » : sans ce pin, ils vérifieraient un tout autre chemin.
            'whatsapp.channel' => 'web',
        ]);
    }

    private function recipient(string $number, bool $receives = true): AuthorityUserProfile
    {
        $org = AuthorityOrganization::create(['name' => 'Poste '.$number, 'type' => 'police', 'is_active' => true]);
        $user = User::factory()->create();
        $user->assignRole('authority_user');

        return AuthorityUserProfile::create([
            'user_id' => $user->id, 'organization_id' => $org->id,
            'whatsapp_number' => $number, 'receives_whatsapp_fiches' => $receives,
            'authorized_at' => now(),
        ]);
    }

    public function test_no_recipient_falls_back_to_global_number(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->withGuest('Sara', 'Trabelsi')->create([
            'created_by' => $this->receptionist->id,
        ]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")->assertOk();

        $this->assertDatabaseCount('whatsapp_send_log', 1);
        $this->assertSame('21600000000@c.us', WhatsappSendLog::first()->recipient);
    }

    public function test_assigned_recipients_get_one_fiche_each(): void
    {
        $a = $this->recipient('21611111111');
        $b = $this->recipient('21622222222');
        $this->hotel->whatsappRecipientProfiles()->sync([$a->id, $b->id]);

        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->withGuest('Sara', 'Trabelsi')->create([
            'created_by' => $this->receptionist->id,
        ]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")->assertOk();

        // 1 voyageur × 2 destinataires = 2 fiches, aux bons JID, pas au global.
        $this->assertDatabaseCount('whatsapp_send_log', 2);
        $recipients = WhatsappSendLog::pluck('recipient')->sort()->values()->all();
        $this->assertSame(['21611111111@c.us', '21622222222@c.us'], $recipients);
    }

    public function test_only_receiving_recipients_are_used(): void
    {
        $a = $this->recipient('21611111111', receives: true);
        $b = $this->recipient('21622222222', receives: false); // ne reçoit pas
        $this->hotel->whatsappRecipientProfiles()->sync([$a->id, $b->id]);

        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->withGuest('Sara', 'Trabelsi')->create([
            'created_by' => $this->receptionist->id,
        ]);
        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")->assertOk();

        $this->assertDatabaseCount('whatsapp_send_log', 1);
        $this->assertSame('21611111111@c.us', WhatsappSendLog::first()->recipient);
    }

    public function test_kill_switch_forces_global(): void
    {
        config(['whatsapp.direct_routing' => false]);
        $a = $this->recipient('21611111111');
        $this->hotel->whatsappRecipientProfiles()->sync([$a->id]);

        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->withGuest('Sara', 'Trabelsi')->create([
            'created_by' => $this->receptionist->id,
        ]);
        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")->assertOk();

        $this->assertSame('21600000000@c.us', WhatsappSendLog::first()->recipient);
    }

    public function test_manager_can_view_and_select_recipients(): void
    {
        $manager = User::factory()->hotelAdmin($this->hotel)->create();
        $a = $this->recipient('21611111111');

        $this->actingAs($manager)
            ->getJson('/api/v1/hotel/whatsapp-recipients')
            ->assertOk()
            ->assertJsonPath('data.0.selected', false)
            ->assertJsonPath('data.0.number_masked', '••• ••• 111');

        $this->actingAs($manager)
            ->putJson('/api/v1/hotel/whatsapp-recipients', ['recipient_ids' => [$a->id]])
            ->assertOk()->assertJsonPath('data.count', 1);

        $this->assertDatabaseHas('hotel_whatsapp_recipients', [
            'hotel_id' => $this->hotel->id, 'authority_user_profile_id' => $a->id,
        ]);
    }

    public function test_receptionist_cannot_manage_recipients(): void
    {
        $this->actingAs($this->receptionist)
            ->getJson('/api/v1/hotel/whatsapp-recipients')->assertForbidden();
    }
}
