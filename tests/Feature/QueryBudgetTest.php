<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\TravelDocument;
use App\Models\User;
use App\Models\WhatsappSendLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Budget de requêtes des écrans les plus consultés.
 *
 * ── Ce que ce fichier mesure, et ce qu'il ne mesure pas ─────────────────
 *
 * Il ne mesure pas un temps : une durée dépend de la machine et rendrait la
 * suite instable. Il mesure le NOMBRE DE REQUÊTES, qui est la propriété
 * structurelle — un N+1 s'y voit immédiatement, et il s'y voit *avant* d'être
 * douloureux en production.
 *
 * ── Pourquoi un plafond plutôt qu'un nombre exact ───────────────────────
 *
 * Un `assertSame(17, ...)` casserait à chaque ajout légitime d'un compteur, et
 * se ferait « corriger » en montant le chiffre — jusqu'à ne plus rien
 * protéger. Un PLAFOND, lui, laisse respirer les évolutions normales et n'est
 * franchi que par une régression de forme : une requête par ligne.
 *
 * Le jeu de données est volontairement ASYMÉTRIQUE entre les tests : chaque
 * budget est vérifié avec assez de lignes pour qu'un N+1 dépasse le plafond.
 * Un test à trois lignes ne prouverait rien — c'est précisément ainsi qu'un
 * N+1 passe inaperçu.
 *
 * Données strictement synthétiques.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::factory()->withActiveSubscription()->create(['room_count' => 20]);
        $this->admin = User::factory()->hotelAdmin($this->hotel)->create();
    }

    /** @return array{count:int,queries:array<int,string>} */
    private function measure(callable $call): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return ['count' => count($queries), 'queries' => array_column($queries, 'query')];
    }

    private function assertWithinBudget(int $budget, array $measured, string $screen): void
    {
        $this->assertLessThanOrEqual(
            $budget,
            $measured['count'],
            sprintf(
                "%s : %d requêtes pour un budget de %d.\nUne requête par ligne est le symptôme à chercher.\n%s",
                $screen,
                $measured['count'],
                $budget,
                implode("\n", array_slice($measured['queries'], 0, 25)),
            ),
        );
    }

    // ── Tableau de bord hôtelier ─────────────────────────────────────────────

    public function test_the_hotel_dashboard_does_not_scale_with_the_number_of_stays(): void
    {
        $this->stays(30);

        $measured = $this->measure(fn () => $this->actingAs($this->admin)
            ->getJson('/api/v1/hotel/dashboard')->assertOk());

        // 30 séjours, chacun avec voyageur, document et chambre : un N+1 sur
        // l'une de ces relations dépasserait largement ce plafond.
        $this->assertWithinBudget(45, $measured, 'Tableau de bord hôtelier');
    }

    public function test_the_stay_list_does_not_scale_with_its_rows(): void
    {
        $this->stays(30);

        $measured = $this->measure(fn () => $this->actingAs($this->admin)
            ->getJson('/api/v1/hotel/check-ins?per_page=30')->assertOk());

        $this->assertWithinBudget(20, $measured, 'Liste des séjours');
    }

    // ── Portail autorité ─────────────────────────────────────────────────────

    public function test_the_authority_search_does_not_scale_with_its_results(): void
    {
        $this->stays(30);
        $officer = $this->officer();

        $measured = $this->measure(fn () => $this->actingAs($officer)
            ->getJson('/api/v1/authority/search?last_name=SYNTH')->assertOk());

        $this->assertWithinBudget(25, $measured, 'Recherche autorité');
    }

    // ── Administration ───────────────────────────────────────────────────────

    public function test_the_whatsapp_inbox_does_not_scale_with_its_threads(): void
    {
        // L'écran que je viens d'ajouter : il résout un agent et une
        // organisation par fil. Sans eager loading, c'est deux requêtes par
        // ligne — le N+1 classique d'une boîte de réception.
        $this->conversations(25);
        $platformAdmin = User::factory()->platformAdmin()->create();

        $measured = $this->measure(fn () => $this->actingAs($platformAdmin)
            ->getJson('/api/v1/admin/whatsapp/inbox')->assertOk());

        $this->assertWithinBudget(15, $measured, 'Boîte de réception autorités');
    }

    public function test_the_whatsapp_health_screen_stays_flat(): void
    {
        // Compteurs d'agrégat uniquement, dont `undelivered` ajouté en P5 :
        // ils ne doivent jamais parcourir les lignes.
        $this->sendLogs(40);
        $platformAdmin = User::factory()->platformAdmin()->create();

        $measured = $this->measure(fn () => $this->actingAs($platformAdmin)
            ->getJson('/api/v1/admin/whatsapp/health')->assertOk());

        $this->assertWithinBudget(20, $measured, 'Santé WhatsApp');
    }

    public function test_the_whatsapp_log_does_not_scale_with_its_rows(): void
    {
        $this->sendLogs(40);
        $platformAdmin = User::factory()->platformAdmin()->create();

        $measured = $this->measure(fn () => $this->actingAs($platformAdmin)
            ->getJson('/api/v1/admin/whatsapp/logs?per_page=40')->assertOk());

        $this->assertWithinBudget(20, $measured, 'Journal WhatsApp');
    }

    // ── Fixtures synthétiques ────────────────────────────────────────────────

    private function stays(int $n): void
    {
        $rooms = Room::factory()->count($n)->for($this->hotel)->create();

        foreach (range(1, $n) as $i) {
            $checkIn = CheckIn::factory()->for($this->hotel)->create([
                'created_by' => $this->admin->id,
                'status' => $i % 3 === 0 ? 'completed' : 'active',
                'room_id' => $rooms[$i - 1]->id,
                'check_in_date' => today()->subDays($i % 10),
                'expected_check_out_date' => today()->addDays(2),
                'actual_check_out_date' => $i % 3 === 0 ? today() : null,
            ]);

            $guest = Guest::factory()->create(['last_name' => 'SYNTH'.$i]);
            TravelDocument::create([
                'guest_id' => $guest->id,
                'type' => 'passport',
                'document_number' => 'SYN'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'issuing_country_code' => 'TUN',
            ]);
            $checkIn->guests()->attach($guest->id, ['is_primary' => true, 'added_by' => $this->admin->id]);
        }
    }

    private function sendLogs(int $n): void
    {
        foreach (range(1, $n) as $i) {
            WhatsappSendLog::create([
                'hotel_id' => $this->hotel->id,
                'recipient' => '2166000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'caption' => 'Fiche synthétique',
                'status' => WhatsappSendLog::STATUS_SENT,
                'delivery_status' => WhatsappSendLog::DELIVERY_ACCEPTED,
                'message_id_whatsapp' => 'wamid.SYN'.$i,
                'template_name' => 'fiche_police_v2',
                'queued_at' => now()->subHours(5),
                'sent_at' => now()->subHours(5),
            ]);
        }
    }

    private function conversations(int $n): void
    {
        foreach (range(1, $n) as $i) {
            $phone = '2166100'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);

            $org = AuthorityOrganization::create([
                'name' => 'Poste synthétique '.$i, 'type' => 'police', 'is_active' => true,
            ]);
            $agent = User::factory()->authorityUser($org)->create();
            AuthorityUserProfile::firstOrCreate(
                ['user_id' => $agent->id],
                ['organization_id' => $org->id, 'whatsapp_number' => $phone, 'authorized_at' => now()],
            );

            \App\Models\WhatsappConversation::create([
                'phone' => $phone,
                'authority_user_profile_id' => AuthorityUserProfile::where('user_id', $agent->id)->value('id'),
                'last_message_at' => now()->subMinutes($i),
                'last_message_direction' => 'inbound',
                'last_message_preview' => 'Message synthétique',
                'unread_count' => $i % 4,
            ]);
        }
    }

    private function officer(): User
    {
        $org = AuthorityOrganization::create([
            'name' => 'Ministère (mesure)', 'type' => 'ministry', 'is_active' => true,
        ]);
        $officer = User::factory()->authorityUser($org)->create();
        AuthorityUserProfile::firstOrCreate(
            ['user_id' => $officer->id],
            ['organization_id' => $org->id, 'authorized_at' => now()],
        );
        $officer->forceFill([
            'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $officer;
    }
}
