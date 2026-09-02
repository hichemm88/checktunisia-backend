<?php

namespace Tests\Feature;

use App\Mail\SystemMail;
use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\User;
use App\Models\WhatsappChannelOutage;
use App\Models\WhatsappSendLog;
use App\Services\Whatsapp\WhatsappOtpSender;
use App\Services\Whatsapp\WhatsappOutboxService;
use App\Services\Whatsapp\WhatsappSendingGuard;
use App\Services\Whatsapp\WhatsappTemplateStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Garde d'approbation du modèle, au dispatch.
 *
 * L'incident qui a rendu ces tests nécessaires : WHATSAPP_SENDING_ENABLED est
 * passé à true alors que le modèle des fiches était encore PENDING chez Meta.
 * Le dispatcher a présenté les fiches, Meta les a refusées en 132001, chaque
 * refus a consommé une tentative, et l'abandon à 24 h les a marquées « échec
 * définitif » — avec une alerte email par fiche. Des fiches de police
 * déclarées perdues alors qu'aucune n'avait jamais pu partir.
 *
 * Ce qui est vérifié ici n'est donc pas « l'envoi fonctionne » (c'est le rôle
 * de WhatsappCloudApiTest), mais les quatre façons dont l'incident pourrait se
 * reproduire :
 *
 *  - une tentative part alors que le modèle n'est pas approuvé ;
 *  - une entrée est pénalisée (tentative, backoff, abandon) pour une panne de
 *    canal qui n'est pas la sienne ;
 *  - l'horloge des 24 h tourne pendant l'attente réglementaire ;
 *  - une panne unique produit une alerte par fiche.
 */
class WhatsappTemplateApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $receptionist;

    /**
     * État de Meta, pilotable EN COURS DE TEST.
     *
     * Un second `Http::fake()` n'écrase pas le premier — les stubs sont
     * empilés et c'est le premier qui répond. Or l'essentiel de ce fichier
     * consiste à faire CHANGER l'état de Meta entre deux passes (PENDING puis
     * APPROVED). D'où un unique stub, piloté par ces propriétés.
     */
    private string $templateStatus = 'APPROVED';

    private string $templateLanguage = 'fr';

    private mixed $templatesResponse = null;

    private mixed $messageResponse = null;

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
            'whatsapp.cloud.base_url' => 'https://graph.facebook.com',
            'whatsapp.cloud.api_version' => 'v21.0',
            'whatsapp.cloud.timeout' => 30,
            'whatsapp.cloud.template.name' => 'fiche_police_v2',
            'whatsapp.cloud.template.language' => 'fr',
            'whatsapp.cloud.template.otp_name' => 'qayed_otp',
            'whatsapp.cloud.template.otp_language' => 'fr',
            'whatsapp.cloud.waba_id' => 'waba-1',
            'whatsapp.cloud.app_id' => 'app-1',
            'whatsapp.cloud.app_secret' => 'app-secret',
            'whatsapp.cloud.webhook_verify_token' => 'verify-me',
            'whatsapp.guard.sending_enabled' => true,
            'whatsapp.guard.cutover_at' => now()->subDay()->toIso8601String(),
            'whatsapp.min_interval_seconds' => 0,
            'whatsapp.interval_jitter_ratio' => 0,
        ]);

        $this->fakeMeta();
    }

    // ── 1. Modèle PENDING : zéro tentative ───────────────────────────────────

    public function test_a_pending_template_produces_no_send_attempt_at_all(): void
    {
        $this->templateStatus = 'PENDING';
        $this->completeCheckIn();

        $job = WhatsappSendLog::first();
        $this->assertSame(0, (int) $job->attempts, 'Précondition : la fiche est enfilée, jamais tentée.');

        $result = app(WhatsappOutboxService::class)->dispatchPending();

        // Aucun appel d'ENVOI : ni le téléversement du PDF, ni le message. La
        // seule requête admise est la lecture du statut des modèles, qui est
        // précisément ce qui a permis de ne rien tenter.
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/messages'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/media'));

        $job->refresh();

        // L'entrée est intacte : même statut, aucune tentative décomptée,
        // aucun backoff, aucune erreur inscrite à son dossier.
        $this->assertSame(WhatsappSendLog::STATUS_PENDING, $job->status);
        $this->assertSame(0, (int) $job->attempts);
        $this->assertNull($job->last_error);
        // Toujours éligible tout de suite : aucun backoff ne lui a été imposé.
        $this->assertTrue($job->next_attempt_at->lte(now()));

        $this->assertStringContainsString('approbation', (string) $result['blocked']);
    }

    public function test_an_unknown_template_status_blocks_rather_than_guesses(): void
    {
        // Graph refuse la lecture : nous ne SAVONS pas si le modèle est
        // approuvé. Tenter « au cas où » est exactement le comportement qui a
        // produit l'incident.
        $this->templatesResponse = Http::response(['error' => ['message' => 'Bad token']], 401);

        $this->completeCheckIn();
        app(WhatsappOutboxService::class)->dispatchPending();

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/messages'));
        $this->assertSame(0, (int) WhatsappSendLog::first()->attempts);
    }

    public function test_a_template_approved_in_another_language_is_not_approved_here(): void
    {
        // Meta renvoie le MÊME 132001 pour un modèle jamais soumis et pour un
        // modèle approuvé dans une autre langue. Distinguer les deux évite de
        // resoumettre un modèle qui existe et d'attendre pour rien.
        $this->templateLanguage = 'en';

        $this->completeCheckIn();
        app(WhatsappOutboxService::class)->dispatchPending();

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/messages'));

        $this->assertStringContainsString('en', (string) app(WhatsappTemplateStatus::class)->blockingReason());
    }

    public function test_the_otp_channel_is_untouched_by_a_pending_fiche_template(): void
    {
        $this->templateStatus = 'PENDING';

        // Le modèle des fiches attend son approbation ; celui de l'OTP est
        // approuvé et emprunte un autre chemin. Fermer le portail des agents
        // pour un problème qui n'est pas le sien serait doubler la panne.
        $this->assertNotNull(app(WhatsappSendingGuard::class)->templateBlock());

        $result = app(WhatsappOtpSender::class)->send('21620000000', '123456');

        $this->assertTrue($result->success);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/messages'));
    }

    // ── 2. Passage à APPROVED : l'envoi part ────────────────────────────────

    public function test_the_queue_flows_again_as_soon_as_the_template_is_approved(): void
    {
        $this->templateStatus = 'PENDING';
        $this->completeCheckIn();

        app(WhatsappOutboxService::class)->dispatchPending();
        $this->assertSame(WhatsappSendLog::STATUS_PENDING, WhatsappSendLog::first()->status);

        // Meta approuve. Le statut mémorisé expire au bout d'un quart d'heure ;
        // on simule ce rafraîchissement.
        $this->templateStatus = 'APPROVED';
        app(WhatsappTemplateStatus::class)->forget();

        app(WhatsappOutboxService::class)->dispatchPending();

        $job = WhatsappSendLog::first();
        $this->assertSame(WhatsappSendLog::STATUS_SENT, $job->status);
        $this->assertSame('wamid.OK', $job->message_id_whatsapp);

        // Rien à relancer à la main : la file repart d'elle-même.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/messages'));
    }

    public function test_the_templates_command_hands_the_approval_to_the_dispatcher(): void
    {
        $this->templateStatus = 'PENDING';
        $this->assertFalse(app(WhatsappTemplateStatus::class)->ficheApproved());

        // L'exploitant relance la commande de suivi et y lit « APPROVED ».
        // Sans report vers la boucle d'envoi, il verrait la bonne nouvelle à
        // l'écran et attendrait quinze minutes sans savoir qu'il attend.
        $this->templateStatus = 'APPROVED';
        $this->artisan('whatsapp:templates')->assertSuccessful();

        $this->assertTrue(app(WhatsappTemplateStatus::class)->ficheApproved());
    }

    // ── 3. 132001 : erreur de configuration, pas échec de fiche ─────────────

    public function test_a_132001_pauses_the_channel_and_never_fails_the_entry(): void
    {
        Mail::fake();
        $this->platformAdmin();

        // Le modèle est APPROVED d'après Meta… et pourtant l'envoi est refusé
        // en 132001. C'est le cas d'une divergence de nom ou de langue : le
        // canal est mal réglé, la fiche n'y est pour rien.
        $this->messageResponse = $this->refusal132001();

        $this->completeCheckIn();
        $result = app(WhatsappOutboxService::class)->dispatchPending();

        $job = WhatsappSendLog::first();

        // L'entrée reste en file, sans tentative décomptée ni horloge de retry.
        $this->assertSame(WhatsappSendLog::STATUS_PENDING, $job->status);
        $this->assertSame(0, (int) $job->attempts);
        $this->assertNull($job->next_attempt_at);
        $this->assertSame(0, $result['failed']);

        // Le canal, lui, est suspendu — insister ne réparerait rien.
        $this->assertNotNull(app(WhatsappSendingGuard::class)->pausedUntil());
    }

    public function test_a_132001_makes_the_channel_re_read_the_real_template_status(): void
    {
        Mail::fake();
        $this->platformAdmin();
        $this->messageResponse = $this->refusal132001();

        $this->completeCheckIn();
        app(WhatsappOutboxService::class)->dispatchPending();

        /*
         * Meta vient de démentir l'approbation que nous avions en mémoire.
         * Cette mémoire est désormais la donnée la moins fiable dont nous
         * disposions : la passe suivante doit REDEMANDER, sans quoi elle
         * rejouerait le même refus jusqu'à expiration du cache — et c'est
         * cette boucle-là qui a brûlé les tentatives de toute la file.
         *
         * Meta dit maintenant la vérité : le modèle est PENDING.
         */
        $this->templateStatus = 'PENDING';
        Cache::forget('whatsapp:global_pause_until');

        app(WhatsappOutboxService::class)->dispatchPending();

        // Un SEUL envoi tenté en tout — celui de la première passe. La seconde
        // a relu le statut et s'est tue.
        $this->assertSame(1, $this->recordedCalls('/messages'));
        $this->assertSame(
            2,
            $this->recordedCalls('message_templates'),
            'Le statut doit avoir été redemandé à Meta, pas relu en cache.'
        );
    }

    /**
     * Nombre de requêtes enregistrées dont l'URL contient $needle.
     *
     * `Http::assertNotSent` ne convient pas ici : il porte sur TOUT
     * l'historique, y compris la passe précédente qu'on veut justement
     * distinguer de la suivante.
     */
    private function recordedCalls(string $needle): int
    {
        $count = 0;

        Http::assertSent(function ($request) use ($needle, &$count) {
            if (str_contains($request->url(), $needle)) {
                $count++;
            }

            return true;
        });

        return $count;
    }

    public function test_a_132001_alerts_administrators_once_not_once_per_fiche(): void
    {
        Mail::fake();
        $this->platformAdmin();
        $this->messageResponse = $this->refusal132001();

        // Trois fiches en file, une seule et même panne.
        $this->completeCheckIn();
        $this->completeCheckIn();
        $this->completeCheckIn();
        $this->assertSame(3, WhatsappSendLog::count());

        $outbox = app(WhatsappOutboxService::class);

        // La pause s'arme à chaque refus ; on la lève entre deux passes pour
        // éprouver le vrai garde-fou — la déduplication de l'alerte.
        foreach (range(1, 3) as $ignored) {
            $outbox->dispatchPending();
            Cache::forget('whatsapp:global_pause_until');
            app(WhatsappTemplateStatus::class)->remember('APPROVED');
        }

        Mail::assertSent(
            SystemMail::class,
            1,
        );
    }

    // ── 4. L'horloge des 24 h ne compte que le temps utile ──────────────────

    public function test_the_24h_clock_ignores_the_time_the_channel_could_not_send(): void
    {
        $this->templateStatus = 'PENDING';
        $this->completeCheckIn();

        $job = WhatsappSendLog::first();

        // Deux jours passent, dont deux jours d'attente d'approbation : la
        // fiche est vieille au mur, mais n'a jamais eu sa chance.
        $job->forceFill(['queued_at' => now()->subDays(2)])->save();
        WhatsappChannelOutage::create([
            'started_at' => now()->subDays(2),
            'ended_at' => null,
            'reason' => 'En attente d\'approbation du modèle.',
        ]);

        $guard = app(WhatsappSendingGuard::class);

        $this->assertLessThan(
            (int) config('whatsapp.max_age_minutes'),
            $guard->effectiveAgeMinutes($job->queued_at),
            'Le temps d\'attente réglementaire ne peut pas consommer le budget de tentatives.'
        );

        // Et la conséquence qui compte : un échec transitoire ne l'abandonne pas.
        app(WhatsappOutboxService::class)->markFailed($job, 'Réseau indisponible');

        $this->assertSame(WhatsappSendLog::STATUS_PENDING, $job->fresh()->status);
    }

    public function test_a_fiche_that_had_its_full_24h_of_real_attempts_is_still_abandoned(): void
    {
        Mail::fake();
        $this->platformAdmin();

        $this->completeCheckIn();
        $job = WhatsappSendLog::first();
        $job->forceFill(['queued_at' => now()->subDays(2)])->save();

        // Aucune période d'incapacité : le canal a réellement essayé pendant
        // ces deux jours. Le garde-fou ne doit pas être devenu un moyen de ne
        // plus jamais abandonner une fiche.
        $this->assertSame(0, WhatsappChannelOutage::count());

        app(WhatsappOutboxService::class)->markFailed($job, 'Destinataire injoignable');

        $this->assertSame(WhatsappSendLog::STATUS_FAILED, $job->fresh()->status);
    }

    public function test_the_dispatch_loop_records_and_closes_the_incapacity_window(): void
    {
        $this->templateStatus = 'PENDING';
        $this->completeCheckIn();

        $outbox = app(WhatsappOutboxService::class);

        $outbox->dispatchPending();
        $this->assertNotNull(WhatsappChannelOutage::open(), 'Un blocage doit ouvrir une période.');

        // Une seconde passe bloquée ne doit pas ouvrir une SECONDE période :
        // deux périodes qui se chevauchent compteraient deux fois le même
        // temps d'arrêt, et rendraient les fiches immortelles.
        $outbox->dispatchPending();
        $this->assertSame(1, WhatsappChannelOutage::count());

        $this->templateStatus = 'APPROVED';
        app(WhatsappTemplateStatus::class)->forget();
        $outbox->dispatchPending();

        $this->assertNull(WhatsappChannelOutage::open(), 'Le canal rouvert doit refermer la période.');
    }

    // ── 5. Commande de reprise ──────────────────────────────────────────────

    public function test_requeue_failed_is_a_dry_run_by_default(): void
    {
        $this->failedOn132001();

        $this->artisan('whatsapp:requeue-failed', ['--reason' => '132001'])
            ->expectsOutputToContain('Fiches en échec définitif sur ce code : 1')
            ->assertSuccessful();

        // Rien n'a bougé : le décompte se lit avant d'écrire.
        $this->assertSame(2, WhatsappSendLog::where('status', WhatsappSendLog::STATUS_FAILED)->count());
    }

    public function test_requeue_failed_applies_only_to_post_cutover_entries(): void
    {
        [$post, $pre] = $this->failedOn132001();

        $this->artisan('whatsapp:requeue-failed', ['--reason' => '132001', '--apply' => true])
            ->assertSuccessful();

        // Post-bascule : de retour en file, tentatives remises à zéro et
        // horloge relancée — les 24 h consommées l'ont été sur une panne de
        // canal, pas sur la fiche.
        $post->refresh();
        $this->assertSame(WhatsappSendLog::STATUS_PENDING, $post->status);
        $this->assertSame(0, (int) $post->attempts);
        $this->assertNull($post->next_attempt_at);
        $this->assertTrue($post->queued_at->gt(now()->subMinute()));

        // Antérieure à la bascule : elle NE repart pas. Le séjour est terminé,
        // et la faire repartir depuis un numéro neuf est ce qui a coûté le
        // numéro précédent.
        $this->assertSame(WhatsappSendLog::STATUS_FAILED, $pre->fresh()->status);
    }

    public function test_requeue_failed_ignores_other_error_codes(): void
    {
        $this->failedOn132001();

        $other = $this->failedJob(now()->subHour(), '131026', '[131026] Destinataire non joignable.');

        $this->artisan('whatsapp:requeue-failed', ['--reason' => '132001', '--apply' => true])
            ->assertSuccessful();

        $this->assertSame(
            WhatsappSendLog::STATUS_FAILED,
            $other->fresh()->status,
            'Un numéro sans compte WhatsApp ne redevient pas joignable parce qu\'un modèle a été approuvé.'
        );
    }

    public function test_requeue_failed_is_idempotent(): void
    {
        $this->failedOn132001();

        $this->artisan('whatsapp:requeue-failed', ['--reason' => '132001', '--apply' => true])->assertSuccessful();

        $this->artisan('whatsapp:requeue-failed', ['--reason' => '132001'])
            ->expectsOutputToContain('Rien à remettre en file.')
            ->assertSuccessful();
    }

    // ── 6. Écran d'administration ───────────────────────────────────────────

    public function test_admin_health_names_the_pending_approval(): void
    {
        $this->templateStatus = 'PENDING';

        $response = $this->actingAs($this->platformAdmin())
            ->getJson('/api/v1/admin/whatsapp/health')
            ->assertOk();

        $response->assertJsonPath('data.template.status', 'PENDING');
        $response->assertJsonPath('data.template.approved', false);
        $response->assertJsonPath('data.template.name', 'fiche_police_v2');
        $response->assertJsonPath('data.sending_blocked', true);

        $this->assertStringContainsString(
            'approbation',
            (string) $response->json('data.blocked_reason'),
            'L\'écran doit dire « en attente d\'approbation », pas « bloqué ».'
        );
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * Un seul stub, lu à chaque requête : c'est ce qui permet de faire évoluer
     * l'état de Meta en cours de test, ce que des `Http::fake()` successifs ne
     * permettent pas (le premier stub enregistré l'emporte).
     */
    private function fakeMeta(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'message_templates')) {
                return $this->templatesResponse ?? Http::response(['data' => [
                    [
                        'name' => 'fiche_police_v2',
                        'language' => $this->templateLanguage,
                        'status' => $this->templateStatus,
                    ],
                    ['name' => 'qayed_otp', 'language' => 'fr', 'status' => 'APPROVED'],
                ]], 200);
            }

            if (str_ends_with($url, '/media')) {
                return Http::response(['id' => 'media-1'], 200);
            }

            if (str_ends_with($url, '/messages')) {
                return $this->messageResponse ?? Http::response(['messages' => [['id' => 'wamid.OK']]], 200);
            }

            return Http::response([], 200);
        });
    }

    private function refusal132001(): mixed
    {
        return Http::response(
            ['error' => ['code' => 132001, 'message' => 'Template name does not exist in the translation']],
            400,
        );
    }

    private function platformAdmin(): User
    {
        return User::factory()->platformAdmin()->create();
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

    /**
     * Deux fiches en échec définitif sur 132001 : une postérieure à la
     * bascule (à rejouer), une antérieure (à laisser où elle est).
     *
     * @return array{0: WhatsappSendLog, 1: WhatsappSendLog}
     */
    private function failedOn132001(): array
    {
        return [
            $this->failedJob(now()->subHours(2), '132001', '[132001] Modèle inexistant dans cette langue.'),
            $this->failedJob(now()->subDays(3), '132001', '[132001] Modèle inexistant dans cette langue.'),
        ];
    }

    private function failedJob(\DateTimeInterface $createdAt, string $code, string $error): WhatsappSendLog
    {
        $job = WhatsappSendLog::create([
            'hotel_id' => $this->hotel->id,
            'recipient' => '21600000000',
            'caption' => 'Fiche',
            'status' => WhatsappSendLog::STATUS_FAILED,
            'attempts' => 10,
            'error_code' => $code,
            'last_error' => $error,
            'queued_at' => $createdAt,
        ]);

        // `created_at` est renseigné par Eloquent : il faut le forcer APRÈS,
        // car c'est lui — et lui seul — que la borne de bascule regarde.
        $job->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $job->fresh();
    }
}
