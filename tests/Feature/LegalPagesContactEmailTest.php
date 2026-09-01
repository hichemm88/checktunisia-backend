<?php

namespace Tests\Feature;

use App\Models\Page;
use Database\Seeders\LegalPagesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Adresse de contact des pages légales.
 *
 * Les CGV et la politique de confidentialité désignent une adresse pour
 * l'exercice des droits, les réclamations et les remboursements : elle est lue
 * par les clients et par un examinateur, et doit donc être celle de la société,
 * jamais une boîte personnelle.
 *
 * Deux chemins à couvrir, parce qu'ils échouent séparément : ce que le seeder
 * écrit sur une base neuve, et ce que la migration corrige sur une base déjà
 * peuplée (le seeder ne réécrit jamais une page existante).
 */
class LegalPagesContactEmailTest extends TestCase
{
    use RefreshDatabase;

    private const PERSONAL = 'hichemmathlouthi@gmail.com';
    private const COMPANY = 'contact@qayed.tn';

    public function test_seeded_legal_pages_publish_the_company_address(): void
    {
        $this->seed(LegalPagesSeeder::class);

        foreach (['mentions-legales', 'cgv', 'politique-confidentialite'] as $slug) {
            $page = Page::where('slug', $slug)->firstOrFail();
            $json = json_encode($page->content, JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString(self::PERSONAL, $json, "{$slug} publie encore une adresse personnelle");
            $this->assertStringContainsString(self::COMPANY, $json, "{$slug} ne publie aucune adresse de contact société");
        }
    }

    /**
     * La migration de rattrapage, rejouée à la main sur une page qui porte
     * encore l'ancienne adresse — le cas exact de la production.
     */
    public function test_migration_rewrites_the_address_on_an_existing_page(): void
    {
        $page = Page::create([
            'slug'    => 'cgv-heritee',
            'status'  => 'published',
            'content' => ['fr' => ['content' => [
                ['type' => 'Prose', 'props' => ['text' => 'Réclamation par e-mail : ' . self::PERSONAL]],
            ]]],
            'meta'    => ['fr' => ['title' => 'CGV', 'description' => 'Écrire à ' . self::PERSONAL]],
        ]);

        $this->runContactEmailMigration();

        $fresh = $page->fresh();
        $this->assertStringNotContainsString(self::PERSONAL, json_encode($fresh->content));
        $this->assertStringContainsString(self::COMPANY, $fresh->content['fr']['content'][0]['props']['text']);
        // La méta compte autant : c'est elle qui part dans les résultats de recherche.
        $this->assertStringContainsString(self::COMPANY, $fresh->meta['fr']['description']);
    }

    /** Rejouable : une page déjà propre ne doit pas être touchée deux fois. */
    public function test_migration_leaves_a_clean_page_untouched(): void
    {
        $page = Page::create([
            'slug'    => 'page-propre',
            'status'  => 'published',
            'content' => ['fr' => ['content' => [
                ['type' => 'Prose', 'props' => ['text' => 'Écrire à ' . self::COMPANY]],
            ]]],
            'meta'    => ['fr' => ['title' => 'Propre', 'description' => '']],
        ]);
        $before = DB::table('pages')->where('id', $page->id)->value('updated_at');

        $this->runContactEmailMigration();

        $this->assertSame($before, DB::table('pages')->where('id', $page->id)->value('updated_at'));
    }

    private function runContactEmailMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_09_01_000003_replace_personal_contact_email_in_cms_pages.php'
        );
        $migration->up();
    }
}
