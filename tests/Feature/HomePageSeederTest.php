<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Page;
use Database\Seeders\HomePageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bascule de version de la page d'accueil CMS.
 *
 * Le seeder tourne à chaque démarrage du conteneur : il doit rattraper une
 * page encore identique à un seed précédent, mais ne jamais écraser le travail
 * d'un administrateur.
 */
class HomePageSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seed(): void
    {
        (new HomePageSeeder())->run();
    }

    /** Contenu v1 (extrait suffisant) tel qu'il était stocké avant la refonte. */
    private function legacyContentFingerprintSample(): array
    {
        // On ne rejoue pas la v1 ici : ce que le test vérifie, c'est le
        // comportement face à un contenu INCONNU du seeder (donc édité).
        return ['root' => ['props' => ['title' => 'Qayed']], 'content' => [
            ['type' => 'Hero', 'props' => ['id' => 'Hero-home-1', 'description' => 'Texte réécrit à la main dans l\'admin.']],
        ]];
    }

    public function test_creates_and_publishes_the_home_page(): void
    {
        $this->seed();

        $page = Page::where('slug', 'home')->firstOrFail();
        $this->assertSame('published', $page->status);
        $this->assertNotEmpty($page->content['fr']['content']);
        $this->assertSame('Qayed — Enregistrez vos voyageurs en 30 secondes', $page->meta['fr']['title']);
    }

    public function test_seeding_twice_is_idempotent(): void
    {
        $this->seed();
        $before = Page::where('slug', 'home')->firstOrFail();
        $updatedAt = $before->updated_at;

        $this->seed();

        $after = Page::where('slug', 'home')->firstOrFail();
        $this->assertEquals($updatedAt, $after->updated_at);
    }

    public function test_never_overwrites_a_page_edited_in_the_admin(): void
    {
        $this->seed();
        $page = Page::where('slug', 'home')->firstOrFail();
        $page->update(['content' => ['fr' => $this->legacyContentFingerprintSample()]]);

        $this->seed();

        $page->refresh();
        $this->assertSame(
            'Texte réécrit à la main dans l\'admin.',
            $page->content['fr']['content'][0]['props']['description'],
        );
    }

    public function test_refresh_command_forces_the_seeder_content_back(): void
    {
        $this->seed();
        Page::where('slug', 'home')->firstOrFail()
            ->update(['content' => ['fr' => $this->legacyContentFingerprintSample()]]);

        $this->artisan('qayed:refresh-home', ['--force' => true])->assertSuccessful();

        $page = Page::where('slug', 'home')->firstOrFail();
        $this->assertSame(
            HomePageSeeder::fingerprint((new HomePageSeeder())->homeAttributes()['content']),
            HomePageSeeder::fingerprint($page->content),
        );
    }

    public function test_fingerprint_ignores_key_order(): void
    {
        $a = ['content' => [['type' => 'Hero', 'props' => ['id' => 'x', 'title' => 'T']]]];
        $b = ['content' => [['props' => ['title' => 'T', 'id' => 'x'], 'type' => 'Hero']]];

        $this->assertSame(HomePageSeeder::fingerprint($a), HomePageSeeder::fingerprint($b));
    }

    public function test_home_content_carries_no_emoji_and_no_authority_claim(): void
    {
        $this->seed();
        $json = json_encode(Page::where('slug', 'home')->firstOrFail()->content, JSON_UNESCAPED_UNICODE);

        $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', $json), 'La homepage ne doit contenir aucun emoji.');
        foreach (['Ministère', 'Interpol', 'watchlist', 'UW AGENCY'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "« {$forbidden} » ne doit plus figurer sur la landing.");
        }
    }

    public function test_renames_the_legacy_security_menu_entry(): void
    {
        MenuItem::create([
            'location' => 'navbar',
            'label' => ['fr' => 'Sécurité', 'en' => 'Security', 'ar' => 'الأمان'],
            'external_url' => '/#securite',
            'sort_order' => 3,
        ]);

        $this->seed();

        $item = MenuItem::where('location', 'navbar')->firstOrFail();
        $this->assertSame('/#conformite', $item->external_url);
        $this->assertSame('Conformité', $item->label['fr']);
    }
}
