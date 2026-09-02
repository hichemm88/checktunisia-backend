<?php

namespace App\Services\Whatsapp;

/**
 * Traduction des codes d'erreur de la Cloud API (Meta Graph).
 *
 * Une erreur Meta n'est pas « un échec » : c'est trois décisions distinctes,
 * et les confondre coûte cher.
 *
 *  1. FAUT-IL RETENTER ? Retenter une fenêtre de 24 h fermée ou un numéro
 *     invalide ne réussira jamais — cela consomme les tentatives, retarde
 *     l'alerte de 24 h et masque la vraie cause. Seuls les incidents
 *     transitoires (limitation de débit, panne Meta, réseau) méritent un
 *     backoff.
 *  2. FAUT-IL RÉVEILLER QUELQU'UN ? Un jeton expiré ou un modèle suspendu
 *     arrête TOUT le canal légal du produit, pas une fiche : ça ne peut pas
 *     attendre la lecture d'un journal.
 *  3. QUE DIRE À L'HUMAIN qui lira le journal admin ? Un code nu n'aide
 *     personne ; la phrase indique l'action.
 *
 * Référence : developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes
 */
final class WhatsappCloudErrors
{
    /**
     * Incidents transitoires : réessayer a un sens.
     *
     * @var array<int,string>
     */
    private const RETRYABLE = [
        131000 => 'Erreur interne Meta — incident passager côté fournisseur.',
        130429 => 'Limite de débit du compte atteinte — envois trop rapprochés.',
        131048 => 'Limite anti-spam atteinte pour ce numéro émetteur.',
        131056 => 'Trop de messages vers ce destinataire en peu de temps.',
        133016 => 'Numéro émetteur temporairement indisponible (opération en cours côté Meta).',
        80007 => 'Limite de débit de l\'API atteinte.',
        2 => 'Service Graph temporairement indisponible.',
    ];

    /**
     * Échecs définitifs : l'état ne changera pas en réessayant à l'identique.
     *
     * @var array<int,string>
     */
    private const PERMANENT = [
        100 => 'Paramètre invalide — la requête est fautive (destinataire, modèle ou variables).',
        131008 => 'Paramètre obligatoire manquant dans la requête.',
        131009 => 'Valeur de paramètre non conforme.',
        131026 => 'Destinataire non joignable : numéro sans compte WhatsApp, ou qui ne peut pas recevoir ce message.',
        131047 => 'Fenêtre de 24 h fermée : hors conversation, seul un modèle approuvé passe.',
        131049 => 'Message retenu par Meta pour préserver la qualité de l\'écosystème — l\'émetteur envoie trop, ou trop de messages non sollicités.',
        131051 => 'Type de message non pris en charge.',
        132000 => 'Nombre de variables du modèle différent de celui approuvé.',
        132005 => 'Texte rendu trop long pour le modèle approuvé.',
        132007 => 'Contenu refusé par la politique des modèles.',
        132012 => 'Format de variable refusé par le modèle.',
        132015 => 'Modèle en pause : trop de retours négatifs.',
        132016 => 'Modèle désactivé définitivement.',
        132068 => 'Flux du modèle en pause.',
        132069 => 'Flux du modèle bloqué.',
    ];

    /**
     * ERREURS DE CONFIGURATION : ce n'est pas la fiche qui est fautive, c'est
     * le réglage du canal.
     *
     * La distinction n'est pas académique, elle est la leçon de l'incident.
     * 132001 était classé « définitif » : chaque fiche présentée pendant que
     * le modèle attendait son approbation a donc été marquée « échec
     * définitif », avec une alerte par fiche — alors qu'une seule chose était
     * cassée, la même pour toutes, et qu'elle se réparait en une fois.
     *
     * Le bon traitement tient en trois gestes : garder l'entrée EN FILE (elle
     * partira quand le réglage sera juste), suspendre le canal (insister ne
     * répare rien), alerter UNE fois (c'est une panne, pas N échecs).
     *
     * @var array<int,string>
     */
    private const CONFIGURATION = [
        132001 => 'Modèle inexistant dans cette langue — vérifier nom et code langue, ou attendre l\'approbation Meta.',
    ];

