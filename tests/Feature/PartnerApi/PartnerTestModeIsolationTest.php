<?php

namespace Tests\Feature\PartnerApi;

use App\Models\ApiKey;
use App\Models\CheckIn;
use App\Models\CheckinUsageEvent;
use App\Models\FicheSession;
use App\Models\PartnerWebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * §7/§8 — isolation complète du mode test (clé qyd_test_…) : ni webhook, ni
 * consommation de quota pour une fiche créée en mode test.
 */
class PartnerTestModeIsolationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPartnerApi;

    public function test_test_mode_key_end_to_end_creates_no_webhook_and_no_quota_usage(): void
    {
        $this->seedWidgetJwtSecret();
        ['hotel' => $hotel] = $this->makeOrgWithHotel();
        $partner = $this->makePartner();
        ['plaintext' => $testKey] = $this->issuePartnerKey($partner, ApiKey::MODE_TEST);
        $this->linkEstablishment($partner, $hotel);
        $endpoint = $this->makeWebhookEndpoint($partner);

        $this->assertStringStartsWith('qyd_test_', $testKey);

        $create = $this->withHeaders($this->bearer($testKey))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($hotel, 'BK-TESTMODE'))
            ->assertCreated();

        $session = FicheSession::findOrFail($create->json('session_id'));
        $this->assertTrue($session->is_test);

        $query = parse_url($create->json('widget_url'), PHP_URL_QUERY);
        parse_str((string) $query, $params);
        $bootstrap = $this->getJson('/widget/v1/bootstrap?token='.$params['token'])->assertOk();
        $widgetToken = $bootstrap->json('widget_token');

        $this->withHeaders($this->bearer($widgetToken))
            ->postJson('/widget/v1/guests', $this->widgetGuestPayload())
            ->assertCreated();

        $submit = $this->withHeaders($this->bearer($widgetToken))
            ->postJson('/widget/v1/submit')
            ->assertOk();

        $checkIn = CheckIn::findOrFail($submit->json('fiche_id'));
        $this->assertSame('active', $checkIn->status);
        $this->assertTrue((bool) ($checkIn->metadata['test_mode'] ?? false));

        // No webhook delivery at all — WidgetSubmissionService::submit() skips
        // enqueueForSession('fiche.submitted', ...) entirely when is_test.
        $this->assertSame(0, PartnerWebhookDelivery::where('endpoint_id', $endpoint->id)->count());

        // No quota consumption either. This is NOT a gap: CheckInService::complete()
        // (app/Services/CheckIn/CheckInService.php) reads $checkIn->metadata['test_mode']
        // — set by FicheSessionService::createOrReuse() from $apiKey->isTestMode() —
        // and skips CheckinUsageRecorder::recordSafely() (and the WhatsApp relay
        // enqueue) whenever it is true. Verified here rather than assumed.
        $this->assertSame(0, CheckinUsageEvent::where('check_in_id', $checkIn->id)->count());
        $this->assertSame(0, CheckinUsageEvent::count());
    }
}
