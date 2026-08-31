<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\User;
use App\Models\WhatsappSendLog;
use App\Services\Whatsapp\FicheTemplate;
use App\Services\Whatsapp\WhatsappCloudErrors;
use App\Services\Whatsapp\WhatsappOutboxService;
use App\Services\Whatsapp\WhatsappSendingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Migration vers la WhatsApp Cloud API officielle.
 *
 * Le relais WhatsApp Web a été banni : ce chemin-ci est désormais le SEUL par
 * lequel une fiche de police atteint l'autorité. Ce qui est vérifié ici n'est
 * donc pas « la fonctionnalité marche », mais les quatre façons dont elle
 * pourrait échouer sans qu'on le voie :
 *
 *  - une fiche part vers moins de destinataires qu'avant ;
 *  - l'arriéré du bannissement repart d'un coup et fait bannir le nouveau
 *    numéro à son tour ;
 *  - un accusé de réception falsifié fait passer pour transmise une fiche qui
 *    ne l'a jamais été ;
 *  - une erreur définitive est retentée 24 h durant au lieu d'alerter.
 */
class WhatsappCloudApiTest extends TestCase
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
            'whatsapp.channel' => 'cloud',
            'whatsapp.recipient' => '21600000000@c.us',
            'whatsapp.direct_routing' => true,
            'whatsapp.cloud.token' => 'test-token',
            'whatsapp.cloud.phone_number_id' => '123456',
            'whatsapp.cloud.waba_id' => 'waba-1',
            'whatsapp.cloud.app_secret' => 'app-secret',
            'whatsapp.cloud.webhook_verify_token' => 'verify-me',
            'whatsapp.guard.sending_enabled' => true,
            // Bascule dans le passé : le comportement nominal.
            'whatsapp.guard.cutover_at' => now()->subDay()->toIso8601String(),
        ]);
    }

    // ── Construction du message ──────────────────────────────────────────────

    public function test_a_fiche_is_sent_as_an_approved_template_not_free_text(): void
    {
        $this->fakeAccepted();
        $this->completeCheckIn();

        app(WhatsappOutboxService::class)->dispatchPending();

        Http::assertSent(function ($request) {
            // Le texte libre n'aboutit que dans une fenêtre de 24 h ouverte par
            // un message ENTRANT. Personne ne répond à une fiche de police :
            // la fenêtre est toujours fermée, seul le modèle passe.
            $this->assertSame('template', $request['type']);
            $this->assertSame('fiche_police_nouvelle', $request['template']['name']);

            return true;
        });
    }

    public function test_template_components_carry_header_body_and_button(): void
    {
        $this->fakeAccepted();
        $this->completeCheckIn();

        app(WhatsappOutboxService::class)->dispatchPending();

        Http::assertSent(function ($request) {
            $byType = collect($request['template']['components'])->keyBy('type');

            $this->assertCount(1, $byType['header']['parameters']);
            $this->assertCount(FicheTemplate::BODY_VARIABLES, $byType['body']['parameters']);

            // Le bouton porte le SUFFIXE de l'URL, pas l'URL entière : la base
            // est figée dans le modèle approuvé chez Meta.
            $this->assertSame('url', $byType['button']['sub_type']);
            $this->assertSame('0', $byType['button']['index']);
            $this->assertCount(1, $byType['button']['parameters']);

            return true;
        });
    }

    public function test_template_variables_never_contain_line_breaks(): void
    {
        $hotel = Hotel::factory()->withActiveSubscription()->create(['name' => "Dar\nMultiligne"]);
        $checkIn = CheckIn::factory()->for($hotel)->draft()->withGuest('Sara', 'Trabelsi')->create([
            'created_by' => User::factory()->receptionist($hotel)->create()->id,
        ]);
        $checkIn->load(['hotel.address', 'room', 'guests.documents']);

        $params = FicheTemplate::params($checkIn, $checkIn->guests->first());

        // Meta rejette (132012) toute variable contenant un saut de ligne, une
        // tabulation ou plus de quatre espaces consécutifs. Une fiche perdue
        // pour un espace en trop, c'est une fiche perdue quand même.
        foreach (array_merge($params['header'], $params['body']) as $value) {
            $this->assertDoesNotMatchRegularExpression('/[\r\n\t]|\s{5,}/u', $value);
            $this->assertNotSame('', $value, 'Meta refuse une variable vide.');
        }
    }

    // ── Multi-destinataires ──────────────────────────────────────────────────

    public function test_each_recipient_gets_its_own_send_and_its_own_wamid(): void
    {
        $this->hotel->whatsappRecipientProfiles()->sync([
            $this->authorityRecipient('21611111111')->id,
            $this->authorityRecipient('21622222222')->id,
        ]);

        $wamids = ['wamid.A', 'wamid.B'];
        Http::fake(function () use (&$wamids) {
            return Http::response(['messages' => [['id' => array_shift($wamids)]]], 200);
        });

        $this->completeCheckIn();

        // Une fiche par (voyageur × destinataire) : le comportement historique
        // du relais, conservé tel quel.
        $this->assertSame(2, WhatsappSendLog::count());

        app(WhatsappOutboxService::class)->dispatchPending();

        $jobs = WhatsappSendLog::orderBy('recipient')->get();
        $this->assertSame(['21611111111', '21622222222'], $jobs->pluck('recipient')->all());

        // Un identifiant DISTINCT par envoi : c'est ce qui permet au webhook de
        // dire lequel des deux policiers a réellement reçu la fiche. L'ordre
        // d'attribution n'a pas d'importance (deux fiches enfilées dans la même
        // seconde), l'unicité en a une.
        $ids = $jobs->pluck('message_id_whatsapp')->sort()->values()->all();
        $this->assertSame(['wamid.A', 'wamid.B'], $ids);
    }

    public function test_one_unreachable_recipient_does_not_block_the_others(): void
    {
        $this->hotel->whatsappRecipientProfiles()->sync([
            $this->authorityRecipient('21611111111')->id,
            $this->authorityRecipient('21622222222')->id,
        ]);

        $responses = [
            Http::response(['error' => ['code' => 131026, 'message' => 'Receiver incapable']], 400),
            Http::response(['messages' => [['id' => 'wamid.OK']]], 200),
        ];
        // Fermeture classique et reference : une fonction flechee capture par
        // VALEUR, chaque appel renverrait alors la meme premiere reponse.
        Http::fake(function () use (&$responses) {
            return array_shift($responses);
        });

        $this->completeCheckIn();
        app(WhatsappOutboxService::class)->dispatchPending();

        $jobs = WhatsappSendLog::orderBy('recipient')->get();

        $this->assertSame(WhatsappSendLog::STATUS_FAILED, $jobs[0]->status);
        // Le second est parti malgré l'échec du premier : c'est tout l'intérêt
        // d'un job par destinataire.
        $this->assertSame(WhatsappSendLog::STATUS_SENT, $jobs[1]->status);
        $this->assertSame('wamid.OK', $jobs[1]->message_id_whatsapp);
    }

    // ── Traduction des erreurs Meta ──────────────────────────────────────────

    public function test_meta_error_codes_decide_whether_to_retry(): void
    {
        // Fenêtre fermée, destinataire injoignable, paramètre invalide : rien
        // ne changera en réessayant.
        $this->assertFalse(WhatsappCloudErrors::isRetryable(131047, 400));
        $this->assertFalse(WhatsappCloudErrors::isRetryable(131026, 400));
        $this->assertFalse(WhatsappCloudErrors::isRetryable(131049, 400));
        $this->assertFalse(WhatsappCloudErrors::isRetryable(100, 400));

        // Limitation de débit et panne Meta : réessayer a un sens. Noter que
        // Meta renvoie ces cas-là en 400 — le code prime sur le statut HTTP.
        $this->assertTrue(WhatsappCloudErrors::isRetryable(130429, 400));
        $this->assertTrue(WhatsappCloudErrors::isRetryable(80007, 400));
        $this->assertTrue(WhatsappCloudErrors::isRetryable(null, 503));

        // Jeton mort : ce n'est pas la fiche qui a échoué, c'est le canal.
        $this->assertTrue(WhatsappCloudErrors::isCritical(190));
        $this->assertFalse(WhatsappCloudErrors::isCritical(131026));

        // Meta vient de dire « trop » : se taire, et pas seulement pour ce job.
        $this->assertTrue(WhatsappCloudErrors::triggersGlobalPause(131049));
        $this->assertTrue(WhatsappCloudErrors::triggersGlobalPause(80007));
        $this->assertFalse(WhatsappCloudErrors::triggersGlobalPause(131026));
    }

    public function test_a_permanent_error_fails_immediately_instead_of_retrying_for_24h(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(
            ['error' => ['code' => 131026, 'message' => 'Receiver incapable']], 400
        )]);

        $this->completeCheckIn();
        app(WhatsappOutboxService::class)->dispatchPending();

        $job = WhatsappSendLog::first();

        // Repasser en file 24 h durant ne ferait que retarder de 24 h l'alerte
        // qui aurait dû partir tout de suite.
        $this->assertSame(WhatsappSendLog::STATUS_FAILED, $job->status);
        $this->assertSame('131026', $job->error_code);
        $this->assertNull($job->next_attempt_at);
    }

    public function test_a_rate_limit_error_pauses_every_send_not_just_this_one(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(
            ['error' => ['code' => 131049, 'message' => 'Held for quality']], 400
        )]);

        $this->completeCheckIn();
        app(WhatsappOutboxService::class)->dispatchPending();

        // Insister quand Meta signale un problème de débit ou de qualité est
        // exactement ce qui transforme une limitation en bannissement.
        $this->assertNotNull(app(WhatsappSendingGuard::class)->pausedUntil());
    }

    // ── Garde-fous ───────────────────────────────────────────────────────────

    public function test_nothing_is_sent_while_the_cutover_is_not_armed(): void
    {
        config(['whatsapp.guard.cutover_at' => null]);
        Http::fake();

        $this->completeCheckIn();
        app(WhatsappOutboxService::class)->dispatchPending();

        // Défaut volontairement paralysant : une bascule ne doit pas s'armer
        // toute seule au déploiement.
        Http::assertNothingSent();
    }

    public function test_the_kill_switch_stops_sending_without_losing_anything(): void
    {
        config(['whatsapp.guard.sending_enabled' => false]);
        Http::fake();

        $this->completeCheckIn();
        $result = app(WhatsappOutboxService::class)->dispatchPending();

        Http::assertNothingSent();
        $this->assertNotNull($result['blocked']);
        // Rien n'est perdu : la fiche attend.
        $this->assertSame(WhatsappSendLog::STATUS_PENDING, WhatsappSendLog::first()->status);
    }

    public function test_the_pre_cutover_backlog_is_cancelled_never_sent(): void
    {
        Http::fake();
        $this->completeCheckIn();

        // Une fiche de l'arriéré : enfilée avant la bascule.
        $job = WhatsappSendLog::first();
        $job->forceFill(['created_at' => now()->subMonth()])->save();

        app(WhatsappOutboxService::class)->dispatchPending();

        Http::assertNothingSent();

        $job->refresh();
        $this->assertSame(WhatsappSendLog::STATUS_CANCELLED, $job->status);
        $this->assertSame(WhatsappOutboxService::PRE_CUTOVER_REASON, $job->error_code);
    }

    public function test_a_manual_resend_cannot_revive_the_pre_cutover_backlog(): void
    {
        Http::fake();
        $this->completeCheckIn();

        $job = WhatsappSendLog::first();
        $job->forceFill([
            'created_at' => now()->subMonth(),
            'status' => WhatsappSendLog::STATUS_FAILED,
        ])->save();

        // « Relancer tout » est le chemin par lequel l'arriéré repartirait le
        // plus facilement : un clic, plusieurs centaines de fiches de séjours
        // terminés vers des officiels.
        app(WhatsappOutboxService::class)->resendAllFailed();

        $this->assertSame(WhatsappSendLog::STATUS_CANCELLED, $job->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_an_abnormal_backlog_holds_sending_back_instead_of_flushing_it(): void
    {
        config(['whatsapp.guard.backlog_alert_threshold' => 2]);
        Http::fake();

        foreach (range(1, 4) as $i) {
            WhatsappSendLog::create([
                'hotel_id' => $this->hotel->id,
                'recipient' => '2161111111'.$i,
                'caption' => 'Fiche '.$i,
                'status' => WhatsappSendLog::STATUS_PENDING,
                'next_attempt_at' => now(),
                'queued_at' => now(),
            ]);
        }

        $result = app(WhatsappOutboxService::class)->dispatchPending();

        // Un arriéré n'est pas un volume de travail en retard : c'est le
        // symptôme d'une panne. Le vider automatiquement est ce qui a coûté le
        // numéro émetteur précédent.
        Http::assertNothingSent();
        $this->assertStringContainsString('Arriéré', (string) $result['blocked']);
    }

    public function test_the_per_minute_rate_limit_defers_instead_of_dropping(): void
    {
        config(['whatsapp.guard.max_sends_per_minute' => 1]);
        $this->fakeAccepted();

        $this->hotel->whatsappRecipientProfiles()->sync([
            $this->authorityRecipient('21611111111')->id,
            $this->authorityRecipient('21622222222')->id,
        ]);

        $this->completeCheckIn();
        $result = app(WhatsappOutboxService::class)->dispatchPending();

        $this->assertSame(1, $result['sent']);
        // La seconde attend le créneau suivant — elle n'est ni perdue ni en échec.
        $this->assertSame(1, WhatsappSendLog::where('status', WhatsappSendLog::STATUS_PENDING)->count());
    }

    // ── Webhook ──────────────────────────────────────────────────────────────

    public function test_the_verification_challenge_is_echoed_raw(): void
    {
        $this->getJson('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=1158201444')
            ->assertOk()
            // Encapsulé en JSON, Meta refuse l'URL : la réponse doit être le
            // défi et rien d'autre.
            ->assertSee('1158201444', false);
    }

    public function test_the_verification_challenge_is_refused_with_a_wrong_token(): void
    {
        $this->get('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=123')
            ->assertForbidden();
    }

    public function test_a_status_callback_updates_the_matching_send(): void
    {
        $job = $this->sentJob('wamid.DELIVERED');

        $this->postSigned($this->statusPayload('wamid.DELIVERED', 'delivered'))->assertOk();

        $job->refresh();
        $this->assertSame(WhatsappSendLog::DELIVERY_DELIVERED, $job->delivery_status);
        $this->assertNotNull($job->delivered_at);
        // L'envoi reste « envoyé » : c'est la LIVRAISON qui a progressé.
        $this->assertSame(WhatsappSendLog::STATUS_SENT, $job->status);
    }

    public function test_a_failed_delivery_reopens_the_send_and_records_the_meta_code(): void
    {
        $job = $this->sentJob('wamid.FAILED');

        $this->postSigned([
            'entry' => [['changes' => [['value' => ['statuses' => [[
                'id' => 'wamid.FAILED',
                'status' => 'failed',
                'errors' => [['code' => 131026, 'title' => 'Receiver incapable']],
            ]]]]]]],
        ])->assertOk();

        $job->refresh();
        // Une fiche jamais reçue ne doit pas rester affichée « envoyée » dans
        // le journal : sur un canal légal, c'est le pire des mensonges.
        $this->assertSame(WhatsappSendLog::DELIVERY_FAILED, $job->delivery_status);
        $this->assertSame(WhatsappSendLog::STATUS_FAILED, $job->status);
        $this->assertSame('131026', $job->error_code);
    }

    public function test_an_unsigned_callback_is_rejected_and_changes_nothing(): void
    {
        $job = $this->sentJob('wamid.X');

        $this->postJson('/api/v1/webhooks/whatsapp', $this->statusPayload('wamid.X', 'delivered'))
            ->assertStatus(401);

        // Sans signature, n'importe qui pourrait déclarer une fiche « remise »
        // — c'est-à-dire falsifier une preuve de transmission.
        $this->assertNull($job->fresh()->delivery_status);
    }

    public function test_a_tampered_body_is_rejected(): void
    {
        $job = $this->sentJob('wamid.Y');

        $signed = $this->statusPayload('wamid.Y', 'read');
        $signature = 'sha256='.hash_hmac('sha256', json_encode($signed), 'app-secret');

        // Corps modifié APRÈS signature : la signature ne colle plus.
        $tampered = $this->statusPayload('wamid.Y', 'delivered');

        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], json_encode($tampered))->assertStatus(401);

        $this->assertNull($job->fresh()->delivery_status);
    }

    public function test_the_webhook_refuses_everything_when_no_app_secret_is_configured(): void
    {
        config(['whatsapp.cloud.app_secret' => null]);
        $job = $this->sentJob('wamid.Z');

        // Mieux vaut ne rien traiter que traiter des accusés de réception non
        // authentifiés.
        $this->postJson('/api/v1/webhooks/whatsapp', $this->statusPayload('wamid.Z', 'delivered'))
            ->assertStatus(401);

        $this->assertNull($job->fresh()->delivery_status);
    }

    public function test_an_inbound_message_is_acknowledged_with_200(): void
    {
        // Meta rejoue toute livraison non acquittée, puis finit par désactiver
        // le webhook : un événement non géré doit quand même répondre 200.
        $this->postSigned([
            'entry' => [['changes' => [['value' => ['messages' => [[
                'id' => 'wamid.IN', 'from' => '21611111111', 'type' => 'text',
            ]]]]]]],
        ])->assertOk();
    }

    // ── Bout en bout ─────────────────────────────────────────────────────────

    public function test_full_flow_from_checkin_to_delivered(): void
    {
        $this->fakeAccepted('wamid.FLOW');
        $this->completeCheckIn();

        $job = WhatsappSendLog::first();
        $this->assertSame(WhatsappSendLog::STATUS_PENDING, $job->status);

        app(WhatsappOutboxService::class)->dispatchPending();

        $job->refresh();
        $this->assertSame(WhatsappSendLog::STATUS_SENT, $job->status);
        $this->assertSame('wamid.FLOW', $job->message_id_whatsapp);
        $this->assertSame('whatsapp_cloud', $job->channel);
        // « accepté » et non « remis » : Meta n'a encore fait que prendre en
        // charge le message.
        $this->assertSame(WhatsappSendLog::DELIVERY_ACCEPTED, $job->delivery_status);

        $this->postSigned($this->statusPayload('wamid.FLOW', 'delivered'))->assertOk();
        $this->postSigned($this->statusPayload('wamid.FLOW', 'read'))->assertOk();

        $job->refresh();
        $this->assertSame(WhatsappSendLog::DELIVERY_READ, $job->delivery_status);
        $this->assertNotNull($job->read_at);
    }

    // ── Aides ────────────────────────────────────────────────────────────────

    private function fakeAccepted(string $wamid = 'wamid.TEST'): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => $wamid]]], 200)]);
    }

    private function completeCheckIn(): CheckIn
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->withGuest('Sara', 'Trabelsi')->create([
            'created_by' => $this->receptionist->id,
        ]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")
            ->assertOk();

        return $checkIn;
    }

    private function authorityRecipient(string $number): AuthorityUserProfile
    {
        $org = AuthorityOrganization::create(['name' => 'Poste '.$number, 'type' => 'police', 'is_active' => true]);
        $user = User::factory()->create();
        $user->assignRole('authority_user');

        return AuthorityUserProfile::create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'whatsapp_number' => $number,
            'receives_whatsapp_fiches' => true,
            'authorized_at' => now(),
        ]);
    }

    private function sentJob(string $wamid): WhatsappSendLog
    {
        return WhatsappSendLog::create([
            'hotel_id' => $this->hotel->id,
            'recipient' => '21611111111',
            'caption' => 'Fiche',
            'status' => WhatsappSendLog::STATUS_SENT,
            'message_id_whatsapp' => $wamid,
            'queued_at' => now(),
            'sent_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function statusPayload(string $wamid, string $status): array
    {
        return ['entry' => [['changes' => [['value' => ['statuses' => [[
            'id' => $wamid,
            'status' => $status,
            'timestamp' => (string) now()->timestamp,
        ]]]]]]]];
    }

    /** @param  array<string,mixed>  $payload */
    private function postSigned(array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body);
    }
}
