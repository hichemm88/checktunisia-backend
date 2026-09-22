<?php

namespace App\Services\Prospection;

/**
 * Le pipeline (§ Pipeline) : à_contacter → contacté → relancé → démo
 * planifiée → démo faite → essai en cours → client, plus les statuts
 * terminaux refus / sans_réponse / hors_périmètre.
 *
 * Aucune machine à états stricte : un commercial doit pouvoir revenir en
 * arrière (ex. une démo annulée repasse en "contacté") ou sauter une étape.
 * Seule la VALEUR est validée ; les effets de bord de chaque transition sont
 * ici (voir EstablishmentStatusUpdater).
 */
final class PipelineStatus
{
    public const A_CONTACTER = 'a_contacter';

    public const CONTACTE = 'contacte';

    public const RELANCE = 'relance';

    public const DEMO_PLANIFIEE = 'demo_planifiee';

    public const DEMO_FAITE = 'demo_faite';

    public const ESSAI_EN_COURS = 'essai_en_cours';

    public const CLIENT = 'client';

    public const REFUS = 'refus';

    public const SANS_REPONSE = 'sans_reponse';

    public const HORS_PERIMETRE = 'hors_perimetre';

    public const ALL = [
        self::A_CONTACTER,
        self::CONTACTE,
        self::RELANCE,
        self::DEMO_PLANIFIEE,
        self::DEMO_FAITE,
        self::ESSAI_EN_COURS,
        self::CLIENT,
        self::REFUS,
        self::SANS_REPONSE,
        self::HORS_PERIMETRE,
    ];

    public const TERMINAL = [self::REFUS, self::SANS_REPONSE, self::HORS_PERIMETRE];

    /** Statuts qui proposent une date de prochaine action par défaut à J+4. */
    public const PROPOSES_FOLLOW_UP = [self::CONTACTE, self::RELANCE];

    /** Nombre de jours par défaut avant la prochaine relance. */
    public const DEFAULT_FOLLOW_UP_DAYS = 4;

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    /** Libellé FR — utilisé par les notifications push (texte, pas d'UI ici). */
    public static function label(string $status): string
    {
        return match ($status) {
            self::A_CONTACTER => 'à contacter',
            self::CONTACTE => 'contacté',
            self::RELANCE => 'relancé',
            self::DEMO_PLANIFIEE => 'démo planifiée',
            self::DEMO_FAITE => 'démo faite',
            self::ESSAI_EN_COURS => 'essai en cours',
            self::CLIENT => 'client',
            self::REFUS => 'refus',
            self::SANS_REPONSE => 'sans réponse',
            self::HORS_PERIMETRE => 'hors périmètre',
            default => $status,
        };
    }
}
