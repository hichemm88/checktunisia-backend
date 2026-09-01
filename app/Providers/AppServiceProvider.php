<?php

namespace App\Providers;

use App\Services\Whatsapp\WhatsappCloudConfig;
use App\Support\BrandLogo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
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

        // Le service WebAuthn construit un sérialiseur Symfony complet à
        // l'instanciation : une seule fois par requête suffit.
        $this->app->singleton(\App\Services\Webauthn\WebauthnService::class);
    }

    public function boot(): void
    {
        $this->configureRateLimiters();
        $this->warnOnIncompleteWhatsappConfig();

        /*
         * Le logo, partout où Qayed se présente à l'autorité.
         *
         * Deux vues : l'en-tête des PDF de fiches, et la page d'attente
         * servie par /f/{token} quand le portail n'est pas encore ouvert.
         *
         * Par un compositeur plutôt qu'en argument des appelants (relais
         * WhatsApp, export par email, récapitulatif quotidien, contrôleur du
         * lien) : le logo est une constante de marque, pas une donnée de la
         * fiche. Le passer à la main quatre fois, c'est quatre occasions de
         * l'oublier — et une page sans identification ne se remarque qu'une
         * fois chez le destinataire.
         */
        View::composer(['pdf.police-fiches', 'fiche-link-info'], function ($view) {
            $view->with('brandLogo', BrandLogo::dataUri());
        });
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

        // Cérémonies WebAuthn (émission de challenge, vérification d'assertion).
        // Ces routes sont publiques : sans limite, elles laisseraient créer des
        // challenges à volonté et marteler la vérification.
        //
        // Plus haut que /auth/login (5/min) à dessein. Une connexion par
        // passkey légitime coûte DEUX requêtes (options puis vérification) ;
        // le seul affichage de la page de connexion en consomme une de plus
        // quand le navigateur propose le remplissage conditionnel ; et une
        // réception à plusieurs postes partage une seule IP publique. Le
        // facteur limitant reste cryptographique, pas le débit : une assertion
        // sans la clé privée ne peut pas être forgée, quel que soit le nombre
        // d'essais.
        RateLimiter::for('webauthn', fn (Request $request) => Limit::perMinute(30)->by($this->signature($request)));

        // Connexion des agents par code WhatsApp. Indexée sur l'IP : la
        // requête est anonyme par construction, il n'y a pas d'utilisateur à
        // qui l'attribuer.
        //
        // 12/min, pas 5 : l'écran de saisie du code envoie une requête par
        // tentative, et un poste de police partage une IP publique — deux
        // agents qui se connectent en même temps ne doivent pas se bloquer
        // l'un l'autre. Ce limiteur n'est PAS la protection du mécanisme ; il
        // empêche seulement de marteler la route. Les bornes qui comptent
        // (trois demandes par numéro et par IP sur dix minutes, trois essais
        // par code, verrouillage de quinze minutes) sont dans
        // WhatsappOtpService, où elles peuvent distinguer une demande d'un
        // essai.
        RateLimiter::for('whatsapp-otp', fn (Request $request) => Limit::perMinute(12)->by($request->ip()));
    }

    /**
     * Canal légal armé mais mal configuré : le dire, fort, à chaque démarrage.
     *
     * Appelé depuis boot() et non depuis configureRateLimiters() : ce contrôle
     * n'a rien à voir avec les limiteurs de débit, et l'enterrer là le rendait
     * invisible au premier lecteur venu.
     *
     * Volontairement une entrée de journal critique (relayée à Sentry) et NON
     * une exception. Faire tomber l'application pour une variable WhatsApp
     * empêcherait aussi d'enregistrer les check-in et de consulter le
     * registre : le remède serait pire que le mal.
     *
     * Le déploiement, lui, DOIT échouer — c'est le rôle de
     * `php artisan whatsapp:check-config`, appelé par docker/start.sh et à
     * poser en Pre-Deploy Command. Voir docs/whatsapp-cloud-api.md.
     */
    protected function warnOnIncompleteWhatsappConfig(): void
    {
        if (! WhatsappCloudConfig::isArmed()) {
            return;
        }

        $missing = WhatsappCloudConfig::missing();

        if ($missing !== []) {
            Log::critical('[whatsapp] '.WhatsappCloudConfig::explain($missing));
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
