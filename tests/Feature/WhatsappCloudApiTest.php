<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\User;
use App\Models\WhatsappSendLog;
use App\Models\WhatsappSessionState;
use App\Services\Whatsapp\FicheTemplate;
use App\Services\Whatsapp\WhatsappCloudConfig;
use App\Services\Whatsapp\WhatsappCloudErrors;
use App\Services\Whatsapp\WhatsappOutboxService;
use App\Services\Whatsapp\WhatsappSendingGuard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
            'whatsapp.cloud.app_id' => 'app-1',
            'whatsapp.cloud.app_secret' => 'app-secret',
            'whatsapp.cloud.webhook_verify_token' => 'verify-me',
            'whatsapp.guard.sending_enabled' => true,
            // Bascule dans le passé : le comportement nominal.
            'whatsapp.guard.cutover_at' => now()->subDay()->toIso8601String(),
            // Cadence neutralisée : 45 s entre deux envois rendraient la suite
            // inutilisable. Elle est vérifiée séparément, sur son propre calcul.
            'whatsapp.min_interval_seconds' => 0,
            'whatsapp.interval_jitter_ratio' => 0,
        ]);
    }

    // ── Construction du message ──────────────────────────────────────────────

    public function test_a_fiche_is_sent_as_an_approved_template_not_free_text(): void
    {
        $this->fakeAccepted();
        $this->completeCheckIn();

        app(WhatsappOutboxService::class)->dispatchPending();

        $this->assertTemplateSent(function ($request) {
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

        $this->assertTemplateSent(function ($request) {
            $byType = collect($request['template']['components'])->keyBy('type');

            // L'en-tête est un DOCUMENT — le PDF de la fiche, pièce d'identité
            // comprise — et non du texte : c'est ce qui rétablit la parité avec
            // le relais WhatsApp Web.
            $this->assertSame('document', $byType['header']['parameters'][0]['type']);
            $this->assertSame('media-1', $byType['header']['parameters'][0]['document']['id']);

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

        $params = FicheTemplate::params($checkIn, $checkIn->guests->first(), '01JTESTTOKEN');

        // Meta rejette (132012) toute variable contenant un saut de ligne, une
        // tabulation ou plus de quatre espaces consécutifs. Une fiche perdue
        // pour un espace en trop, c'est une fiche perdue quand même.
        foreach ($params['body'] as $value) {
            $this->assertDoesNotMatchRegularExpression('/[\r\n\t]|\s{5,}/u', $value);
            $this->assertNotSame('', $value, 'Meta refuse une variable vide.');
        }
    }

    public function test_the_fiche_pdf_travels_as_the_template_header(): void
    {
        $this->fakeAccepted();
        $this->completeCheckIn();

        app(WhatsappOutboxService::class)->dispatchPending();

        // Le PDF est téléversé AVANT le message : la Cloud API n'accepte
        // aucune pièce jointe en ligne.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/media'));

        $this->assertTemplateSent(function ($request) {
            $header = collect($request['template']['components'])->firstWhere('type', 'header');

            $this->assertSame('document', $header['parameters'][0]['type']);
            // Le nom de fichier est ce que le destinataire voit avant d'ouvrir,
            // dans un fil où toutes les fiches s'empilent.
            $this->assertStringContainsString('fiche-police', $header['parameters'][0]['document']['filename']);

            return true;
        });
    }

    public function test_a_refused_media_upload_is_temporary_never_a_lost_fiche(): void
    {
        Http::fake([
            '*/media' => Http::response(['error' => ['message' => 'Upload failed']], 500),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.X']]], 200),
        ]);

        $this->completeCheckIn();
        app(WhatsappOutboxService::class)->dispatchPending();

        $job = WhatsappSendLog::first();

        // Un /media momentanément indisponible ne dit RIEN de la fiche.
        // La marquer définitivement échouée la perdrait pour de bon.
        $this->assertSame(WhatsappSendLog::STATUS_PENDING, $job->status);
        $this->assertNotNull($job->next_attempt_at);

        // Et surtout : rien n'est parti sans sa pièce jointe.
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/messages'));
    }

    public function test_repeated_refusals_trip_the_circuit_breaker(): void
    {
        config(['whatsapp.circuit_breaker_failures' => 2]);

        $this->fakeMediaOkAndMessage(Http::response(
            ['error' => ['code' => 131026, 'message' => 'Receiver incapable']], 400
        ));

        // Deux destinataires, donc deux refus d'affilée.
        $this->hotel->whatsappRecipientProfiles()->sync([
            $this->authorityRecipient('21611111111')->id,
            $this->authorityRecipient('21622222222')->id,
        ]);

        $this->completeCheckIn();
        app(WhatsappOutboxService::class)->dispatchPending();

        // Plusieurs refus alors que l'API répond, c'est la signature d'une
        // restriction de compte : insister transforme une suspension de
        // quelques heures en bannissement. Le relais se coupe EN BASE, et la
        // reprise reste un geste humain.
        $this->assertTrue(WhatsappSessionState::current()->fresh()->paused);
    }

    public function test_the_hourly_ceiling_holds_the_queue_back(): void
    {
        config(['whatsapp.max_per_hour' => 1]);
        $this->fakeAccepted();

        $this->hotel->whatsappRecipientProfiles()->sync([
            $this->authorityRecipient('21611111111')->id,
            $this->authorityRecipient('21622222222')->id,
        ]);

        $this->completeCheckIn();
        app(WhatsappOutboxService::class)->dispatchPending();

        // Le plafond horaire se mesure sur le JOURNAL, pas sur un compteur en
        // mémoire : un processus qui redémarre ne repart pas de zéro.
        $this->assertSame(1, WhatsappSendLog::where('status', WhatsappSendLog::STATUS_SENT)->count());
        $this->assertSame(1, WhatsappSendLog::where('status', WhatsappSendLog::STATUS_PENDING)->count());
    }

    // ── Multi-destinataires ──────────────────────────────────────────────────

    public function test_each_recipient_gets_its_own_send_and_its_own_wamid(): void
    {
        $this->hotel->whatsappRecipientProfiles()->sync([
            $this->authorityRecipient('21611111111')->id,
            $this->authorityRecipient('21622222222')->id,
        ]);

        $wamids = ['wamid.A', 'wamid.B'];
        Http::fake([
            '*/media' => Http::response(['id' => 'media-1'], 200),
            '*/messages' => function () use (&$wamids) {
                return Http::response(['messages' => [['id' => array_shift($wamids)]]], 200);
            },
        ]);

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
        // Fermeture classique et référence : une fonction fléchée capture par
        // VALEUR, chaque appel renverrait alors la même première réponse.
        Http::fake([
            '*/media' => Http::response(['id' => 'media-1'], 200),
            '*/messages' => function () use (&$responses) {
                return array_shift($responses);
            },
        ]);

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
        $this->fakeMediaOkAndMessage(Http::response(
            ['error' => ['code' => 131026, 'message' => 'Receiver incapable']], 400
        ));

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
        $this->fakeMediaOkAndMessage(Http::response(
            ['error' => ['code' => 131049, 'message' => 'Held for quality']], 400
        ));

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

    // ── Configuration : une seule famille de noms ────────────────────────────

    public function test_the_config_reads_one_family_of_variable_names(): void
    {
        // Deux familles vivant en parallèle, ce sont deux endroits où poser un
        // jeton, un seul qui compte, et aucun moyen de savoir lequel est lu.
        // Ce test échoue si un repli WHATSAPP_CLOUD_* réapparaît.
        $source = file_get_contents(config_path('whatsapp.php'));

        preg_match_all("/env\('(WHATSAPP_[A-Z_]+)'/", $source, $matches);
        $read = array_unique($matches[1]);

        $legacy = array_values(array_filter(
            $read,
            fn ($name) => str_starts_with($name, 'WHATSAPP_CLOUD_')
                // Le nom du garde-fou de bascule est celui de la consigne
                // d'origine ; il ne fait partie d'aucune famille en double.
                && $name !== 'WHATSAPP_CLOUD_API_CUTOVER_AT',
        ));

        $this->assertSame([], $legacy, 'Repli sur d\'anciens noms de variables : '.implode(', ', $legacy));
    }

    public function test_missing_variables_are_named_not_guessed(): void
    {
        config([
            'whatsapp.cloud.token' => null,
            'whatsapp.cloud.app_secret' => null,
        ]);

        $missing = WhatsappCloudConfig::missing();

        // Le message doit nommer ce qui manque : « ça ne marche pas » oblige à
        // deviner, et on devine mal sous pression.
        $this->assertSame(['WHATSAPP_API_TOKEN', 'WHATSAPP_APP_SECRET'], $missing);
        $this->assertStringContainsString('WHATSAPP_API_TOKEN', WhatsappCloudConfig::explain($missing));
    }

    public function test_the_deployment_check_fails_when_a_variable_is_missing(): void
    {
        config(['whatsapp.cloud.token' => null]);

        // Le déploiement peut échouer sans conséquence pour personne : c'est
        // le bon endroit où être intransigeant.
        $this->artisan('whatsapp:check-config')
            ->expectsOutputToContain('WHATSAPP_API_TOKEN')
            ->assertExitCode(1);
    }

    public function test_the_deployment_check_passes_on_a_complete_config(): void
    {
        $this->artisan('whatsapp:check-config --admin')->assertExitCode(0);
    }

    public function test_the_deployment_check_is_silent_when_the_channel_is_not_armed(): void
    {
        // Inutile de crier sur un environnement qui n'envoie rien.
        config(['whatsapp.channel' => 'web', 'whatsapp.cloud.token' => null]);

        $this->artisan('whatsapp:check-config')->assertExitCode(0);
    }

    public function test_dispatch_refuses_and_names_what_is_missing(): void
    {
        config(['whatsapp.cloud.phone_number_id' => null]);
        Http::fake();

        $this->artisan('whatsapp:dispatch')
            ->expectsOutputToContain('WHATSAPP_PHONE_NUMBER_ID')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_admin_health_names_the_missing_variables_without_their_values(): void
    {
        config(['whatsapp.cloud.waba_id' => null]);

        $response = $this->actingAs(User::factory()->platformAdmin()->create())
            ->getJson('/api/v1/admin/whatsapp/health')
            ->assertOk();

        $this->assertContains('WHATSAPP_WABA_ID', $response->json('data.missing_config'));
        // Des noms, jamais des valeurs.
        $this->assertStringNotContainsString('test-token', $response->getContent());
    }

    // ── Lien « Consulter la fiche » ──────────────────────────────────────────

    public function test_the_button_points_at_the_redirect_route_not_at_a_screen(): void
    {
        $this->fakeAccepted();
        $this->completeCheckIn();

        app(WhatsappOutboxService::class)->dispatchPending();

        $token = WhatsappSendLog::first()->public_token;
        $this->assertNotNull($token);

        $this->assertTemplateSent(function ($request) use ($token) {
            $button = collect($request['template']['components'])->firstWhere('type', 'button');

            // Le bouton porte le JETON de l'envoi, pas l'identifiant du
            // voyageur : l'URL de base est figée chez Meta, elle ne doit
            // désigner aucune route applicative susceptible de changer.
            $this->assertSame($token, $button['parameters'][0]['text']);

            return true;
        });
    }

    public function test_the_link_redirects_to_the_authority_page(): void
    {
        $this->fakeAccepted();
        $checkIn = $this->completeCheckIn();

        $job = WhatsappSendLog::first();

        $this->get('/f/'.$job->public_token)
            ->assertRedirectContains('/authority/guests/'.$job->guest_id);

        // 302 et non 301 : la destination changera le jour où une page fiche
        // par jeton signé existera, et un 301 serait resté en cache.
        $this->assertSame(302, $this->get('/f/'.$job->public_token)->getStatusCode());
        $this->assertNotNull($checkIn);
    }

    public function test_an_unknown_link_says_nothing(): void
    {
        // Ni indice sur l'existence de la fiche, ni donnée : un 404 nu.
        $this->get('/f/01JUNKNOWNTOKEN0000000000')->assertNotFound();
    }

    public function test_the_link_survives_a_manual_resend(): void
    {
        $this->fakeAccepted();
        $this->completeCheckIn();

        $job = WhatsappSendLog::first();
        $token = $job->public_token;

        $job->update(['status' => WhatsappSendLog::STATUS_FAILED]);
        app(WhatsappOutboxService::class)->resend($job->fresh());

        // Un lien stable qui se périmerait au premier renvoi ne serait pas un
        // lien stable : le policier garde le message d'origine.
        $this->assertSame($token, $job->fresh()->public_token);
    }

    public function test_the_token_invariant_is_enforced_by_the_database(): void
    {
        // Un invariant qui ne vit que dans le modèle tient jusqu'à la première
        // écriture qui contourne Eloquent — reprise de données, script SQL,
        // insertOrIgnore écrit dans six mois. On vérifie donc la contrainte là
        // où elle compte : en base.
        $this->expectException(QueryException::class);

        DB::table('whatsapp_send_log')->insert([
            'id' => (string) Str::uuid(),
            'recipient' => '21611111111',
            'caption' => 'Fiche sans jeton',
            'status' => WhatsappSendLog::STATUS_PENDING,
            'queued_at' => now(),
            'public_token' => null,
        ]);
    }

    public function test_the_token_column_rejects_duplicates(): void
    {
        $first = $this->sentJob('wamid.UNIQ');

        // Deux fiches partageant un jeton, ce sont deux policiers qui ouvrent
        // le même dossier — ou l'un qui ouvre celui de l'autre.
        $this->expectException(QueryException::class);

        DB::table('whatsapp_send_log')->insert([
            'id' => (string) Str::uuid(),
            'recipient' => '21622222222',
            'caption' => 'Doublon',
            'status' => WhatsappSendLog::STATUS_PENDING,
            'queued_at' => now(),
            'public_token' => $first->public_token,
        ]);
    }

    // ── Ce que la route publique de santé laisse voir ────────────────────────

    public function test_public_health_exposes_no_recipient_no_credential_no_token(): void
    {
        $this->hotel->whatsappRecipientProfiles()->sync([
            $this->authorityRecipient('21611111111')->id,
        ]);
        $this->fakeAccepted();
        $this->completeCheckIn();

        $body = $this->getJson('/api/v1/health/whatsapp')->assertOk()->getContent();

        // L'URL est publique : rien de ce qui identifie un destinataire, un
        // compte Meta ou une fiche ne doit pouvoir en sortir.
        foreach ([
            '21611111111',                                    // numéro de destinataire
            '21600000000',                                    // numéro global
            'test-token', '123456', 'waba-1', 'app-secret',   // identifiants Meta
            (string) WhatsappSendLog::first()->public_token,  // jeton de fiche
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        // Et rien de plus que le verdict.
        $this->assertSame(
            ['enabled', 'status'],
            array_keys($this->getJson('/api/v1/health/whatsapp')->json('data')),
        );
    }

    public function test_public_health_reports_degraded_without_saying_why(): void
    {
        config(['whatsapp.guard.sending_enabled' => false]);

        $response = $this->getJson('/api/v1/health/whatsapp')->assertOk();

        $this->assertSame('degraded', $response->json('data.status'));
        // Le motif décrit notre configuration interne : il reste côté admin.
        $this->assertStringNotContainsString('WHATSAPP_', $response->getContent());
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

    /**
     * Deux appels par fiche, et pas un seul : /media téléverse le PDF, puis
     * /messages envoie le modèle qui le référence. Simuler le second sans le
     * premier ferait échouer tous les envois sur un téléversement vide — et
     * l'échec ressemblerait à un problème de message.
     */
    private function fakeAccepted(string $wamid = 'wamid.TEST'): void
    {
        Http::fake([
            '*/media' => Http::response(['id' => 'media-1'], 200),
            '*/messages' => Http::response(['messages' => [['id' => $wamid]]], 200),
        ]);
    }

    /** Téléversement OK, message refusé — pour éprouver la gestion d'erreur. */
    private function fakeMediaOkAndMessage(mixed $message): void
    {
        Http::fake([
            '*/media' => Http::response(['id' => 'media-1'], 200),
            '*/messages' => $message,
        ]);
    }

    /** L'envoi du modèle, isolé du téléversement. */
    private function assertTemplateSent(\Closure $assertion): void
    {
        Http::assertSent(function ($request) use ($assertion) {
            if (! str_ends_with($request->url(), '/messages')) {
                return false;
            }

            return $assertion($request);
        });
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
