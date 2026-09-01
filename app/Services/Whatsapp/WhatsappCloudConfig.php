<?php

namespace App\Services\Whatsapp;

/**
 * Inventaire des variables que la Cloud API exige pour fonctionner.
 *
 * Une seule famille de noms — « WHATSAPP_ » + le terme de la console Meta —
 * et un seul endroit qui sait laquelle sert à quoi. Ce fichier est la
 * réponse à « qu'est-ce que le code lit, exactement ? », question qui ne
 * devrait pas exiger un grep.
 *
 * Le vrai risque que cette classe couvre n'est pas la panne : c'est le
 * SILENCE. Une variable manquante n'a jamais produit d'erreur visible — le
 * canal se contentait de ne rien envoyer, exactement comme un canal qui n'a
 * rien à envoyer. C'est ainsi qu'un arriéré de 715 fiches s'est constitué
 * sans que personne ne le voie.
 */
final class WhatsappCloudConfig
{
    /**
     * Sans elles, aucune fiche ne peut partir.
     *
     * @var array<string,string> nom de variable => clé de configuration
     */
    private const REQUIRED_TO_SEND = [
        'WHATSAPP_API_TOKEN' => 'whatsapp.cloud.token',
        'WHATSAPP_PHONE_NUMBER_ID' => 'whatsapp.cloud.phone_number_id',
    ];

    /**
     * Sans elles, l'envoi fonctionne mais on ne sait jamais ce que le message
     * est devenu : le webhook refuse tout, faute de pouvoir authentifier.
     *
     * @var array<string,string>
     */
    private const REQUIRED_FOR_RECEIPTS = [
        'WHATSAPP_APP_SECRET' => 'whatsapp.cloud.app_secret',
        'WHATSAPP_WEBHOOK_VERIFY_TOKEN' => 'whatsapp.cloud.webhook_verify_token',
    ];

    /**
     * Nécessaires seulement aux commandes d'administration (modèles,
     * enregistrement du webhook), pas à l'envoi courant.
     *
     * @var array<string,string>
     */
    private const REQUIRED_FOR_ADMIN = [
        'WHATSAPP_WABA_ID' => 'whatsapp.cloud.waba_id',
        'WHATSAPP_APP_ID' => 'whatsapp.cloud.app_id',
    ];

    /**
     * Variables absentes, parmi celles nécessaires à l'envoi et aux accusés
     * de réception.
     *
     * @return array<int,string>
     */
    public static function missing(): array
    {
        return self::absentIn(self::REQUIRED_TO_SEND + self::REQUIRED_FOR_RECEIPTS);
    }

    /**
     * Variables absentes parmi celles qu'exigent les commandes
     * d'administration — en plus de celles de l'envoi.
     *
     * @return array<int,string>
     */
    public static function missingForAdmin(): array
    {
        return self::absentIn(
            self::REQUIRED_TO_SEND + self::REQUIRED_FOR_RECEIPTS + self::REQUIRED_FOR_ADMIN
        );
    }

    public static function isComplete(): bool
    {
        return self::missing() === [];
    }

    /**
     * Le canal Cloud API est-il censé être en service ?
     *
     * Une configuration incomplète n'a d'importance que si l'on compte
     * réellement sur ce canal : inutile de crier sur un environnement de
     * développement qui n'envoie rien.
     */
    public static function isArmed(): bool
    {
        return (bool) config('whatsapp.enabled')
            && config('whatsapp.channel') === 'cloud';
    }

    /**
     * Message destiné à un humain : ce qui manque, et ce que ça coûte.
     *
     * @param  array<int,string>  $missing
     */
    public static function explain(array $missing): string
    {
        return 'Configuration WhatsApp Cloud API incomplète — variable(s) manquante(s) : '
            .implode(', ', $missing).'. '
            .'Tant qu\'elles ne sont pas posées, AUCUNE fiche de police ne peut être transmise. '
            .'Voir docs/whatsapp-cloud-api.md pour la liste complète.';
    }

    /**
     * @param  array<string,string>  $requirements
     * @return array<int,string>
     */
    private static function absentIn(array $requirements): array
    {
        $missing = [];

        foreach ($requirements as $variable => $configKey) {
            if (blank(config($configKey))) {
                $missing[] = $variable;
            }
        }

        return $missing;
    }
}
