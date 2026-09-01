<?php

namespace App\Console\Commands;

use App\Services\Whatsapp\WhatsappCloudConfig;
use Illuminate\Console\Command;

/**
 * Vérifie que la Cloud API est configurée, et ÉCHOUE si elle ne l'est pas.
 *
 * À placer dans la commande de démarrage du conteneur, à côté de
 * `php artisan migrate`, pour transformer une configuration incomplète en
 * déploiement rouge plutôt qu'en canal légal silencieux :
 *
 *     php artisan migrate --force && php artisan whatsapp:check-config && …
 *
 * Pourquoi une commande et non un contrôle au démarrage de l'application :
 * faire planter le conteneur pour une variable WhatsApp empêcherait aussi
 * d'enregistrer les check-in, de consulter le registre et de payer un
 * abonnement. Un hébergeur qui ne peut plus rien faire est un dommage plus
 * grave que des fiches qui attendent en file. Le déploiement, lui, peut
 * échouer sans conséquence pour personne — c'est le bon endroit où être
 * intransigeant.
 */
class CheckWhatsappConfig extends Command
{
    protected $signature = 'whatsapp:check-config
        {--admin : Vérifie aussi les variables des commandes d\'administration (modèles, webhook)}';

    protected $description = 'Vérifie la configuration WhatsApp Cloud API ; sort en erreur si elle est incomplète.';

    public function handle(): int
    {
        if (! WhatsappCloudConfig::isArmed()) {
            $this->line('Canal Cloud API non armé (whatsapp.enabled=false ou WHATSAPP_CHANNEL≠cloud) — rien à vérifier.');

            return self::SUCCESS;
        }

        $missing = $this->option('admin')
            ? WhatsappCloudConfig::missingForAdmin()
            : WhatsappCloudConfig::missing();

        if ($missing !== []) {
            $this->error(WhatsappCloudConfig::explain($missing));

            return self::FAILURE;
        }

        $this->info('Configuration WhatsApp Cloud API complète.');

        // La configuration peut être complète et l'envoi rester volontairement
        // fermé (coupe-circuit, bascule non armée). Le dire ici évite de
        // conclure « tout va bien » alors que rien ne partira.
        if (blank(config('whatsapp.guard.cutover_at'))) {
            $this->warn('WHATSAPP_CLOUD_API_CUTOVER_AT n\'est pas définie : aucune fiche ne partira. Voulu tant que la bascule n\'est pas faite.');
        }

        if (! config('whatsapp.guard.sending_enabled', true)) {
            $this->warn('WHATSAPP_SENDING_ENABLED=false : coupe-circuit actif, aucune fiche ne partira.');
        }

        return self::SUCCESS;
    }
}
