<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * CMS : pages Puck (brouillon/publié), menus publics, médias.
 */
class CmsTest extends TestCase
{
    use RefreshDatabase;

    private User $platformAdmin;
    private User $hotelAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->platformAdmin = User::factory()->platformAdmin()->create();
        $this->hotelAdmin    = User::factory()->hotelAdmin(\App\Models\Hotel::factory()->create())->create();
    }

    private function puckContent(string $title): array
    {
        return ['content' => [['type' => 'Hero', 'props' => ['id' => 'Hero-1', 'title' => $title]]], 'root' => ['props' => []]];
    }

    // ── Accès ────────────────────────────────────────────────────────────────

    public function test_cms_crud_requires_platform_admin(): void
    {
        $this->postJson('/api/v1/admin/pages', ['slug' => 'x'])->assertUnauthorized();
        $this->actingAs($this->hotelAdmin)->getJson('/api/v1/admin/pages')->assertForbidden();
        $this->actingAs($this->hotelAdmin)->postJson('/api/v1/admin/menu-items', [])->assertForbidden();
        $this->actingAs($this->hotelAdmin)->postJson('/api/v1/admin/media', [])->assertForbidden();
    }

    // ── Pages ────────────────────────────────────────────────────────────────

    public function test_admin_can_create_update_and_publish_page(): void
    {
        $page = $this->actingAs($this->platformAdmin)
            ->postJson('/api/v1/admin/pages', [
                'slug'    => 'a-propos',
                'content' => ['fr' => $this->puckContent('À propos')],
                'meta'    => ['fr' => ['title' => 'À propos — Qayed', 'description' => 'Qui sommes-nous.']],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('draft', $page['status']);

        // Brouillon → invisible publiquement
        $this->getJson('/api/v1/public/pages/a-propos')->assertNotFound();

        // Publication
        $this->actingAs($this->platformAdmin)
            ->patchJson("/api/v1/admin/pages/{$page['id']}", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->getJson('/api/v1/public/pages/a-propos')
            ->assertOk()
            ->assertJsonPath('data.meta.fr.title', 'À propos — Qayed')
            ->assertJsonPath('data.content.fr.content.0.type', 'Hero');
    }

    public function test_reserved_and_duplicate_slugs_are_rejected(): void
    {
        $this->actingAs($this->platformAdmin)
            ->postJson('/api/v1/admin/pages', ['slug' => 'admin'])
            ->assertUnprocessable();

        Page::create(['slug' => 'contact', 'status' => 'draft']);
        $this->actingAs($this->platformAdmin)
            ->postJson('/api/v1/admin/pages', ['slug' => 'contact'])
            ->assertUnprocessable();

        $this->actingAs($this->platformAdmin)
            ->postJson('/api/v1/admin/pages', ['slug' => 'Bad Slug!'])
            ->assertUnprocessable();
    }

    // ── Menus ────────────────────────────────────────────────────────────────

    public function test_public_menus_hide_inactive_and_unpublished_targets(): void
    {
        $published = Page::create(['slug' => 'tarifs-detail', 'status' => 'published']);
        $draft     = Page::create(['slug' => 'brouillon', 'status' => 'draft']);

        $this->actingAs($this->platformAdmin)->postJson('/api/v1/admin/menu-items', [
            'location' => 'navbar', 'label' => ['fr' => 'Tarifs'], 'page_id' => $published->id,
        ])->assertCreated();
        $this->actingAs($this->platformAdmin)->postJson('/api/v1/admin/menu-items', [
            'location' => 'navbar', 'label' => ['fr' => 'Caché'], 'page_id' => $draft->id,
        ])->assertCreated();
        $this->actingAs($this->platformAdmin)->postJson('/api/v1/admin/menu-items', [
            'location' => 'footer', 'label' => ['fr' => 'Blog'], 'external_url' => 'https://blog.qayed.tn',
        ])->assertCreated();
        MenuItem::create(['location' => 'footer', 'label' => ['fr' => 'Off'], 'external_url' => 'https://x.tn', 'is_active' => false]);

        $menus = $this->getJson('/api/v1/public/menus')->assertOk()->json('data');

        $this->assertCount(1, $menus['navbar']); // page brouillon exclue
        $this->assertSame('tarifs-detail', $menus['navbar'][0]['slug']);
        $this->assertCount(1, $menus['footer']); // entrée inactive exclue
        $this->assertSame('https://blog.qayed.tn', $menus['footer'][0]['url']);
    }

    public function test_menu_item_needs_page_or_url_but_not_both(): void
    {
        $page = Page::create(['slug' => 'p1', 'status' => 'published']);

        $this->actingAs($this->platformAdmin)->postJson('/api/v1/admin/menu-items', [
            'location' => 'navbar', 'label' => ['fr' => 'Sans cible'],
        ])->assertUnprocessable();

        $this->actingAs($this->platformAdmin)->postJson('/api/v1/admin/menu-items', [
            'location' => 'navbar', 'label' => ['fr' => 'Double'],
            'page_id' => $page->id, 'external_url' => 'https://x.tn',
        ])->assertUnprocessable();
    }

    // ── Médias ───────────────────────────────────────────────────────────────

    public function test_media_upload_resizes_and_roundtrips(): void
    {
        $upload = UploadedFile::fake()->image('photo.jpg', 2400, 1200);

        $media = $this->actingAs($this->platformAdmin)
            ->post('/api/v1/admin/media', ['file' => $upload], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data');

        $this->assertSame('image/webp', $media['mime']);
        $this->assertStringEndsWith('.webp', $media['filename']);

        $response = $this->get("/api/v1/public/media/{$media['id']}");
        $response->assertOk();
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
    }
}
