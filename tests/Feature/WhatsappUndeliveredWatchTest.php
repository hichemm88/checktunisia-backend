<?php

namespace Tests\Feature;

use App\Mail\SystemMail;
use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\Hotel;
use App\Models\User;
use App\Models\WhatsappSendLog;
use App\Services\Whatsapp\WhatsappOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * La fiche que le système croit envoyée et que la police n'a jamais reçue.
 *
 * ── Le trou ─────────────────────────────────────────────────────────────
 *
 * Quand Meta accepte un message, il rend 200 + un `wamid`. La fiche passe en
 * `status = sent`, `delivery_status = accepted`. C'est un accusé
 * d'ACCEPTATION, pas de livraison : Meta dit « je m'en charge », rien de plus.
 *
 * La livraison réelle n'arrive que plus tard, par webhook. Si elle n'arrive
 * JAMAIS — numéro mort, compte WhatsApp supprimé, appareil éteint jusqu'à
 * l'expiration du message chez Meta, ou webhook définitivement perdu — la
 * ligne reste en `accepted`, pour toujours.
 *
 * Or rien ne regardait cet état :
 *
 *  - `stuckCount()` compte les `failed` et les `pending` en attente de
 *    backoff. Pas les `sent` figées ;
 *  - l'écran d'administration range la fiche sous « envoyées », c'est-à-dire
 *    parmi les SUCCÈS ;
 *  - aucune alerte, aucun compteur, aucune trace.
 *
 * Sur un canal qui porte une obligation légale de déclaration, c'est le pire
 * mode de panne possible : le système affiche un succès, le poste de police
 * n'a rien reçu, et personne ne peut s'en apercevoir.
 *
 * ── Ce que ces tests verrouillent ───────────────────────────────────────
 *
 * L'état doit être COMPTÉ, VISIBLE et ALERTÉ. On ne prétend pas garantir la
 * livraison — elle ne dépend pas de nous — mais son absence prolongée doit
 * cesser d'être silencieuse.
 */
class WhatsappUndeliveredWatchTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->hotel = Hotel::factory()->create();

        config([
            'whatsapp.enabled' => true,
            'whatsapp.channel' => 'cloud',
            'whatsapp.cloud.token' => 'test-token',
            'whatsapp.cloud.phone_number_id' => '123456',
            'whatsapp.undelivered_alert_minutes' => 60,
        ]);
    }

    // ── Le compteur ──────────────────────────────────────────────────────────

    public function test_a_fiche_accepted_but_never_delivered_is_counted(): void
    {
        $this->fiche(WhatsappSendLog::DELIVERY_ACCEPTED, now()->subHours(3));

        $this->assertSame(
            1,
            app(WhatsappOutboxService::class)->undeliveredCount(),
            'une fiche acceptée sans livraison depuis 3 h reste invisible',
        );
    }

    public function test_a_fiche_accepted_moments_ago_is_not_counted(): void
    {
        // La livraison arrive normalement en quelques secondes. Compter
        // immédiatement produirait une alerte permanente, donc ignorée.
        $this->fiche(WhatsappSendLog::DELIVERY_ACCEPTED, now()->subMinutes(2));

        $this->assertSame(0, app(WhatsappOutboxService::class)->undeliveredCount());
    }

    public function test_delivered_and_read_fiches_are_never_counted(): void
    {
        $this->fiche(WhatsappSendLog::DELIVERY_DELIVERED, now()->subDay());
        $this->fiche(WhatsappSendLog::DELIVERY_READ, now()->subDay());

        $this->assertSame(0, app(WhatsappOutboxService::class)->undeliveredCount());
    }

    public function test_a_failed_fiche_is_not_counted_twice(): void
    {
        // Un échec de livraison a déjà son chemin : `markFailed`, statut
        // `failed`, alerte. Le recompter ici doublerait le signal.
        $this->fiche(WhatsappSendLog::DELIVERY_FAILED, now()->subDay());

        $this->assertSame(0, app(WhatsappOutboxService::class)->undeliveredCount());
    }

    public function test_a_fiche_left_at_sent_by_meta_also_counts(): void
    {
        // `sent` chez Meta veut dire « parti vers l'appareil ». Ce n'est pas
        // une livraison : figé là depuis des heures, c'est le même trou.
        $this->fiche(WhatsappSendLog::DELIVERY_SENT, now()->subHours(5));

        $this->assertSame(1, app(WhatsappOutboxService::class)->undeliveredCount());
    }

    public function test_a_test_fiche_is_not_counted(): void
    {
        // Le bouton « message test » de l'administration ne porte aucune
        // obligation légale : l'alerter brouillerait le signal.
        $job = $this->fiche(WhatsappSendLog::DELIVERY_ACCEPTED, now()->subHours(3));
        $job->forceFill(['is_test' => true])->save();

        $this->assertSame(0, app(WhatsappOutboxService::class)->undeliveredCount());
    }

    // ── La visibilité ────────────────────────────────────────────────────────

    public function test_the_admin_health_screen_shows_the_undelivered_count(): void
    {
        $this->fiche(WhatsappSendLog::DELIVERY_ACCEPTED, now()->subHours(3));

        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/whatsapp/health')
            ->assertOk()
            ->assertJsonPath('data.queue.undelivered', 1);
    }

    // ── L'alerte ─────────────────────────────────────────────────────────────

    public function test_the_scheduled_check_alerts_once_and_not_on_every_pass(): void
    {
        $this->fiche(WhatsappSendLog::DELIVERY_ACCEPTED, now()->subHours(3));
        // L'alerte part vers les administrateurs plateforme : sans destinataire,
        // rien n'est envoye et le test ne prouverait rien.
        User::factory()->platformAdmin()->create();

        $this->artisan('whatsapp:check-health')->assertExitCode(0);
        $sent = $this->undeliveredAlerts();
        $this->assertGreaterThan(0, $sent, 'aucune alerte pour une fiche jamais livrée');

        // Le drapeau porte le NOMBRE déjà signalé : c'est lui qui empêche une
        // alerte toutes les dix minutes pendant des jours. Répétée, elle
        // apprendrait aux administrateurs à l'ignorer — donc à ne pas alerter.
        $this->assertSame(1, (int) Cache::get('whatsapp:undelivered-alerted'));
    }

    public function test_an_already_reported_situation_does_not_alert_again(): void
    {
        /*
         * On arme le drapeau au nombre courant, comme l'aurait laissé un
         * passage précédent. Enchaîner deux `artisan()` ne prouverait rien ici :
         * le cache « array » des tests ne survit pas d'une invocation à
         * l'autre, et le test mesurerait le harnais au lieu du code.
         */
        $this->fiche(WhatsappSendLog::DELIVERY_ACCEPTED, now()->subHours(3));
        User::factory()->platformAdmin()->create();
        Cache::put('whatsapp:undelivered-alerted', 1, now()->addDay());

        $this->artisan('whatsapp:check-health')->assertExitCode(0);

        $this->assertSame(0, $this->undeliveredAlerts());
    }

    public function test_a_worsening_situation_alerts_again(): void
    {
        // Deux fiches non livrées ne sont pas la même information qu'une seule :
        // une aggravation doit reparler.
        $this->fiche(WhatsappSendLog::DELIVERY_ACCEPTED, now()->subHours(3));
        $this->fiche(WhatsappSendLog::DELIVERY_ACCEPTED, now()->subHours(4));
        User::factory()->platformAdmin()->create();
        Cache::put('whatsapp:undelivered-alerted', 1, now()->addDay());

        $this->artisan('whatsapp:check-health')->assertExitCode(0);

        $this->assertGreaterThan(0, $this->undeliveredAlerts(), 'une aggravation est restée silencieuse');
    }

    /**
     * Le point qui manquait avant ce correctif : savoir COMBIEN de fiches
     * traînent ne dit pas QUI ne les reçoit pas. L'administrateur devait
     * rouvrir le journal WhatsApp et repérer la ligne figée à la main — cette
     * information, l'email doit désormais la porter lui-même.
     */
    public function test_the_alert_names_the_stuck_authority_agent(): void
    {
        $org = AuthorityOrganization::create([
            'name' => 'Poste de garde nationale — Hammamet',
            'type' => 'police',
        ]);
        $agent = User::factory()->create(['first_name' => 'Fares', 'last_name' => 'Ben Salah']);
        AuthorityUserProfile::create([
            'user_id' => $agent->id,
            'organization_id' => $org->id,
            'whatsapp_number' => '+216 20 123 456',
            'receives_whatsapp_fiches' => true,
        ]);

        $job = $this->fiche(WhatsappSendLog::DELIVERY_ACCEPTED, now()->subHours(3));
        $job->forceFill(['recipient' => '21620123456'])->save();

        User::factory()->platformAdmin()->create();

        $this->artisan('whatsapp:check-health')->assertExitCode(0);

        $mails = Mail::sent(
            SystemMail::class,
            fn (SystemMail $mail) => str_contains($mail->renderedSubject, 'jamais livrees'),
        );

        $this->assertGreaterThan(0, $mails->count());
        $mails->each(function (SystemMail $mail) {
            $this->assertStringContainsString('Fares Ben Salah', $mail->renderedHtml);
            $this->assertStringContainsString('Poste de garde nationale', $mail->renderedHtml);
            $this->assertStringContainsString('20123456', $mail->renderedHtml);
        });
    }

    public function test_no_alert_when_everything_was_delivered(): void
    {
        $this->fiche(WhatsappSendLog::DELIVERY_DELIVERED, now()->subDay());
        User::factory()->platformAdmin()->create();

        $this->artisan('whatsapp:check-health')->assertExitCode(0);

        // On ne compte QUE cette alerte : un test sans battement de coeur
        // declenche aussi, et legitimement, l'alerte « worker silencieux ».
        $this->assertSame(0, $this->undeliveredAlerts());
    }

    // ── Utilitaire ───────────────────────────────────────────────────────────

    /** Nombre d'alertes « fiches jamais livrees » reellement parties. */
    private function undeliveredAlerts(): int
    {
        return count(Mail::sent(
            SystemMail::class,
            fn (SystemMail $mail) => str_contains($mail->renderedSubject, 'jamais livrees'),
        ));
    }

    private function fiche(string $deliveryStatus, Carbon $sentAt): WhatsappSendLog
    {
        return WhatsappSendLog::create([
            'hotel_id' => $this->hotel->id,
            'recipient' => '21620123456',
            'caption' => 'Fiche synthétique',
            'status' => WhatsappSendLog::STATUS_SENT,
            'delivery_status' => $deliveryStatus,
            'message_id_whatsapp' => 'wamid.'.uniqid(),
            'template_name' => 'fiche_police_v2',
            'queued_at' => $sentAt->copy()->subMinute(),
            'sent_at' => $sentAt,
        ]);
    }
}
