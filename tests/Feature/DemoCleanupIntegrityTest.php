<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\TravelDocument;
use App\Models\User;
use App\Models\WatchlistEntry;
use App\Models\WatchlistHit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La purge des fiches de démonstration, face à ce qu'une fiche produit
 * réellement autour d'elle.
 *
 * ── Le point d'achoppement ──────────────────────────────────────────────
 *
 * `demo:cleanup-dar-el-kenz` supprime PHYSIQUEMENT les fiches marquées. Les
 * enfants de `check_ins` n'ont pas tous la même règle :
 *
 *   check_in_guests, document_scans, checkin_usage_events  → CASCADE
 *   app_notifications, whatsapp_send_log                   → SET NULL
 *   watchlist_hits                                         → NO ACTION
 *
 * Une fiche de démonstration qui a déclenché une alerte de watchlist porte
 * donc un enfant qui REFUSE la suppression. La transaction est correctement
 * posée — elle annule tout et la base reste intacte — mais la commande ne peut
 * alors plus jamais aboutir : la purge est bloquée, définitivement, par une
 * donnée qu'elle a elle-même produite.
 *
 * Ce n'est pas une corruption : c'est un outil de maintenance qui cesse de
 * fonctionner au moment précis où l'on en a besoin, avec un message d'erreur
 * qui parle de clef étrangère et non de ce qu'il faut faire.
 */
class DemoCleanupIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'SEED-DEMO-2026';

    private Hotel $hotel;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Le nom est le critère de résolution de la commande : il doit être exact.
        $this->hotel = Hotel::factory()->create(['name' => 'Dar el Kenz']);
        $this->admin = User::factory()->hotelAdmin($this->hotel)->create();
    }

    public function test_the_purge_removes_a_plain_demo_fiche(): void
    {
        [$checkIn] = $this->demoFiche();

        $this->artisan('demo:cleanup-dar-el-kenz --commit')->assertExitCode(0);

        $this->assertSame(0, CheckIn::withTrashed()->whereKey($checkIn->id)->count());
    }

    public function test_the_purge_survives_a_demo_fiche_that_raised_an_alert(): void
    {
        /*
         * Le cas réel : le seeder crée des voyageurs, l'un d'eux correspond à
         * une entrée de watchlist, une alerte naît. La fiche porte alors un
         * enfant en NO ACTION.
         */
        [$checkIn, $guest] = $this->demoFiche();
        $this->alertOn($checkIn, $guest);

        $this->artisan('demo:cleanup-dar-el-kenz --commit')->assertExitCode(0);

        $this->assertSame(0, CheckIn::withTrashed()->whereKey($checkIn->id)->count(), 'la fiche de démo est restée');
        $this->assertSame(0, WatchlistHit::where('check_in_id', $checkIn->id)->count(), 'l\'alerte de démo est restée');
    }

    public function test_the_purge_never_touches_a_real_fiche_or_its_alert(): void
    {
        // Contre-épreuve : la commande ne doit emporter que ce qui porte le
        // marqueur. Une alerte réelle survit, même dans le même établissement.
        [$demo, $demoGuest] = $this->demoFiche();
        $this->alertOn($demo, $demoGuest);

        $realGuest = Guest::factory()->create(['last_name' => 'REEL']);
        TravelDocument::create([
            'guest_id' => $realGuest->id,
            'type' => 'passport',
            'document_number' => 'REEL-0001',
            'issuing_country_code' => 'TUN',
        ]);
        $real = CheckIn::factory()->for($this->hotel)->create([
            'created_by' => $this->admin->id,
            'status' => 'active',
        ]);
        $real->guests()->attach($realGuest->id, ['is_primary' => true, 'added_by' => $this->admin->id]);
        $realHit = $this->alertOn($real, $realGuest);

        $this->artisan('demo:cleanup-dar-el-kenz --commit')->assertExitCode(0);

        $this->assertSame(1, CheckIn::whereKey($real->id)->count(), 'une fiche réelle a été supprimée');
        $this->assertSame(1, WatchlistHit::whereKey($realHit->id)->count(), 'une alerte réelle a été supprimée');
        $this->assertSame(1, Guest::whereKey($realGuest->id)->count());
    }

    public function test_a_dry_run_still_deletes_nothing(): void
    {
        [$checkIn, $guest] = $this->demoFiche();
        $this->alertOn($checkIn, $guest);

        $this->artisan('demo:cleanup-dar-el-kenz')->assertExitCode(0);

        $this->assertSame(1, CheckIn::withTrashed()->whereKey($checkIn->id)->count());
        $this->assertSame(1, WatchlistHit::where('check_in_id', $checkIn->id)->count());
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** @return array{0:CheckIn,1:Guest} */
    private function demoFiche(): array
    {
        $guest = Guest::factory()->create([
            'last_name' => 'DEMO',
            'metadata' => ['seed_batch' => self::MARKER],
        ]);
        TravelDocument::create([
            'guest_id' => $guest->id,
            'type' => 'passport',
            'document_number' => 'DEMO-0001',
            'issuing_country_code' => 'TUN',
            'metadata' => ['seed_batch' => self::MARKER],
        ]);

        $checkIn = CheckIn::factory()->for($this->hotel)->create([
            'created_by' => $this->admin->id,
            'status' => 'active',
            'metadata' => ['seed_batch' => self::MARKER],
        ]);
        $checkIn->guests()->attach($guest->id, ['is_primary' => true, 'added_by' => $this->admin->id]);

        return [$checkIn, $guest];
    }

    private function alertOn(CheckIn $checkIn, Guest $guest): WatchlistHit
    {
        $entry = WatchlistEntry::factory()->create(['added_by' => $this->admin->id]);

        return WatchlistHit::factory()->create([
            'watchlist_entry_id' => $entry->id,
            'guest_id' => $guest->id,
            'check_in_id' => $checkIn->id,
            'hotel_id' => $this->hotel->id,
        ]);
    }
}