    /**
     * Pannes de canal : une seule fiche échoue, mais AUCUNE ne repartira tant
     * qu'un humain n'est pas intervenu. Alerte immédiate, pas une ligne de log.
     *
     * @var array<int,string>
     */
    private const CRITICAL = [
        190 => 'Jeton d\'accès invalide ou expiré — plus AUCUNE fiche ne peut partir.',
        0 => 'Authentification refusée par Meta — plus AUCUNE fiche ne peut partir.',
        3 => 'L\'application n\'a pas la permission d\'envoyer des messages.',
        10 => 'Permission refusée pour ce numéro émetteur.',
        368 => 'Numéro émetteur temporairement bloqué pour violation des règles.',
        131031 => 'Compte WhatsApp Business verrouillé.',
        131042 => 'Problème de paiement du compte Meta — les envois sont suspendus.',
    ];

    /**
     * Réessayer a-t-il une chance d'aboutir ?
     *
     * Le code Meta prime sur le statut HTTP : Meta renvoie volontiers 400 sur
     * une limitation de débit, et un 400 « fenêtre fermée » ne doit surtout
     * pas être confondu avec lui. Sans code exploitable, on retombe sur la
     * règle HTTP habituelle (429 et 5xx sont transitoires).
     */
    public static function isRetryable(?int $code, int $httpStatus): bool
    {
        // Une erreur de configuration se répare, et l'entrée n'y est pour
        // rien : elle reste en file. Ce n'est pas un « réessayer plus tard »
        // ordinaire — la boucle d'envoi la remet en file SANS consommer de
        // tentative (voir WhatsappOutboxService::returnToQueue) —, mais tout
        // autre appelant doit au minimum la garder vivante.
        if ($code !== null && (isset(self::RETRYABLE[$code]) || isset(self::CONFIGURATION[$code]))) {
            return true;
        }

        if ($code !== null && (isset(self::PERMANENT[$code]) || isset(self::CRITICAL[$code]))) {
            return false;
        }

        return $httpStatus === 429 || $httpStatus >= 500;
    }

    /**
     * Le canal est-il mal réglé (par opposition à : cette fiche est fautive) ?
     *
     * Trois conséquences, et pas une de moins : entrée conservée en file,
     * canal suspendu, alerte unique.
     */
    public static function isConfiguration(?int $code): bool
    {
        return $code !== null && isset(self::CONFIGURATION[$code]);
    }

    /**
     * Meta vient-il de dire « trop » ?
     *
     * Ces codes-là ne concernent pas la fiche mais l'ÉMETTEUR : débit dépassé,
     * ou qualité jugée insuffisante. Continuer à pousser après un tel signal
     * est précisément ce qui fait passer d'une limitation temporaire à un
     * bannissement — d'où une pause globale, pas un simple retry de la ligne.
     *
     * @var array<int,true>
     */
    private const GLOBAL_PAUSE = [
        131049 => true,   // qualité : messages retenus pour préserver l'écosystème
        80007 => true,    // limite de débit de l'API
        130429 => true,   // limite de débit du compte
        131048 => true,   // limite anti-spam de l'émetteur
    ];

    /** Faut-il suspendre TOUS les envois un moment, et pas seulement celui-ci ? */
    public static function triggersGlobalPause(?int $code): bool
    {
        return $code !== null && isset(self::GLOBAL_PAUSE[$code]);
    }

    /** L'erreur arrête-t-elle tout le canal (et non une seule fiche) ? */
    public static function isCritical(?int $code): bool
    {
        return $code !== null && isset(self::CRITICAL[$code]);
    }

    /** Explication en français, ou null si le code est inconnu de nous. */
    public static function explain(?int $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return self::RETRYABLE[$code]
            ?? self::CONFIGURATION[$code]
            ?? self::PERMANENT[$code]
            ?? self::CRITICAL[$code]
            ?? null;
    }

    /**
     * Message destiné au journal admin : « [131047] Fenêtre de 24 h fermée… ».
     * Reprend le libellé de Meta quand le code nous est inconnu, pour ne pas
     * appauvrir le diagnostic.
     */
    public static function describe(?int $code, ?string $metaMessage): string
    {
        $explanation = self::explain($code) ?? $metaMessage ?? 'Erreur inconnue.';

        return $code !== null ? "[{$code}] {$explanation}" : $explanation;
    }
}
