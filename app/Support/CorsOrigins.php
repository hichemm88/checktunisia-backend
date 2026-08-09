<?php

namespace App\Support;

/**
 * Origines autorisées à appeler l'API, résolues en un seul endroit.
 *
 * POURQUOI LA VARIABLE AJOUTE AU LIEU DE REMPLACER. `CORS_ALLOWED_ORIGINS`
 * existait en production sans être lue par `config/cors.php`, et la
 * conclusion évidente — « en faire la source de vérité » — aurait éteint
 * l'application : sa valeur ne contient que l'ancien domaine Vercel, pas
 * www.qayed.tn d'où viennent réellement les requêtes.
 *
 * Le socle du dépôt reste donc inconditionnel et la variable ne peut
 * qu'AJOUTER. Une variable oubliée, périmée ou mal renseignée est alors sans
 * effet — jamais une panne. Et comme chaque ajout doit être une origine
 * absolue explicite (ni joker, ni chemin, ni schéma exotique, ni clair en
 * production), elle ne peut pas non plus élargir l'accès par inadvertance.
 */
final class CorsOrigins
{
    /**
     * Origines toujours autorisées, quelle que soit la configuration.
     *
     * @var list<string>
     */
    public const BUILT_IN = [
        'https://checktunisia.vercel.app',
        'https://qayed.tn',
        'https://www.qayed.tn',
    ];

    /** Aperçu local, hors production uniquement. */
    private const LOCAL_PREVIEW = 'http://localhost:4173';

    /**
     * @param  string|null  $extra    Contenu brut de CORS_ALLOWED_ORIGINS (liste séparée par des virgules).
     * @param  string|null  $appEnv   Environnement applicatif (APP_ENV).
     * @return list<string>
     */
    public static function resolve(?string $extra, ?string $appEnv = null): array
    {
        $production = $appEnv === 'production';

        $origins = self::BUILT_IN;

        if (! $production) {
            $origins[] = self::LOCAL_PREVIEW;
        }

        foreach (explode(',', (string) $extra) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate !== '' && self::isAcceptable($candidate, $production)) {
                $origins[] = $candidate;
            }
        }

        // `array_values` : `array_unique` conserve les clés d'origine et
        // laisserait un tableau troué, que certains sérialiseurs rendent en
        // objet JSON plutôt qu'en liste.
        return array_values(array_unique($origins));
    }

    /**
     * Une origine acceptable est un schéma web, un hôte, un port éventuel —
     * et rien d'autre. Tout joker est refusé : élargir le CORS doit rester un
     * geste explicite, jamais l'effet de bord d'une chaîne mal copiée.
     */
    private static function isAcceptable(string $candidate, bool $production): bool
    {
        $scheme = $production ? 'https' : 'https?';

        return (bool) preg_match('#^'.$scheme.'://[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$#i', $candidate);
    }
}
