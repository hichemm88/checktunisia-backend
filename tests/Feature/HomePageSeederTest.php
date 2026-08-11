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
        $this->seed(HomePageSeeder::class);

        $page = Page::where('slug', 'home')->firstOrFail();
        $this->assertSame('published', $page->status);
        $this->assertNotEmpty($page->content['fr']['content']);
        $this->assertSame('Qayed — Enregistrez vos voyageurs en 30 secondes', $page->meta['fr']['title']);
    }

    public function test_seeding_twice_is_idempotent(): void
    {
        $this->seed(HomePageSeeder::class);
        $before = Page::where('slug', 'home')->firstOrFail();
        $updatedAt = $before->updated_at;

        $this->seed(HomePageSeeder::class);

        $after = Page::where('slug', 'home')->firstOrFail();
        $this->assertEquals($updatedAt, $after->updated_at);
    }

    public function test_never_overwrites_a_page_edited_in_the_admin(): void
    {
        $this->seed(HomePageSeeder::class);
        $page = Page::where('slug', 'home')->firstOrFail();
        $page->update(['content' => ['fr' => $this->legacyContentFingerprintSample()]]);

        $this->seed(HomePageSeeder::class);

        $page->refresh();
        $this->assertSame(
            'Texte réécrit à la main dans l\'admin.',
            $page->content['fr']['content'][0]['props']['description'],
        );
    }

    public function test_refresh_command_forces_the_seeder_content_back(): void
    {
        $this->seed(HomePageSeeder::class);
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

    /**
     * Ce qui ne doit pas revenir sur la landing. La section « autorités » et
     * ses mentions du Ministère, elles, sont assumées : décision produit
     * explicite après la v2 (cf. en-tête du seeder).
     */
    public function test_home_content_carries_no_emoji_and_no_invented_testimonial(): void
    {
        $this->seed(HomePageSeeder::class);
        $json = json_encode(Page::where('slug', 'home')->firstOrFail()->content, JSON_UNESCAPED_UNICODE);

        $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}]/u', $json), 'La homepage ne doit contenir aucun emoji.');
        foreach (['Mohamed Karray', 'Sarra Ben Amor', 'Riadh Ayari', 'UW AGENCY'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "« {$forbidden} » ne doit pas figurer sur la landing.");
        }

        $types = array_column(Page::where('slug', 'home')->firstOrFail()->content['fr']['content'], 'type');
        $this->assertNotContains('Testimonials', $types, 'Aucun bloc de témoignages tant qu\'il n\'y a pas de témoignage réel.');
    }

    /** La v2 avait renommé l'entrée ; la section sombre a repris l'ancre #securite. */
    public function test_restores_the_security_menu_entry_renamed_by_v2(): void
    {
        MenuItem::create([
            'location' => 'navbar',
            'label' => ['fr' => 'Conformité', 'en' => 'Compliance', 'ar' => 'الامتثال'],
            'external_url' => '/#conformite',
            'sort_order' => 3,
        ]);

        $this->seed(HomePageSeeder::class);

        $item = MenuItem::where('location', 'navbar')->firstOrFail();
        $this->assertSame('/#securite', $item->external_url);
        $this->assertSame('Sécurité', $item->label['fr']);
    }

    /** Une entrée renommée à la main dans l'admin ne doit pas être écrasée. */
    public function test_leaves_a_hand_edited_menu_entry_alone(): void
    {
        MenuItem::create([
            'location' => 'navbar',
            'label' => ['fr' => 'Nos garanties', 'en' => 'Our guarantees', 'ar' => 'ضماناتنا'],
            'external_url' => '/#conformite',
            'sort_order' => 3,
        ]);

        $this->seed(HomePageSeeder::class);

        $this->assertSame('Nos garanties', MenuItem::where('location', 'navbar')->firstOrFail()->label['fr']);
    }
}
