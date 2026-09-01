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
        // OcrService n'était lié nulle part : le conteneur le construisait avec
        // la valeur par défaut du constructeur (« mock »), et config('ocr.driver')
        // n'avait donc AUCUN effet. Définir OCR_DRIVER en production n'aurait
        // rien changé — le pilote factice serait resté aux commandes.
        $this->app->bind(
            \App\Services\OCR\OcrService::class,
            fn () => new \App\Services\OCR\OcrService((string) config('ocr.driver', 'mock')),
        );
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
        $this->warnOnIncompleteWhatsappConfig();

        RateLimiter::for('api', function (Request $request) {
            // Le webhook Meta porte sa propre limite (throttle:whatsapp-webhook),
            // bien plus haute : un lot d'accusés de réception peut dépasser 120
            // requêtes par minute. Le repli global l'étranglerait en 429, Meta
            // rejouerait, puis finirait par désactiver l'abonnement — et on
            // perdrait la seule preuve de livraison des fiches.
            if ($request->is('api/v1/webhooks/whatsapp')) {
                return Limit::none();
            }

            return Limit::perMinute(120)->by($this->signature($request));
        });

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

        // Rappel serveur de Konnect. Il n'y a pas d'utilisateur derrière : la
        // limite est indexée sur l'IP du prestataire. Un paiement engendre un
        // ou deux appels ; 60/min laisse la place à une rafale de rejeux après
        // une coupure sans ouvrir la porte au martèlement d'une URL publique.
        RateLimiter::for('konnect-webhook', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // Lien « Consulter la fiche » des messages WhatsApp. Un policier
        // l'ouvre une fois, éventuellement deux. La limite n'est pas là pour
        // le débit mais contre l'énumération de jetons : 80 bits d'aléa la
        // rendent déjà vaine, 30 appels par minute la rendent absurde.
        RateLimiter::for('fiche-link', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // Webhook Meta (WhatsApp Cloud API). Chaque fiche produit jusqu'à trois
        // accusés de réception (sent, delivered, read), groupés par lots. Une
        // réception chargée plus les rejeux qui suivent une coupure justifient
        // une limite large — son rôle est d'empêcher le martèlement d'une URL
        // publique, pas de rationner Meta. La signature, elle, est vérifiée
        // dans le contrôleur : une requête non signée ne coûte qu'un HMAC.
        RateLimiter::for('whatsapp-webhook', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
    }

    /**
     * Canal légal armé mais mal configuré : le dire, fort, à chaque démarrage.
     *
     * Volontairement une entrée de journal critique (relayée à Sentry) et NON
     * une exception. Faire tomber l'application pour une variable WhatsApp
     * empêcherait aussi d'enregistrer les check-in et de consulter le
     * registre : le remède serait pire que le mal.
     *
     * Le déploiement, lui, DOIT échouer — c'est le rôle de
     * `php artisan whatsapp:check-config`, à placer dans la commande de
     * démarrage du conteneur. Voir docs/whatsapp-cloud-api.md.
     */
    protected function warnOnIncompleteWhatsappConfig(): void
    {
        if (! \App\Services\Whatsapp\WhatsappCloudConfig::isArmed()) {
            return;
        }

        $missing = \App\Services\Whatsapp\WhatsappCloudConfig::missing();

        if ($missing !== []) {
            \Illuminate\Support\Facades\Log::critical(
                '[whatsapp] '.\App\Services\Whatsapp\WhatsappCloudConfig::explain($missing)
            );
        }
    }

    /**
     * Utilisateur authentifié si disponible, IP sinon.
     */
    protected function signature(Request $request): string
    {
        return $request->user()?->getAuthIdentifier() ?? $request->ip();
    }
}
