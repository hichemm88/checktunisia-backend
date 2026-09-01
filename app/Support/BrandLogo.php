<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Logo Qayed, embarqué en base64 pour les PDF.
 *
 * DomPDF ne va chercher aucune ressource distante : une balise `<img src>`
 * pointant sur une URL produirait un cadre vide dans un document transmis à
 * l'autorité. Le fichier est donc lu sur disque et inséré dans le HTML.
 *
 * ── Pourquoi un repli, et pourquoi il reste ─────────────────────────────────
 *
 * Le fichier peut manquer : dépôt fraîchement cloné, image retirée par erreur,
 * ou simple oubli au déploiement. Sans repli, l'en-tête du PDF serait vide, et
 * une fiche de police sans identification de l'émetteur est moins utile qu'une
 * fiche au logo textuel. Le mot « QAYED » prend donc le relais — silencieux
 * pour le lecteur, signalé dans les journaux pour l'exploitant.
 *
 * Le résultat est mémorisé pour la durée du processus : un récapitulatif
 * quotidien compose des dizaines de fiches, et relire le même fichier à chaque
 * page ne servirait à rien.
 */
final class BrandLogo
{
    /** Chemin relatif à resources/, unique endroit où déposer le fichier. */
    private const PATH = 'images/qayed-logo.png';

    private static ?string $cached = null;

    private static bool $resolved = false;

    /**
     * Data URI du logo, ou null s'il n'est pas disponible.
     *
     * Null n'est pas une erreur : c'est le signal que la vue doit afficher le
     * repli textuel.
     */
    public static function dataUri(): ?string
    {
        if (self::$resolved) {
            return self::$cached;
        }

        self::$resolved = true;
        $path = resource_path(self::PATH);

        if (! is_file($path) || ! is_readable($path)) {
            Log::info('[fiche] logo absent ('.self::PATH.') — en-tête PDF en repli textuel.');

            return self::$cached = null;
        }

        $bytes = @file_get_contents($path);

        if ($bytes === false || $bytes === '') {
            Log::warning('[fiche] logo illisible ou vide ('.self::PATH.') — repli textuel.');

            return self::$cached = null;
        }

        return self::$cached = 'data:image/png;base64,'.base64_encode($bytes);
    }

    /** Remet le cache à zéro — pour les tests, qui déposent et retirent le fichier. */
    public static function forget(): void
    {
        self::$cached = null;
        self::$resolved = false;
    }
}
