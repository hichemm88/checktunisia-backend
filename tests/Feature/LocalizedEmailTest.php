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

    // ── Le cycle de vie de l'abonnement parle aussi la langue du client ──────
    //
    // Les gabarits étaient traduits, mais les phrases INJECTÉES dedans (fin
    // d'essai, motif d'expiration) étaient composées en français sur le lieu
    // d'envoi. Un client anglophone recevait donc une coquille anglaise avec
    // une phrase française au milieu.

    public function test_the_trial_sentence_follows_the_client_language(): void
    {
        $this->assertStringContainsString('se termine dans 2 jour(s)', SystemMailer::trialMessage(2, '15/07/2026', 'fr'));
        $this->assertStringContainsString('ends in 2 day(s)', SystemMailer::trialMessage(2, '15/07/2026', 'en'));
        $this->assertStringContainsString('تنتهي', SystemMailer::trialMessage(2, '15/07/2026', 'ar'));

        $this->assertStringContainsString("se termine aujourd'hui", SystemMailer::trialMessage(0, '15/07/2026', 'fr'));
        $this->assertStringContainsString('ends today', SystemMailer::trialMessage(0, '15/07/2026', 'en'));

        // null = l'essai est déjà terminé.
        $this->assertStringContainsString("s'est terminé le 15/07/2026", SystemMailer::trialMessage(null, '15/07/2026', 'fr'));
        $this->assertStringContainsString('ended on 15/07/2026', SystemMailer::trialMessage(null, '15/07/2026', 'en'));
    }

    public function test_the_expiry_reason_follows_the_client_language_and_never_asks_to_write_to_us(): void
    {
        foreach (['fr', 'en', 'ar'] as $locale) {
            $reason = SystemMailer::subscriptionExpiredReason('15/07/2026', $locale);

            $this->assertStringContainsString('15/07/2026', $reason);
            // Le renouvellement est self-service : plus aucun motif ne doit
            // renvoyer le client vers nous.
            $this->assertStringNotContainsString('Contactez-nous', $reason);
            $this->assertStringNotContainsString('contact@qayed.tn', $reason);
        }

        $this->assertStringContainsString('expired', SystemMailer::subscriptionExpiredReason('15/07/2026', 'en'));
    }

    public function test_an_unknown_locale_falls_back_to_french_for_these_sentences_too(): void
    {
        $this->assertSame(
            SystemMailer::trialMessage(3, '15/07/2026', 'fr'),
            SystemMailer::trialMessage(3, '15/07/2026', 'de'),
        );
        $this->assertSame(
            SystemMailer::subscriptionExpiredReason('15/07/2026', 'fr'),
            SystemMailer::subscriptionExpiredReason('15/07/2026', null),
        );
    }
}
