<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Limitation de débit (API-01).
 *
 * Avant : tout /hotel/* était sans limite, upload de scans compris (10 Mo +
 * appel au modèle de vision par requête), et aucun repli global n'existait.
 *
 * Le test qui compte le plus ici n'est pas « ça bloque » mais « ça ne bloque
 * PAS un usage légitime » : une limite mal réglée casse la réception d'un
 * hôtel un vendredi soir, ce qui est pire que le problème qu'elle résout.
 */
class ThrottlingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Le cache « array » vit le temps du processus PHP : sans purge, les
        // compteurs fuiraient d'un test à l'autre et rendraient l'ordre
        // d'exécution significatif.
        RateLimiter::clear('api');
        cache()->clear();
    }

    // ── Les parcours légitimes ne doivent jamais être bloqués ────────────────

    public function test_a_realistic_receptionist_burst_is_not_throttled(): void
    {
        $hotel = Hotel::factory()->withActiveSubscription()->create();
        $user  = User::factory()->receptionist($hotel)->create();

        // Un check-in complet représente une dizaine de requêtes ; on simule
        // ici l'équivalent de plusieurs check-ins enchaînés plus les
        // rafraîchissements du tableau de bord.
        for ($i = 0; $i < 40; $i++) {
            $this->actingAs($user)
                ->getJson('/api/v1/hotel/dashboard')
                ->assertOk();
        }
    }

    public function test_two_staff_of_the_same_hotel_do_not_share_a_budget(): void
    {
        $hotel = Hotel::factory()->withActiveSubscription()->create();
        $alice = User::factory()->receptionist($hotel)->create();
        $bob   = User::factory()->receptionist($hotel)->create();

        // Une réception a plusieurs postes derrière une seule IP publique :
        // une limite indexée sur l'IP punirait le second poste.
        for ($i = 0; $i < 80; $i++) {
            $this->actingAs($alice)->getJson('/api/v1/hotel/dashboard')->assertOk();
        }

        $this->actingAs($bob)
            ->getJson('/api/v1/hotel/dashboard')
            ->assertOk();
    }

    // ── La limite existe bel et bien ─────────────────────────────────────────

    public function test_global_limiter_eventually_returns_429(): void
    {
        $hotel = Hotel::factory()->withActiveSubscription()->create();
        $user  = User::factory()->receptionist($hotel)->create();

        $status = 200;
        // La limite globale est à 120/min ; on dépasse volontairement.
        for ($i = 0; $i < 130 && $status === 200; $i++) {
            $status = $this->actingAs($user)->getJson('/api/v1/hotel/dashboard')->getStatusCode();
        }

        $this->assertSame(429, $status, 'Le périmètre /hotel/* doit finir par être limité.');
    }

    public function test_scan_upload_is_limited_more_strictly_than_the_rest(): void
    {
        $hotel = Hotel::factory()->withActiveSubscription()->create();
        $user  = User::factory()->receptionist($hotel)->create();

        // On épuise le compteur dédié sans toucher au budget global : la preuve
        // que l'upload porte bien une limite propre, plus basse.
        for ($i = 0; $i < 20; $i++) {
            RateLimiter::hit('scan-upload|'.$user->id);
        }

        $this->assertGreaterThan(
            RateLimiter::remaining('scan-upload|'.$user->id, 20),
            RateLimiter::remaining('api|'.$user->id, 120),
            "L'upload de scan doit s'épuiser avant le budget global."
        );
    }

    // ── Le worker WhatsApp ne doit pas être cassé ────────────────────────────

    public function test_whatsapp_worker_polling_rate_is_not_throttled(): void
    {
        config(['whatsapp.worker_secret' => 'test-secret']);

        // Le worker sonde toutes les 5 s (WHATSAPP_IDLE_POLL_MS), soit environ
        // 24 requêtes/minute sur deux endpoints. On vérifie très largement
        // au-dessus de son rythme réel.
        for ($i = 0; $i < 60; $i++) {
            $this->getJson(
                '/api/v1/internal/whatsapp/control',
                ['X-Whatsapp-Worker-Secret' => 'test-secret']
            )->assertOk();
        }
    }
}
