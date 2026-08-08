<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiters();
    }

    /**
     * Limiteurs de débit (API-01).
     *
     * Avant : tout le périmètre /hotel/* était SANS limitation, y compris
     * l'upload de scans (10 Mo par requête, coût IA par appel). Seuls
     * l'authentification, l'inscription publique, l'autorité et l'admin
     * étaient protégés, et aucun limiteur global de repli n'existait.
     *
     * Toutes les limites ci-dessous sont indexées sur l'UTILISATEUR quand la
     * requête est authentifiée, et sur l'IP sinon. C'est essentiel ici : un
     * hôtel a plusieurs postes derrière une seule IP publique, une limite par
     * IP punirait donc une réception à plusieurs personnes.
     */
    protected function configureRateLimiters(): void
    {
        // Repli global. Volontairement généreux : il n'est pas là pour cadrer
        // l'usage normal mais pour arrêter une boucle folle ou un aspirateur.
        // Repère : le tableau de bord se rafraîchit toutes les 60 s par onglet
        // ouvert, et un check-in complet représente ~10 requêtes.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($this->signature($request)));

        // Upload de scan : 10 Mo par requête et un appel au modèle de vision
        // derrière. Une réception très chargée traite quelques voyageurs par
        // minute, avec 1 à 2 documents chacun — 20/min laisse une marge large
        // tout en rendant l'abus non rentable.
        RateLimiter::for('scan-upload', fn (Request $request) => Limit::perMinute(20)->by($this->signature($request)));

        // Opérations d'argent : rien ne justifie un rythme élevé, et le
        // contrôle de doublon de paiement n'est pas verrouillé en base
        // (SEC-12). Une limite basse réduit la fenêtre de course.
        RateLimiter::for('payments', fn (Request $request) => Limit::perMinute(10)->by($this->signature($request)));

        // Endpoints qui VÉRIFIENT le mot de passe courant d'un compte déjà
        // authentifié : changement d'e-mail et changement de mot de passe.
        //
        // Ils n'étaient couverts que par le repli global à 120/min, alors que
        // /auth/login est à 5/min — soit 172 000 essais par jour pour deviner
        // un mot de passe à partir d'une session volée. Et sur ces endpoints
        // l'enjeu est maximal : deviner le mot de passe permet de changer
        // l'adresse e-mail, donc l'identifiant de connexion ET la cible du
        // lien de réinitialisation. C'est la prise de compte complète.
        //
        // 6/min laisse largement la place à une correction de frappe légitime
        // tout en ramenant l'endpoint au même ordre de grandeur que le login.
        RateLimiter::for('credential-check', fn (Request $request) => Limit::perMinute(6)->by($this->signature($request)));

        // Worker WhatsApp : il sonde toutes les 5 s (WHATSAPP_IDLE_POLL_MS),
        // soit ~24 req/min sur deux endpoints. La limite est donc très au-dessus
        // du besoin réel — son rôle est d'empêcher l'énumération en masse des
        // UUID de scans si le secret partagé venait à fuiter (SEC-21).
        // Indexée sur l'IP : le worker n'a pas d'utilisateur.
        RateLimiter::for('internal-worker', fn (Request $request) => Limit::perMinute(240)->by($request->ip()));
    }

    /**
     * Utilisateur authentifié si disponible, IP sinon.
     */
    protected function signature(Request $request): string
    {
        return $request->user()?->getAuthIdentifier() ?? $request->ip();
    }
}
