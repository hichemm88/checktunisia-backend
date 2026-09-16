<?php

namespace Tests\Feature\PartnerApi;

use App\Models\FicheSession;
use App\Models\PartnerWebhookDelivery;
use App\Services\PartnerApi\PartnerWebhookOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * §4 — livraison des webhooks partenaires : signature HMAC vérifiable,
 * backoff sur échec, désactivation auto de l'endpoint, redrive manuel.
 */
class PartnerWebhookDeliveryTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPartnerApi;

    private function submitAFiche(array $fixture, string $bookingRef): FicheSession
    {
        $create = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($fixture['hotel'], $bookingRef))
            ->assertCreated();

        $query = parse_url($create->json('widget_url'), PHP_URL_QUERY);
        parse_str((string) $query, $params);

        $bootstrap = $this->getJson('/widget/v1/bootstrap?token='.$params['token'])->assertOk();
        $widgetToken = $bootstrap->json('widget_token');

        $this->withHeaders($this->bearer($widgetToken))
            ->postJson('/widget/v1/guests', $this->widgetGuestPayload())
            ->assertCreated();
        $this->withHeaders($this->bearer($widgetToken))
            ->postJson('/widget/v1/submit')
            ->assertOk();

        return FicheSession::findOrFail($create->json('session_id'));
    }

    public function test_outbound_signature_header_matches_the_documented_hmac_recipe(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $fixture = $this->setUpLinkedPartner();
        $endpoint = $this->makeWebhookEndpoint($fixture['partner'], ['secret' => 'whsec_known_value']);
        $this->submitAFiche($fixture, 'BK-SIGNATURE');

        $sent = app(PartnerWebhookOutboxService::class)->dispatchPending();
        $this->assertSame(1, $sent['sent']);

        Http::assertSent(function ($request) use ($endpoint) {
            if ($request->url() !== $endpoint->url) {
                return false;
            }

            $header = $request->header('Qayed-Signature')[0] ?? null;
            $this->assertNotNull($header);

            if (! preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $header, $m)) {
                $this->fail("Qayed-Signature header does not match the documented format: {$header}");
            }

            [$fullMatch, $timestamp, $signature] = $m;

            $expected = hash_hmac('sha256', $timestamp.'.'.$request->body(), 'whsec_known_value');
            $this->assertSame($expected, $signature, 'Recomputed HMAC does not match the sent v1 signature.');

            return true;
        });
    }

    public function test_failing_endpoint_leaves_delivery_pending_with_backoff_scheduled(): void
    {
        config(['partner_api.webhooks.retry_schedule_minutes' => [1, 5, 15, 60, 240, 1440]]);
        Http::fake(['*' => Http::response('server error', 500)]);

        $fixture = $this->setUpLinkedPartner();
        $this->makeWebhookEndpoint($fixture['partner']);
        $this->submitAFiche($fixture, 'BK-FAIL');

        $before = now();
        app(PartnerWebhookOutboxService::class)->dispatchPending();

        $delivery = PartnerWebhookDelivery::where('event_type', 'fiche.submitted')->firstOrFail();
        $this->assertSame(PartnerWebhookDelivery::STATUS_PENDING, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(500, $delivery->last_response_status);
        $this->assertNotNull($delivery->next_attempt_at);
        $this->assertTrue($delivery->next_attempt_at->greaterThan($before));
        $this->assertTrue($delivery->next_attempt_at->lessThanOrEqualTo($before->copy()->addMinutes(2)));
    }

    public function test_endpoint_auto_disables_after_enough_consecutive_failures(): void
    {
        config([
            'partner_api.webhooks.retry_schedule_minutes' => [1, 1, 1, 1, 1],
            'partner_api.webhooks.auto_disable_after_failures' => 3,
        ]);
        Http::fake(['*' => Http::response('server error', 500)]);

        $fixture = $this->setUpLinkedPartner();
        $endpoint = $this->makeWebhookEndpoint($fixture['partner']);
        $this->submitAFiche($fixture, 'BK-AUTODISABLE');

        $outbox = app(PartnerWebhookOutboxService::class);

        for ($i = 0; $i < 3; $i++) {
            $outbox->dispatchPending();
            $this->travel(2)->minutes(); // make the job eligible for its next attempt
        }

        $endpoint->refresh();
        $this->assertSame(3, $endpoint->consecutive_failures);
        $this->assertFalse($endpoint->active, 'Endpoint should auto-disable after reaching auto_disable_after_failures.');
    }

    public function test_manual_redrive_resets_a_delivery(): void
    {
        config(['partner_api.webhooks.retry_schedule_minutes' => [1, 5, 15, 60, 240, 1440]]);
        Http::fake(['*' => Http::response('server error', 500)]);

        $fixture = $this->setUpLinkedPartner();
        $this->makeWebhookEndpoint($fixture['partner']);
        $this->submitAFiche($fixture, 'BK-REDRIVE');

        $outbox = app(PartnerWebhookOutboxService::class);
        $outbox->dispatchPending();

        $delivery = PartnerWebhookDelivery::where('event_type', 'fiche.submitted')->firstOrFail();
        $this->assertGreaterThan(0, $delivery->attempts);
        $this->assertNotNull($delivery->next_attempt_at);

        $outbox->redrive($delivery);
        $delivery->refresh();

        $this->assertSame(PartnerWebhookDelivery::STATUS_PENDING, $delivery->status);
        $this->assertSame(0, $delivery->attempts);
        $this->assertNull($delivery->claimed_at);
        $this->assertTrue($delivery->next_attempt_at->lessThanOrEqualTo(now()->addSecond()));
    }
}
