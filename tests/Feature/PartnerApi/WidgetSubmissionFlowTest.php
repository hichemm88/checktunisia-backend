<?php

namespace Tests\Feature\PartnerApi;

use App\Models\CheckIn;
use App\Models\CheckinUsageEvent;
use App\Models\FicheSession;
use App\Models\PartnerWebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * §3 — cycle de vie complet du widget : bootstrap → add/remove guest →
 * submit, en mode create ET amend, avec vérification des effets de bord
 * (quota, webhook) et du garde-fou du token de session opaque.
 */
class WidgetSubmissionFlowTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPartnerApi;

    private function createSession(array $fixture, string $bookingRef, array $guests = []): array
    {
        $create = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($fixture['hotel'], $bookingRef, $guests))
            ->assertSuccessful();

        return ['session_id' => $create->json('session_id'), 'widget_url' => $create->json('widget_url'), 'mode' => $create->json('mode')];
    }

    private function tokenFromWidgetUrl(string $widgetUrl): string
    {
        $query = parse_url($widgetUrl, PHP_URL_QUERY);
        parse_str((string) $query, $params);

        return $params['token'];
    }

    private function bootstrap(string $widgetUrl): string
    {
        $response = $this->getJson('/widget/v1/bootstrap?token='.$this->tokenFromWidgetUrl($widgetUrl))->assertOk();

        return $response->json('widget_token');
    }

    public function test_full_happy_path_create_mode_records_usage_once_and_enqueues_webhook(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $endpoint = $this->makeWebhookEndpoint($fixture['partner']);

        $created = $this->createSession($fixture, 'BK-HAPPY', [['first_name' => 'A']]);
        $this->assertSame('create', $created['mode']);
        $widgetToken = $this->bootstrap($created['widget_url']);

        $this->withHeaders($this->bearer($widgetToken))
            ->postJson('/widget/v1/guests', $this->widgetGuestPayload(['first_name' => 'Ahmed']))
            ->assertCreated();
        $this->withHeaders($this->bearer($widgetToken))
            ->postJson('/widget/v1/guests', $this->widgetGuestPayload(['first_name' => 'Sara', 'document' => [
                'type' => 'passport', 'document_number' => 'TN'.random_int(10000000, 99999999), 'issuing_country_code' => 'TUN',
            ]]))
            ->assertCreated();

        $submit = $this->withHeaders($this->bearer($widgetToken))
            ->postJson('/widget/v1/submit')
            ->assertOk();

        $this->assertSame(2, $submit->json('guest_count'));

        $session = FicheSession::findOrFail($created['session_id']);
        $checkIn = CheckIn::findOrFail($session->check_in_id);
        $this->assertSame('active', $checkIn->status);
        $this->assertSame(2, $checkIn->guests()->count());
        $this->assertSame(FicheSession::STATUS_SUBMITTED, $session->fresh()->status);

        // Quota side effect recorded exactly once.
        $this->assertSame(1, CheckinUsageEvent::where('check_in_id', $checkIn->id)->count());

        // Webhook enqueued for the configured endpoint.
        $delivery = PartnerWebhookDelivery::where('endpoint_id', $endpoint->id)
            ->where('event_type', 'fiche.submitted')
            ->first();
        $this->assertNotNull($delivery, 'A fiche.submitted delivery should have been enqueued.');
        $this->assertSame($session->id, $delivery->idempotency_key);
        $this->assertSame($checkIn->id, $delivery->payload['fiche_id']);
        $this->assertSame(2, $delivery->payload['guest_count']);
        $this->assertSame(PartnerWebhookDelivery::STATUS_PENDING, $delivery->status);
    }

    public function test_amend_mode_add_guest_and_submit_does_not_double_count_usage(): void
    {
        $fixture = $this->setUpLinkedPartner();

        // Original submission (mode=create) — one usage event.
        $created = $this->createSession($fixture, 'BK-AMEND-FLOW');
        $widgetToken = $this->bootstrap($created['widget_url']);
        $this->withHeaders($this->bearer($widgetToken))
            ->postJson('/widget/v1/guests', $this->widgetGuestPayload())
            ->assertCreated();
        $this->withHeaders($this->bearer($widgetToken))
            ->postJson('/widget/v1/submit')
            ->assertOk();

        $checkInId = FicheSession::findOrFail($created['session_id'])->check_in_id;
        $this->assertSame(1, CheckinUsageEvent::where('check_in_id', $checkInId)->count());

        // A second POST for the same booking_ref → mode=amend, same check-in.
        $amend = $this->createSession($fixture, 'BK-AMEND-FLOW');
        $this->assertSame('amend', $amend['mode']);
        $this->assertSame($created['session_id'] !== $amend['session_id'], true);

        $amendWidgetToken = $this->bootstrap($amend['widget_url']);
        $this->withHeaders($this->bearer($amendWidgetToken))
            ->postJson('/widget/v1/guests', $this->widgetGuestPayload(['first_name' => 'LateArrival', 'document' => [
                'type' => 'passport', 'document_number' => 'TN'.random_int(10000000, 99999999), 'issuing_country_code' => 'TUN',
            ]]))
            ->assertCreated();

        $amendSubmit = $this->withHeaders($this->bearer($amendWidgetToken))
            ->postJson('/widget/v1/submit')
            ->assertOk();

        $this->assertSame(2, $amendSubmit->json('guest_count'));
        $this->assertSame($checkInId, $amendSubmit->json('fiche_id'));

        // Still exactly ONE usage event across the original submission + amendment.
        $this->assertSame(1, CheckinUsageEvent::where('check_in_id', $checkInId)->count());
        $this->assertSame(1, CheckinUsageEvent::count());
    }

    public function test_can_remove_a_guest_before_submit(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $created = $this->createSession($fixture, 'BK-REMOVE');
        $widgetToken = $this->bootstrap($created['widget_url']);

        $g1 = $this->withHeaders($this->bearer($widgetToken))
            ->postJson('/widget/v1/guests', $this->widgetGuestPayload(['first_name' => 'Keep']))
            ->assertCreated()
            ->json('data.id');

        $g2 = $this->withHeaders($this->bearer($widgetToken))
            ->postJson('/widget/v1/guests', $this->widgetGuestPayload(['first_name' => 'Remove', 'is_primary' => false, 'document' => [
                'type' => 'passport', 'document_number' => 'TN'.random_int(10000000, 99999999), 'issuing_country_code' => 'TUN',
            ]]))
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->bearer($widgetToken))
            ->deleteJson("/widget/v1/guests/{$g2}")
            ->assertNoContent();

        $submit = $this->withHeaders($this->bearer($widgetToken))
            ->postJson('/widget/v1/submit')
            ->assertOk();

        $this->assertSame(1, $submit->json('guest_count'));
        $checkIn = CheckIn::findOrFail(FicheSession::findOrFail($created['session_id'])->check_in_id);
        $this->assertTrue($checkIn->guests()->where('guests.id', $g1)->exists());
        $this->assertFalse($checkIn->guests()->where('guests.id', $g2)->exists());
    }

    public function test_guests_endpoint_with_garbage_widget_token_returns_session_expired(): void
    {
        $this->seedWidgetJwtSecret();

        $this->withHeaders($this->bearer('not-a-real-widget-token'))
            ->postJson('/widget/v1/guests', $this->widgetGuestPayload())
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'session_expired');
    }

    public function test_submit_endpoint_with_expired_widget_token_returns_session_expired(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $created = $this->createSession($fixture, 'BK-EXPIRED-WT');
        $widgetToken = $this->bootstrap($created['widget_url']);

        $session = FicheSession::findOrFail($created['session_id']);
        $session->update(['widget_session_expires_at' => now()->subMinute()]);

        $this->withHeaders($this->bearer($widgetToken))
            ->postJson('/widget/v1/submit')
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'session_expired');
    }
}
