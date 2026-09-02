<?php

namespace App\Support;

/**
 * Compose un motif `LIKE` / `ILIKE` à partir d'une valeur qui n'est PAS un
 * motif.
 *
 * ── Pourquoi ce fichier existe ──────────────────────────────────────────
 *
 * Les liaisons de requête protègent de l'injection SQL — elles ne protègent
 * pas des JOKERS. `where('col', 'ilike', "%$valeur%")` est parfaitement
 * paramétré et reste faux dès que `$valeur` contient `%` ou `_` : ces
 * caractères gardent leur sens de joker à l'intérieur de la chaîne liée.
 *
 * Ce n'est pas une subtilité théorique. Le cloisonnement des comptes « police »
 * reposait sur ce motif, alimenté par `authority_organizations.governorate`,
 * validé seulement en `string|max:100`. Un « % » dans ce champ produisait
 * `%%%` — donc « tous les gouvernorats » — et transformait un poste de police
 * local en compte à portée nationale sur les profils de voyageurs, numéros de
 * passeport compris. Silencieusement : le compte restait de type « police »,
 * aucun écran ne montrait quoi que ce soit d'anormal.
 *
 * ── Ce que cette classe ne fait pas ─────────────────────────────────────
 *
 * Elle ne transforme pas une recherche « contient » en égalité stricte. La
 * tolérance à la sous-chaîne est VOULUE — elle permet à un poste déclaré
 * « Tunis » de couvrir un établissement en « Grand Tunis ». Seuls les jokers
 * sont neutralisés ; le reste du comportement est inchangé.
 */
final class LikePattern
{
    /**
     * Neutralise les jokers d'une valeur destinée à un LIKE.
     *
     * L'antislash passe en PREMIER : l'échapper après `%` et `_` reviendrait à
     * ré-échapper les antislashes qu'on vient d'introduire, et « 100\% » se
     * transformerait en « 100\\% » — un antislash littéral suivi d'un joker
     * toujours actif.
     */
    public static function escape(string $value, string $escapeChar = '\\'): string
    {
        return str_replace(
            [$escapeChar, '%', '_'],
            [$escapeChar.$escapeChar, $escapeChar.'%', $escapeChar.'_'],
            $value,
        );
    }

    /** Motif « contient », jokers de la valeur neutralisés. */
    public static function contains(string $value): string
    {
        return '%'.self::escape($value).'%';
    }
}
