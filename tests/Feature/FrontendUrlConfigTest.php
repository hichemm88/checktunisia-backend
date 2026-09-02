<?php

namespace Tests\Feature;

use App\Models\WhatsappSendLog;
use App\Services\Email\SystemMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'adresse publique du frontend doit venir de la CONFIGURATION, jamais d'un
 * `env()` appelé depuis le code applicatif.
 *
 * ── La panne que ces tests ferment ──────────────────────────────────────
 *
 * `docker/start.sh` exécute `php artisan config:cache` au démarrage. À partir
 * de là, Laravel ne lit plus le fichier .env : un `env()` appelé depuis un
 * contrôleur ou un service rend sa valeur PAR DÉFAUT, toujours, quelle que
 * soit la variable réellement posée sur le serveur.
 *
 * Deux appels vivaient dans le code applicatif et retombaient donc en
 * silence sur « https://qayed.tn » en production. Le repli étant plausible,
 * rien ne le signalait. La panne se serait vue à un changement de domaine, ou
 * sur un environnement de recette : des liens de première connexion pointant
 * vers le mauvais hôte, donc des comptes impossibles à ouvrir, et aucun
 * message d'erreur nulle part.
 *
 * ── Pourquoi ces tests-là attrapent la régression ───────────────────────
 *
 * `config([...])` modifie le dépôt de configuration EN MÉMOIRE — exactement
 * ce que fait la configuration mise en cache — sans toucher à l'environnement
 * du processus. Un `env()` restant dans le code ne verrait pas cette valeur et
 * rendrait son défaut : le test échoue. C'est la seule façon de reproduire en
 * test le comportement d'une production `config:cache`.
 */
class FrontendUrlConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_links_follow_the_configured_frontend_url(): void
    {
        config(['frontend.url' => 'https://recette.qayed.tn']);

        // Les liens de PREMIÈRE CONNEXION passent par ici. Pointés sur le
        // mauvais hôte, ils laissent un compte impossible à ouvrir.
        $this->assertSame('https://recette.qayed.tn/login', SystemMailer::loginUrl());
        $this->assertSame(
            'https://recette.qayed.tn/hotel/subscription',
            SystemMailer::frontendUrl('/hotel/subscription'),
        );
    }

    public function test_a_trailing_slash_never_produces_a_double_slash(): void
    {
        // La variable est saisie à la main en production : la barre finale est
        // la faute de frappe la plus banale, et « //login » casse le routeur du
        // frontend sans rien dire.
        config(['frontend.url' => 'https://qayed.tn/']);

        $this->assertSame('https://qayed.tn/login', SystemMailer::loginUrl());
    }

    public function test_the_fiche_button_redirects_to_the_configured_frontend(): void
    {
        config([
            'frontend.url' => 'https://recette.qayed.tn',
            'whatsapp.fiche_link_mode' => 'portal',
        ]);

        $job = WhatsappSendLog::create([
            'recipient' => '21620123456',
            'caption' => 'Fiche',
            'status' => WhatsappSendLog::STATUS_SENT,
            'public_token' => 'TOKENREDIRECT1',
            'queued_at' => now(),
        ]);

        $this->get('/f/'.$job->public_token)
            ->assertRedirect('https://recette.qayed.tn/authority/dashboard');
    }

    public function test_an_unknown_token_says_nothing_at_all(): void
    {
        // Un jeton inconnu ne doit pas dire si la fiche a existé, ni servir la
        // page officielle — sans quoi le lien devient un support
        // d'hameçonnage au nom de Qayed.
        $this->get('/f/INCONNU')->assertNotFound();
    }
}
