<?php

namespace Tests\Feature;

use App\Models\EmailTemplate;
use App\Models\Hotel;
use App\Models\User;
use App\Services\Email\SystemMailer;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Localisation des emails systeme (fr/en/ar) : resolution des modeles avec repli
 * francais, override admin par langue, coquille RTL en arabe, capture de la
 * langue a l'inscription.
 */
class LocalizedEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_templates_are_localized(): void
    {
        $this->assertStringContainsString('Bienvenue', EmailTemplate::getOrDefault('welcome', 'fr')['subject']);
        $this->assertStringContainsString('Welcome', EmailTemplate::getOrDefault('welcome', 'en')['subject']);
        $this->assertStringContainsString('مرحبًا', EmailTemplate::getOrDefault('welcome', 'ar')['subject']);
    }

    public function test_unknown_locale_falls_back_to_french(): void
    {
        $this->assertSame(
            EmailTemplate::getOrDefault('welcome', 'fr')['subject'],
            EmailTemplate::getOrDefault('welcome', 'de')['subject'],
        );
        $this->assertSame('fr', EmailTemplate::normalizeLocale(null));
    }

    public function test_custom_override_is_scoped_to_its_locale(): void
    {
        EmailTemplate::create([
            'key' => 'welcome', 'locale' => 'en',
            'subject' => 'Custom EN subject', 'body_html' => '<p>Custom</p>',
        ]);

        $en = EmailTemplate::getOrDefault('welcome', 'en');
        $this->assertTrue($en['is_custom']);
        $this->assertSame('Custom EN subject', $en['subject']);

        // Le francais n'est pas affecte par l'override anglais.
        $fr = EmailTemplate::getOrDefault('welcome', 'fr');
        $this->assertFalse($fr['is_custom']);
        $this->assertStringContainsString('Bienvenue', $fr['subject']);
    }

    public function test_arabic_shell_is_rtl(): void
    {
        $ar = SystemMailer::preview('welcome', 'ar');
        $this->assertStringContainsString('dir="rtl"', $ar['html']);
        $this->assertStringContainsString('lang="ar"', $ar['html']);

        $fr = SystemMailer::preview('welcome', 'fr');
        $this->assertStringContainsString('dir="ltr"', $fr['html']);
    }

    public function test_admin_index_and_update_are_per_locale(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        // Lecture en anglais.
        $this->actingAs($admin)->getJson('/api/v1/admin/emails?locale=en')
            ->assertOk()
            ->assertJsonPath('meta.locale', 'en');

        // Ecriture d'un override arabe.
        $this->actingAs($admin)->patchJson('/api/v1/admin/emails/welcome', [
            'subject' => 'موضوع مخصص', 'body_html' => '<p>مرحبًا</p>', 'locale' => 'ar',
        ])->assertOk()->assertJsonPath('data.locale', 'ar');

        $this->assertDatabaseHas('email_templates', ['key' => 'welcome', 'locale' => 'ar', 'subject' => 'موضوع مخصص']);
        // L'override arabe ne cree pas de ligne francaise.
        $this->assertDatabaseMissing('email_templates', ['key' => 'welcome', 'locale' => 'fr']);
    }

    public function test_guard_blocks_non_admin(): void
    {
        $hotel = Hotel::factory()->create();
        $receptionist = User::factory()->receptionist($hotel)->create();
        $this->actingAs($receptionist)->getJson('/api/v1/admin/emails')->assertForbidden();
    }

    public function test_registration_captures_locale_on_org_and_user(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $slug = \App\Models\SubscriptionPlan::query()->value('slug');

        $this->postJson('/api/v1/public/register', [
            'entity_type' => 'company',
            'org_name'    => 'Riad Test',
            'first_name'  => 'Nour', 'last_name' => 'Test',
            'email'       => 'nour.locale@test.tn',
            'password'    => 'Sup3rStr0ng!Pass', 'password_confirmation' => 'Sup3rStr0ng!Pass',
            'plan_slug'   => $slug,
            'locale'      => 'en',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'nour.locale@test.tn', 'locale' => 'en']);
        $this->assertDatabaseHas('organizations', ['name' => 'Riad Test', 'locale' => 'en']);
    }
}
