<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\WhatsappSendLog;
use App\Models\WhatsappSessionState;
use App\Services\Delivery\DeliveryChannelManager;
use App\Services\Delivery\WhatsAppCloudChannel;
use App\Services\Delivery\WhatsAppWebChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Canal de transmission interchangeable (STRAT-07).
 *
 * Enjeu : le contournement du relais WhatsApp non officiel expire le
 * 10/09/2026. L'extraction doit permettre de basculer sans opération
 * chirurgicale — et surtout sans changer le comportement d'ici là.
 */
class DeliveryChannelTest extends TestCase
{
    use RefreshDatabase;

    // ── Sélection du canal ───────────────────────────────────────────────────

    public function test_web_channel_is_the_default(): void
    {
        // Rien ne doit changer en production tant que la variable n'est pas posée.
        $this->assertInstanceOf(WhatsAppWebChannel::class, app(DeliveryChannelManager::class)->active());
    }

    public function test_an_unknown_channel_fails_loudly(): void
    {
        config(['whatsapp.channel' => 'sftp-inexistant']);

        // Surtout pas de repli silencieux : une faute de frappe ferait croire
        // que la bascule a eu lieu alors que l'ancien canal continue.
        $this->expectException(\InvalidArgumentException::class);
        app(DeliveryChannelManager::class)->active();
    }

    // ── La différence de format entre canaux ─────────────────────────────────

    public function test_the_two_channels_format_recipients_differently(): void
    {
        $web = new WhatsAppWebChannel;
        $cloud = new WhatsAppCloudChannel;

        // C'est précisément ce détail qui aurait fuité partout sans interface.
        $this->assertSame('21620123456@c.us', $web->formatRecipient('+216 20 123 456'));
        $this->assertSame('21620123456', $cloud->formatRecipient('+216 20 123 456'));
    }

    public function test_cloud_channel_rejects_truncated_numbers(): void
    {
        $cloud = new WhatsAppCloudChannel;

        $this->assertNull($cloud->formatRecipient('123'), 'Un numéro tronqué doit être refusé, pas transmis.');
        $this->assertNull($cloud->formatRecipient(null));
    }

    public function test_web_channel_is_pull_and_refuses_direct_send(): void
    {
        // La transmission passe par le worker Node ; appeler send() ici serait
        // un bug d'appelant, il doit être bruyant.
        $this->expectException(\LogicException::class);
        (new WhatsAppWebChannel)->send(new WhatsappSendLog);
    }

    // ── Transmission par la Cloud API ────────────────────────────────────────

    private function configureCloud(): void
    {
        config([
            'whatsapp.enabled' => true,
            'whatsapp.channel' => 'cloud',
            'whatsapp.cloud.token' => 'test-token',
            'whatsapp.cloud.phone_number_id' => '123456',
        ]);
    }

