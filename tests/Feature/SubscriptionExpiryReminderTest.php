<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Hotel;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\SubscriptionExpiringNotification;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Rappels d'échéance d'abonnement (`subscriptions:notify-expiring`).
 *
 * Depuis que l'abonnement est porté par l'ORGANISATION, une souscription née
 * de l'inscription publique n'a plus de `hotel_id` — il n'est jamais
 * renseigné après coup. La commande partait pourtant du principe inverse et
 * s'arrêtait net sur le premier client concerné : plus aucun rappel
 * d'échéance, et plus aucun rappel de fin d'essai non plus, puisqu'ils sont
 * envoyés par la même exécution.
 *
 * C'est une panne silencieuse : le serveur web répond normalement, la tâche
 * planifiée échoue chaque jour sans que personne ne le voie, et le client
 * découvre son expiration le jour où ses check-ins sont bloqués.
 */
class SubscriptionExpiryReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
    }

    /**
     * Organisation + établissement + gestionnaire, avec un abonnement porté
     * par l'organisation — donc `hotel_id` null, comme après une inscription.
     *
     * @return array{0: Organization, 1: Hotel, 2: User, 3: Subscription}
     */
    private function orgAccount(string $name, array $subAttrs = []): array
    {
        $org = Organization::create([
            'name' => $name, 'entity_type' => 'company',
            'contact_email' => strtolower(str_replace(' ', '-', $name)).'@test.tn',
            'status' => 'active', 'locale' => 'fr',
        ]);
        $hotel = Hotel::factory()->create(['organization_id' => $org->id]);
        $admin = User::factory()->hotelAdmin($hotel)->create([
            'organization_id' => $org->id, 'role_org' => 'owner',
        ]);

        $sub = Subscription::create(array_merge([
            'organization_id' => $org->id,
            // hotel_id volontairement absent : c'est l'état réel des
            // abonnements créés par l'inscription publique.
            'plan_id'         => (int) SubscriptionPlan::where('slug', 'essentiel')->value('id'),
            'status'          => 'active',
            'billing_cycle'   => 'monthly',
            'started_at'      => now()->subDays(23),
            'expires_at'      => now()->addDays(7),
            'auto_renew'      => true,
        ], $subAttrs));

        return [$org, $hotel, $admin, $sub];
    }

    public function test_an_org_level_subscription_still_warns_its_manager(): void
    {
        Notification::fake();
        [, , $admin, $sub] = $this->orgAccount('Client Org');

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        Notification::assertSentTo($admin, SubscriptionExpiringNotification::class);
        $this->assertSame(
            1,
            AuditLog::where('action', 'subscription.reminder_sent')->count(),
            'le rappel doit être tracé une fois',
        );
        $this->assertNull($sub->fresh()->hotel_id, 'la commande ne doit rien réécrire sur l\'abonnement');
    }

    /**
     * Tous les gestionnaires de l'organisation sont prévenus, pas seulement
     * ceux du premier établissement : l'abonnement couvre l'organisation
     * entière, et c'est elle qui paie.
     */
    public function test_every_manager_of_the_organization_is_warned(): void
    {
        Notification::fake();
        [$org, , $admin] = $this->orgAccount('Multi Sites');

        $second = Hotel::factory()->create(['organization_id' => $org->id]);
        $otherAdmin = User::factory()->hotelAdmin($second)->create([
            'organization_id' => $org->id, 'role_org' => 'admin',
        ]);

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        Notification::assertSentTo($admin, SubscriptionExpiringNotification::class);
        Notification::assertSentTo($otherAdmin, SubscriptionExpiringNotification::class);
    }

    /**
     * Les rappels de fin d'essai partent dans la MÊME exécution. Une erreur
     * sur la branche payante les emportait tous avec elle — c'est ce que ce
     * test verrouille.
     */
    public function test_a_paid_reminder_never_swallows_the_trial_reminders(): void
    {
        Notification::fake();
        Mail::fake();

        $this->orgAccount('Client Payant');
        $this->orgAccount('Essai En Cours', [
            'status'     => 'trial',
            'expires_at' => now()->addDays(2),
            'metadata'   => ['trial' => true],
        ]);

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        $this->assertSame(
            1,
            AuditLog::where('action', 'subscription.trial_reminder_sent')->count(),
            'le rappel d\'essai doit partir même quand un client payant est dans la fenêtre',
        );
    }

    /**
     * Un abonnement historique porté par l'établissement (organization_id
     * null) continue d'être traité — la correction ne doit pas déplacer le
     * problème sur les données anciennes.
     */
    public function test_a_legacy_hotel_level_subscription_is_still_warned(): void
    {
        Notification::fake();

        $hotel = Hotel::factory()->create(['organization_id' => null]);
        $admin = User::factory()->hotelAdmin($hotel)->create();

        Subscription::create([
            'hotel_id'      => $hotel->id,
            'plan_id'       => (int) SubscriptionPlan::where('slug', 'essentiel')->value('id'),
            'status'        => 'active',
            'billing_cycle' => 'monthly',
            'started_at'    => now()->subDays(23),
            'expires_at'    => now()->addDays(7),
            'auto_renew'    => true,
        ]);

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        Notification::assertSentTo($admin, SubscriptionExpiringNotification::class);
    }

    /**
     * Le bouton du rappel doit mener à l'écran d'abonnement du site, pas au
     * domaine de l'API : `url()` compose une adresse sur APP_URL, où aucune
     * page n'existe. Le client cliquait dans le vide.
     */
    public function test_the_reminder_links_to_the_subscription_screen_of_the_site(): void
    {
        [, , $admin, $sub] = $this->orgAccount('Lien Correct');

        $mail = (new SubscriptionExpiringNotification('Lien Correct', $sub, 7))->toMail($admin);

        $this->assertSame(
            \App\Services\Email\SystemMailer::frontendUrl('/hotel/subscription'),
            $mail->actionUrl,
        );
    }
}
