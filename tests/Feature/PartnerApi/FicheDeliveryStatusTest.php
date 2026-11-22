<?php

namespace Tests\Feature\PartnerApi;

use App\Http\Controllers\Admin\PartnerWebhookAdminController;
use App\Models\ApiKey;
use App\Models\CheckIn;
use App\Models\FicheSession;
use App\Models\Guest;
use App\Models\PartnerWebhookDelivery;
use App\Models\PartnerWebhookEndpoint;
use App\Models\User;
use App\Models\WhatsappSendLog;
use App\Services\PartnerApi\FicheDeliveryStatus;
use App\Services\Whatsapp\WhatsappOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerFiches;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * v1.2 — delivery_status (fiche, liste, sessions) et webhooks
 * fiche.delivered / fiche.delivery_failed.
 */
class FicheDeliveryStatusTest extends TestCase
{
    use BuildsPartnerFiches;
    use InteractsWithPartnerApi;
    use RefreshDatabase;

    private const NEW_EVENTS = ['fiche.delivered', 'fiche.delivery_failed'];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
    }

    /**
     * Fiche API soumise : session créée par la vraie route, puis brouillon
     * finalisé en base (le widget n'est pas l'objet de ces tests).
     *
     * @return array{session: FicheSession, checkIn: CheckIn}
     */
    private function submittedApiFiche(array $ctx, string $bookingRef, ?string $key = null, int $guests = 2): array
    {
        $res = $this->withHeaders($this->bearer($key ?? $ctx['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($ctx['hotel'], $bookingRef))
            ->assertCreated();

        $session = FicheSession::findOrFail($res->json('session_id'));
        $checkIn = CheckIn::findOrFail($session->check_in_id);

        for ($i = 0; $i < $guests; $i++) {
            $this->attachGuest($checkIn, Guest::factory()->create(), $i === 0);
        }

        $checkIn->update(['status' => 'active', 'completed_at' => now()]);
        $session->update(['status' => FicheSession::STATUS_SUBMITTED, 'submitted_at' => now()]);

        return ['session' => $session->fresh(), 'checkIn' => $checkIn->fresh()];
    }

    private function newEventDeliveries(?string $event = null)
    {
        return PartnerWebhookDelivery::query()
            ->whereIn('event_type', $event ? [$event] : self::NEW_EVENTS)
            ->get();
    }

    public function test_aggregation_rules(): void
    {
        $this->assertSame('sent', FicheDeliveryStatus::aggregate(['sent', 'sent']));
        $this->assertSame('pending', FicheDeliveryStatus::aggregate(['sent', 'pending']));
        $this->assertSame('failed', FicheDeliveryStatus::aggregate(['pending', 'failed', 'sent']));
        $this->assertSame('failed', FicheDeliveryStatus::aggregate(['sent', 'cancelled']));
    }

    public function test_delivery_status_is_exposed_in_fiche_and_both_session_responses(): void
    {
        $ctx = $this->setUpLinkedPartner();
        $this->enableDeliveryModule();
        ['session' => $session, 'checkIn' => $checkIn] = $this->submittedApiFiche($ctx, 'BK-DS-1');
        $auth = $this->bearer($ctx['plaintext']);

        $byRef = fn () => $this->withHeaders($auth)->getJson('/v1/fiche-sessions/by-booking-ref?'.http_build_query([
            'establishment_id' => $ctx['hotel']->id, 'booking_ref' => 'BK-DS-1',
        ]));

        // Validée, module actif, aucun envoi encore créé : pending.
        $this->withHeaders($auth)->getJson("/v1/fiches/{$checkIn->id}")->assertOk()->assertJsonPath('delivery_status', 'pending');
        $this->withHeaders($auth)->getJson("/v1/fiche-sessions/{$session->id}")->assertOk()->assertJsonPath('delivery_status', 'pending');
        $byRef()->assertOk()->assertJsonPath('delivery_status', 'pending');

        foreach ($checkIn->guests as $guest) {
            $this->makeSendLog($checkIn, WhatsappSendLog::STATUS_SENT, $guest);
        }

        $this->withHeaders($auth)->getJson("/v1/fiches/{$checkIn->id}")->assertOk()
            ->assertJsonPath('delivery_status', 'sent')
            ->assertJsonPath('authority_status', 'relayed');
        $this->withHeaders($auth)->getJson("/v1/fiche-sessions/{$session->id}")->assertOk()->assertJsonPath('delivery_status', 'sent');
        $byRef()->assertOk()->assertJsonPath('delivery_status', 'sent');
    }

    public function test_not_applicable_for_draft_test_mode_and_inactive_module(): void
    {
        $ctx = $this->setUpLinkedPartner();
        $this->enableDeliveryModule();
        $auth = $this->bearer($ctx['plaintext']);

        // Brouillon (session créée, jamais soumise).
        $draft = $this->withHeaders($auth)
            ->postJson('/v1/fiche-sessions', $this->fichePayload($ctx['hotel'], 'BK-DRAFT'))
            ->assertCreated();
        $this->withHeaders($auth)->getJson('/v1/fiche-sessions/'.$draft->json('session_id'))
            ->assertOk()->assertJsonPath('delivery_status', 'not_applicable');

        // Mode test.
        ['plaintext' => $testKey] = $this->issuePartnerKey($ctx['partner'], ApiKey::MODE_TEST);
        ['session' => $testSession, 'checkIn' => $testCheckIn] = $this->submittedApiFiche($ctx, 'BK-TESTMODE', $testKey);
        $this->assertTrue((bool) $testCheckIn->metadata['test_mode']);
        $this->withHeaders($this->bearer($testKey))->getJson("/v1/fiche-sessions/{$testSession->id}")
            ->assertOk()->assertJsonPath('delivery_status', 'not_applicable');
        $this->withHeaders($this->bearer($testKey))->getJson("/v1/fiches/{$testCheckIn->id}")
            ->assertOk()->assertJsonPath('delivery_status', 'not_applicable');

        // Module d'envoi inactif, aucun envoi journalisé.
        ['checkIn' => $live] = $this->submittedApiFiche($ctx, 'BK-NOMODULE');
        $this->disableDeliveryModule();
        $this->withHeaders($auth)->getJson("/v1/fiches/{$live->id}")
            ->assertOk()->assertJsonPath('delivery_status', 'not_applicable');
    }

    public function test_delivered_webhook_is_emitted_once_in_live_when_every_send_succeeded(): void
    {
        $ctx = $this->setUpLinkedPartner();
        $this->enableDeliveryModule();
        $this->makeWebhookEndpoint($ctx['partner'], ['events' => PartnerWebhookAdminController::EVENTS]);
        ['session' => $session, 'checkIn' => $checkIn] = $this->submittedApiFiche($ctx, 'BK-WH-OK');

        [$first, $second] = $checkIn->guests->map(fn ($g) => $this->makeSendLog($checkIn, WhatsappSendLog::STATUS_PENDING, $g))->all();
        $outbox = app(WhatsappOutboxService::class);

        $outbox->markSent($first, 'wamid.first');
        $this->assertCount(0, $this->newEventDeliveries(), 'un envoi encore en file : pas de fiche.delivered');

        $outbox->markSent($second, 'wamid.second');
        $deliveries = $this->newEventDeliveries();
        $this->assertCount(1, $deliveries);
        $this->assertSame('fiche.delivered', $deliveries[0]->event_type);

        // Rejeu (accusé tardif, sauvegarde redondante) : toujours un seul événement.
        $second->refresh()->update(['status' => WhatsappSendLog::STATUS_PENDING]);
        $second->refresh()->update(['status' => WhatsappSendLog::STATUS_SENT]);
        $outbox->recordDeliveryUpdate($first->fresh(), WhatsappSendLog::DELIVERY_DELIVERED);
        $this->assertCount(1, $this->newEventDeliveries());

        $payload = $deliveries[0]->payload;
        $this->assertEqualsCanonicalizing(
            ['fiche_id', 'session_id', 'booking_ref', 'establishment_id', 'delivery_status', 'occurred_at'],
            array_keys($payload),
        );
        $this->assertSame($checkIn->id, $payload['fiche_id']);
        $this->assertSame($session->id, $payload['session_id']);
        $this->assertSame('BK-WH-OK', $payload['booking_ref']);
        $this->assertSame('sent', $payload['delivery_status']);
    }

    public function test_delivery_failed_webhook_on_definitive_failure_only_once(): void
    {
        $ctx = $this->setUpLinkedPartner();
        $this->enableDeliveryModule();
        $this->makeWebhookEndpoint($ctx['partner'], ['events' => PartnerWebhookAdminController::EVENTS]);
        ['checkIn' => $checkIn] = $this->submittedApiFiche($ctx, 'BK-WH-KO');

        [$first, $second] = $checkIn->guests->map(fn ($g) => $this->makeSendLog($checkIn, WhatsappSendLog::STATUS_PENDING, $g))->all();
        $outbox = app(WhatsappOutboxService::class);

        // Échec temporaire (reprise prévue) : rien.
        $outbox->markFailed($first, 'timeout', retryable: true);
        $this->assertSame(WhatsappSendLog::STATUS_PENDING, $first->fresh()->status);
        $this->assertCount(0, $this->newEventDeliveries());

        // Échec définitif : un fiche.delivery_failed, même si l'autre envoi aboutit.
        $outbox->markFailed($first->fresh(), 'numero invalide', retryable: false, errorCode: '131026');
        $outbox->markSent($second, 'wamid.ok');
        $outbox->dismissAllFailed();

        $deliveries = $this->newEventDeliveries();
        $this->assertCount(1, $deliveries);
        $this->assertSame('fiche.delivery_failed', $deliveries[0]->event_type);
        $this->assertSame('failed', $deliveries[0]->payload['delivery_status']);
        $this->assertSame('transmission_failed', $deliveries[0]->payload['reason']);

        // Le motif ne recopie jamais le texte d'erreur du canal.
        $this->assertStringNotContainsString('numero invalide', json_encode($deliveries[0]->payload));
        $this->assertArrayNotHasKey('recipient', $deliveries[0]->payload);
    }

    public function test_no_webhook_in_test_mode_for_native_fiches_or_unsubscribed_endpoints(): void
    {
        $ctx = $this->setUpLinkedPartner();
        $this->enableDeliveryModule();
        $this->makeWebhookEndpoint($ctx['partner'], ['events' => PartnerWebhookAdminController::EVENTS]);

        // Mode test : même si des envois venaient à aboutir, aucun événement.
        ['plaintext' => $testKey] = $this->issuePartnerKey($ctx['partner'], ApiKey::MODE_TEST);
        ['checkIn' => $testCheckIn] = $this->submittedApiFiche($ctx, 'BK-WH-TEST', $testKey, guests: 1);
        $this->makeSendLog($testCheckIn)->update(['status' => WhatsappSendLog::STATUS_SENT]);

        // Fiche saisie dans Qayed : jamais d'événement partenaire.
        $native = $this->makeNativeFiche($ctx['hotel']);
        $this->makeSendLog($native)->update(['status' => WhatsappSendLog::STATUS_SENT]);
        $this->makeSendLog($native, WhatsappSendLog::STATUS_FAILED, null, 'autre@c.us');

        $this->assertCount(0, $this->newEventDeliveries());

        // Endpoint abonné aux seuls événements 1.0 (endpoint existant) : rien.
        PartnerWebhookDelivery::query()->delete();
        PartnerWebhookEndpoint::query()->update(['events' => ['fiche.submitted', 'fiche.failed', 'session.expired']]);
        ['checkIn' => $live] = $this->submittedApiFiche($ctx, 'BK-WH-OLD', guests: 1);
        $this->makeSendLog($live)->update(['status' => WhatsappSendLog::STATUS_SENT]);
        $this->assertCount(0, $this->newEventDeliveries());
    }

    public function test_admin_accepts_new_events_and_subscribes_them_by_default(): void
    {
        $partner = $this->makePartner();
        $admin = User::factory()->platformAdmin()->create();
        $base = "/api/v1/admin/partners/{$partner->id}/webhooks";

        // Défaut : les 5 événements, anciens compris.
        $default = $this->actingAs($admin)->postJson($base, ['url' => 'https://partner.example.test/a'])
            ->assertCreated()->json('data.events');
        $this->assertEqualsCanonicalizing(
            ['fiche.submitted', 'fiche.failed', 'session.expired', 'fiche.delivered', 'fiche.delivery_failed'],
            $default,
        );

        // Liste explicite contenant un nouvel événement : acceptée.
        $this->actingAs($admin)->postJson($base, ['url' => 'https://partner.example.test/b', 'events' => ['fiche.submitted', 'fiche.delivery_failed']])
            ->assertCreated()->assertJsonPath('data.events', ['fiche.submitted', 'fiche.delivery_failed']);

        // Les anciennes listes restent valides ; un nom inconnu reste refusé.
        $this->actingAs($admin)->postJson($base, ['url' => 'https://partner.example.test/c', 'events' => ['fiche.submitted', 'fiche.failed', 'session.expired']])
            ->assertCreated();
        $this->actingAs($admin)->postJson($base, ['url' => 'https://partner.example.test/d', 'events' => ['fiche.inconnu']])
            ->assertStatus(422);
    }
}