    public function test_cloud_channel_sends_and_returns_the_message_id(): void
    {
        $this->configureCloud();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]], 200),
        ]);

        $job = new WhatsappSendLog(['recipient' => '21620123456', 'caption' => 'Fiche voyageur']);

        $result = (new WhatsAppCloudChannel)->send($job);

        $this->assertTrue($result->success);
        $this->assertSame('wamid.TEST', $result->messageId);

        Http::assertSent(fn ($request) => $request['messaging_product'] === 'whatsapp'
            && $request['to'] === '21620123456'
            && $request['text']['body'] === 'Fiche voyageur');
    }

    public function test_cloud_channel_marks_4xx_as_permanent(): void
    {
        $this->configureCloud();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid recipient']], 400),
        ]);

        $result = (new WhatsAppCloudChannel)->send(
            new WhatsappSendLog(['recipient' => '21620123456', 'caption' => 'x'])
        );

        $this->assertFalse($result->success);
        // Retenter à l'identique ne changerait rien : ce serait gaspiller des
        // tentatives et retarder l'alerte.
        $this->assertFalse($result->retryable);
        $this->assertSame('Invalid recipient', $result->error);
    }

    public function test_cloud_channel_marks_5xx_and_429_as_retryable(): void
    {
        $this->configureCloud();

        foreach ([500, 503, 429] as $status) {
            Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'oops']], $status)]);

            $result = (new WhatsAppCloudChannel)->send(
                new WhatsappSendLog(['recipient' => '21620123456', 'caption' => 'x'])
            );

            $this->assertFalse($result->success, "HTTP {$status}");
            $this->assertTrue($result->retryable, "HTTP {$status} doit être réessayable.");
        }
    }

    public function test_cloud_channel_refuses_to_send_when_unconfigured(): void
    {
        config(['whatsapp.enabled' => true, 'whatsapp.cloud.token' => null]);

        Http::fake();

        $result = (new WhatsAppCloudChannel)->send(
            new WhatsappSendLog(['recipient' => '21620123456', 'caption' => 'x'])
        );

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    // ── Mode ombre ───────────────────────────────────────────────────────────

    public function test_shadow_mode_never_transmits(): void
    {
        config([
            'whatsapp.enabled' => true,
            'whatsapp.recipient' => '21611111111@c.us',
            'whatsapp.channel' => 'web',
            'whatsapp.shadow_channel' => 'cloud',
        ]);

        Http::fake();

        $hotel = Hotel::factory()->withActiveSubscription()->create();
        app(DeliveryChannelManager::class)->compareRecipients($hotel, ['21611111111@c.us']);

        // Une comparaison qui enverrait de vrais messages serait pire que pas
        // de comparaison du tout.
        Http::assertNothingSent();
    }

    public function test_shadow_mode_reports_a_recipient_count_mismatch(): void
    {
        config([
            'whatsapp.enabled' => true,
            'whatsapp.recipient' => '',   // le canal cible ne résoudra personne
            'whatsapp.channel' => 'web',
            'whatsapp.shadow_channel' => 'cloud',
        ]);

        Log::spy();

        $hotel = Hotel::factory()->withActiveSubscription()->create();

        // Le canal actif a un destinataire, le canal cible zéro : après
        // bascule, la fiche ne partirait plus. C'est exactement l'écart que le
        // mode ombre doit faire remonter AVANT la bascule.
        app(DeliveryChannelManager::class)->compareRecipients($hotel, ['21611111111@c.us']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'delivery-shadow'))
            ->atLeast()->once();
    }

    public function test_shadow_mode_is_inert_when_not_configured(): void
    {
        config(['whatsapp.shadow_channel' => null]);

        $this->assertNull(app(DeliveryChannelManager::class)->shadow());
    }

    public function test_shadow_channel_identical_to_active_is_ignored(): void
    {
        config(['whatsapp.channel' => 'web', 'whatsapp.shadow_channel' => 'web']);

        // Se comparer à soi-même ne produirait que du bruit.
        $this->assertNull(app(DeliveryChannelManager::class)->shadow());
    }

    // ── Modèle approuvé + fiche en pièce jointe ──────────────────────────────

    /**
     * Hors fenêtre de 24 h, la Cloud API refuse le texte libre (131047), et nos
     * destinataires ne répondent JAMAIS — le module ignore tout message entrant.
     * En production, un envoi qui n'est pas un modèle est un envoi qui échoue.
     */
    public function test_cloud_channel_sends_a_template_when_one_is_configured(): void
    {
        $this->configureCloud();
        config(['whatsapp.cloud.template_name' => 'fiche_police', 'whatsapp.cloud.template_language' => 'fr']);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.T']]], 200)]);

        $hotel = Hotel::factory()->withActiveSubscription()->create(['name' => 'Dar Test']);
        $job = new WhatsappSendLog(['recipient' => '21620123456', 'caption' => "Ligne 1\nLigne 2", 'hotel_id' => $hotel->id]);

        $result = (new WhatsAppCloudChannel)->send($job);

        $this->assertTrue($result->success);
        Http::assertSent(function ($request) {
            if (!str_ends_with($request->url(), '/messages')) {
                return false;
            }

            return $request['type'] === 'template'
                && $request['template']['name'] === 'fiche_police'
                && $request['template']['language']['code'] === 'fr';
        });
    }

    public function test_template_parameters_never_carry_a_line_break(): void
    {
        /*
         * Meta rejette le message ENTIER si un paramètre contient un saut de
         * ligne, une tabulation ou plus de quatre espaces consécutifs. Un nom
         * d'établissement collé depuis un tableur suffit à tout faire tomber.
         */
        $this->configureCloud();
        config(['whatsapp.cloud.template_name' => 'fiche_police']);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.T']]], 200)]);

        $hotel = Hotel::factory()->withActiveSubscription()->create(['name' => "Dar\nTest    Sousse"]);
        $job = new WhatsappSendLog(['recipient' => '21620123456', 'caption' => 'x', 'hotel_id' => $hotel->id]);

        (new WhatsAppCloudChannel)->send($job);

        Http::assertSent(function ($request) {
            if (!str_ends_with($request->url(), '/messages')) {
                return false;
            }
            foreach ($request['template']['components'] as $component) {
                if (($component['type'] ?? null) !== 'body') {
                    continue;
                }
                foreach ($component['parameters'] as $param) {
                    if (preg_match('/[\r\n\t]|\s{5,}/', $param['text'])) {
                        return false;
                    }
                }
            }

            return true;
        });
    }

    public function test_a_refused_media_upload_is_retryable_not_a_lost_fiche(): void
    {
        // Le /media peut tomber sans que la fiche soit en cause : elle doit
        // repartir au tour suivant, pas être abandonnée.
        $this->configureCloud();
        config(['whatsapp.cloud.template_name' => 'fiche_police']);

        Http::fake([
            '*/media' => Http::response(['error' => ['message' => 'Service indisponible']], 503),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.T']]], 200),
        ]);

        $checkIn = CheckIn::factory()
            ->for(Hotel::factory()->withActiveSubscription()->create())
            ->active()->withGuest('Martin', 'Ostermeier')->create();

        $job = WhatsappSendLog::create([
            'hotel_id' => $checkIn->hotel_id,
            'check_in_id' => $checkIn->id,
            'guest_id' => $checkIn->guests()->first()->id,
            'recipient' => '21620123456',
            'caption' => 'Fiche',
            'status' => 'pending',
            'queued_at' => now(),
        ]);

        $result = (new WhatsAppCloudChannel)->send($job);

        $this->assertFalse($result->success);
        $this->assertTrue($result->retryable, 'un /media en panne ne condamne pas la fiche');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/messages'));
    }

    public function test_a_real_fiche_travels_as_an_uploaded_pdf(): void
    {
        // La fiche est multi-ligne : aucune variable de modèle ne peut
        // l'accueillir. Elle doit donc partir en pièce jointe — et c'est ce qui
        // ramène au passage la photo de la pièce d'identité.
        $this->configureCloud();
        config(['whatsapp.cloud.template_name' => 'fiche_police']);

        Http::fake([
            '*/media' => Http::response(['id' => 'MEDIA-42'], 200),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.T']]], 200),
        ]);

        $checkIn = CheckIn::factory()
            ->for(Hotel::factory()->withActiveSubscription()->create(['name' => 'Dar Test']))
            ->active()->withGuest('Martin', 'Ostermeier')->create();

        $job = WhatsappSendLog::create([
            'hotel_id' => $checkIn->hotel_id,
            'check_in_id' => $checkIn->id,
            'guest_id' => $checkIn->guests()->first()->id,
            'recipient' => '21620123456',
            'caption' => 'Fiche',
            'status' => 'pending',
            'queued_at' => now(),
        ]);

        $this->assertTrue((new WhatsAppCloudChannel)->send($job)->success);

        Http::assertSent(function ($request) {
            if (!str_ends_with($request->url(), '/messages')) {
                return false;
            }
            $header = collect($request['template']['components'])->firstWhere('type', 'header');

            return $header !== null
                && $header['parameters'][0]['document']['id'] === 'MEDIA-42'
                && str_ends_with($header['parameters'][0]['document']['filename'], '.pdf');
        });
    }

    public function test_a_test_job_without_a_checkin_still_goes_out(): void
    {
        // Le message [TEST] sert précisément à valider la chaîne : il ne doit
        // pas être le seul à ne pas pouvoir l'emprunter, faute de PDF.
        $this->configureCloud();
        config(['whatsapp.cloud.template_name' => 'fiche_police']);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.T']]], 200)]);

        $job = new WhatsappSendLog(['recipient' => '21620123456', 'caption' => '[TEST]', 'is_test' => true]);

        $this->assertTrue((new WhatsAppCloudChannel)->send($job)->success);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/media'));
    }

    // ── Drainage de la file par le canal push ────────────────────────────────

    /** @return CheckIn */
    private function checkInWithGuest(string $hotelName = 'Dar Test')
    {
        return CheckIn::factory()
            ->for(Hotel::factory()->withActiveSubscription()->create(['name' => $hotelName]))
            ->active()->withGuest('Martin', 'Ostermeier')->create();
    }

    private function queueFiche(): WhatsappSendLog
    {
        $checkIn = $this->checkInWithGuest();

        return WhatsappSendLog::create([
            'hotel_id' => $checkIn->hotel_id,
            'check_in_id' => $checkIn->id,
            'guest_id' => $checkIn->guests()->first()->id,
            'recipient' => '21620123456',
            'caption' => 'Fiche',
            'status' => 'pending',
            'next_attempt_at' => now(),
            'queued_at' => now(),
        ]);
    }

    public function test_push_command_drains_the_queue_without_any_session(): void
    {
        /*
         * En push il n'y a NI worker, NI session appairée. Exiger une session
         * « prête » aurait gelé la file pour toujours après la bascule, en
         * silence : tout configuré, et rien qui part.
         */
        $this->configureCloud();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);

        $job = $this->queueFiche();
        $this->assertSame('initializing', WhatsappSessionState::current()->status);

        $this->artisan('whatsapp:push')->assertExitCode(0);

        $job->refresh();
        $this->assertSame('sent', $job->status);
        $this->assertSame('wamid.OK', $job->message_id_whatsapp);
    }

    public function test_push_command_is_inert_on_a_pull_channel(): void
    {
        // Tant que le canal actif est WhatsApp Web, c'est le worker Node qui
        // transmet : la commande doit pouvoir rester planifiée sans rien casser.
        config(['whatsapp.enabled' => true, 'whatsapp.channel' => 'web', 'whatsapp.recipient' => '21612345678@c.us']);
        Http::fake();

        $job = $this->queueFiche();

        $this->artisan('whatsapp:push')->assertExitCode(0);

        $this->assertSame('pending', $job->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_push_command_respects_the_pause(): void
    {
        // Le coupe-circuit humain vaut pour les deux transports.
        $this->configureCloud();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);
        WhatsappSessionState::current()->update(['paused' => true]);

        $job = $this->queueFiche();

        $this->artisan('whatsapp:push')->assertExitCode(0);

        $this->assertSame('pending', $job->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_push_command_stops_and_cuts_the_relay_after_repeated_refusals(): void
    {
        /*
         * Même leçon que le 17/08 : une série de refus n'est pas une histoire
         * de fiches, c'est le canal qui est refusé. S'obstiner sur la Cloud API
         * coûterait le numéro professionnel vérifié — autrement plus cher
         * qu'une SIM.
         */
        $this->configureCloud();
        config(['whatsapp.circuit_breaker_failures' => 2, 'whatsapp.min_interval_seconds' => 1]);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Refusé']], 400)]);

        $this->queueFiche();
        $this->queueFiche();
        $survivor = $this->queueFiche();

        $this->artisan('whatsapp:push')->assertExitCode(0);

        $this->assertTrue(WhatsappSessionState::current()->fresh()->paused, 'le relais doit être coupé');
        $this->assertSame('pending', $survivor->fresh()->status, 'la file restante est préservée');
    }

    public function test_push_command_pauses_the_run_on_a_temporary_failure(): void
    {
        // Un 5xx ne dit rien de notre légitimité : on rend la main sans couper
        // le relais ni brûler les tentatives des fiches suivantes.
        $this->configureCloud();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'oops']], 503)]);

        $job = $this->queueFiche();

        $this->artisan('whatsapp:push')->assertExitCode(0);

        $this->assertSame('pending', $job->fresh()->status);
        $this->assertFalse(WhatsappSessionState::current()->fresh()->paused);
    }

    public function test_push_command_honours_the_hourly_cap(): void
    {
        // Le plafond est la seule chose qui empêche un arriéré de partir d'un
        // bloc — c'est exactement ce que Meta a sanctionné.
        $this->configureCloud();
        config(['whatsapp.max_per_hour' => 1]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);

        WhatsappSendLog::create([
            'recipient' => '21620123456', 'caption' => 'déjà partie',
            'status' => 'sent', 'sent_at' => now()->subMinutes(10), 'queued_at' => now()->subMinutes(20),
        ]);
        $job = $this->queueFiche();

        $this->artisan('whatsapp:push')->assertExitCode(0);

        $this->assertSame('pending', $job->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_push_command_does_not_idle_after_emptying_the_queue(): void
    {
        /*
         * La cadence s'applique ENTRE deux envois, jamais après le dernier.
         * Placée après, elle faisait dormir 45 s une exécution qui n'avait plus
         * rien à envoyer — verrou de l'ordonnanceur compris, donc en retardant
         * d'autant la fiche suivante. Ce test échoue par le temps qu'il met.
         */
        $this->configureCloud();
        config(['whatsapp.min_interval_seconds' => 3, 'whatsapp.interval_jitter_ratio' => 0]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);

        $this->queueFiche();

        $startedAt = microtime(true);
        $this->artisan('whatsapp:push')->assertExitCode(0);
        $elapsed = microtime(true) - $startedAt;

        $this->assertLessThan(3, $elapsed, 'une file vidée ne doit pas retenir la commande');
    }

    public function test_push_command_paces_between_two_sends(): void
    {
        // Mais la cadence existe bel et bien : deux fiches ne partent pas
        // collées. C'est la rafale que les heuristiques anti-spam repèrent.
        $this->configureCloud();
        config(['whatsapp.min_interval_seconds' => 2, 'whatsapp.interval_jitter_ratio' => 0]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);

        $this->queueFiche();
        $this->queueFiche();

        $startedAt = microtime(true);
        $this->artisan('whatsapp:push')->assertExitCode(0);
        $elapsed = microtime(true) - $startedAt;

        $this->assertGreaterThanOrEqual(2, $elapsed, 'les envois doivent être espacés');
        $this->assertSame(2, WhatsappSendLog::where('status', 'sent')->count());
    }

    // ── Essai du canal Cloud sans basculer la production ─────────────────────

    public function test_cloud_test_command_never_consumes_a_real_fiche(): void
    {
        /*
         * Le point critique. Un essai qui marquerait « envoyée » une fiche
         * encore due à l'autorité serait pire que pas d'essai du tout : elle
         * disparaîtrait de la file sans être parvenue à personne.
         */
        $this->configureCloud();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.T']]], 200)]);

        $job = $this->queueFiche();

        $this->artisan('whatsapp:cloud-test', ['--to' => '21699999999'])->assertExitCode(0);

        $job->refresh();
        $this->assertSame('pending', $job->status, 'la fiche reste due');
        $this->assertNull($job->sent_at);
        $this->assertNull($job->message_id_whatsapp);
        $this->assertSame(0, $job->attempts);
        $this->assertSame(1, WhatsappSendLog::count(), 'aucune ligne parasite créée');
    }

    public function test_cloud_test_command_sends_to_the_requested_number(): void
    {
        // Le numéro de test Meta ne parle qu'aux destinataires déclarés :
        // l'essai doit pouvoir viser un autre numéro que celui de production.
        $this->configureCloud();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.T']]], 200)]);

        $this->queueFiche();

        $this->artisan('whatsapp:cloud-test', ['--to' => '+216 99 888 777'])->assertExitCode(0);

        Http::assertSent(fn ($request) => !str_ends_with($request->url(), '/media')
            && $request['to'] === '21699888777');
    }

    public function test_cloud_test_command_ignores_the_active_channel(): void
    {
        // Valider un canal ne doit pas exiger de lui confier la production.
        config([
            'whatsapp.enabled' => true,
            'whatsapp.channel' => 'web',
            'whatsapp.cloud.token' => 'test-token',
            'whatsapp.cloud.phone_number_id' => '123456',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.T']]], 200)]);

        $this->artisan('whatsapp:cloud-test', ['--to' => '21699888777'])->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }

    public function test_cloud_test_command_reports_meta_error_verbatim(): void
    {
        // C'est le message de Meta qui dit quoi corriger : modèle absent,
        // destinataire non déclaré, jeton périmé. Le masquer coûterait une nuit.
        $this->configureCloud();
        Http::fake([
            'graph.facebook.com/*' => Http::response(
                ['error' => ['message' => 'Template name does not exist in the translation']],
                400,
            ),
        ]);

        $this->artisan('whatsapp:cloud-test', ['--to' => '21699888777'])
            ->expectsOutputToContain('Template name does not exist in the translation')
            ->assertExitCode(1);
    }

    public function test_cloud_test_command_refuses_a_short_number(): void
    {
        $this->configureCloud();
        Http::fake();

        $this->artisan('whatsapp:cloud-test', ['--to' => '123'])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_cloud_test_command_accepts_a_positional_recipient(): void
    {
        // La console web de Railway avale les doubles tirets : « --to=216… » y
        // arrive en « to216… ». C'est pourtant depuis cette console qu'on
        // exerce le canal en production.
        $this->configureCloud();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.T']]], 200)]);

        $this->artisan('whatsapp:cloud-test', ['destinataire' => '21699888777'])->assertExitCode(0);

        Http::assertSent(fn ($request) => !str_ends_with($request->url(), '/media')
            && $request['to'] === '21699888777');
    }

    public function test_cloud_test_command_falls_back_to_the_configured_recipient(): void
    {
        // Sans aucun argument : c'est la forme utilisable partout, y compris
        // dans une console qui abîme les tirets.
        $this->configureCloud();
        config(['whatsapp.recipient' => '21693116000@c.us']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.T']]], 200)]);

        $this->artisan('whatsapp:cloud-test')->assertExitCode(0);

        Http::assertSent(fn ($request) => !str_ends_with($request->url(), '/media')
            && $request['to'] === '21693116000');
    }
}
