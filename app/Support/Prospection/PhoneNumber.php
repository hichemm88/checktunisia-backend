<?php

namespace App\Support\Prospection;

/**
 * Normalisation E.164 des numéros WhatsApp tunisiens (§ Modèle de données :
 * "téléphone WhatsApp (format E.164, +216…)"), pour la fiche de saisie
 * manuelle comme pour l'import CSV/XLSX du fichier existant, où les numéros
 * sont saisis dans des formats hétérogènes (espaces, tirets, "00216", "0"
 * local en tête par erreur de saisie…).
 *
 * Tunisie : numéros locaux à 8 chiffres, indicatif +216, pas de préfixe
 * interurbain à composer en local (contrairement à la France ou au
 * Royaume-Uni) — un "0" en tête est donc une erreur de saisie fréquente
 * plutôt qu'un préfixe légitime, et est tolérée ici plutôt que rejetée.
 */
final class PhoneNumber
{
    /**
     * Retourne le numéro au format E.164 (+216XXXXXXXX), ou null si le
     * numéro ne peut pas être interprété comme un numéro tunisien valide.
     */
    public static function toTunisianE164(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $hasLeadingPlus = str_starts_with(trim($raw), '+');
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        // +216XXXXXXXX déjà au bon format.
        if ($hasLeadingPlus && str_starts_with($digits, '216') && strlen($digits) === 11) {
            return '+'.$digits;
        }

        // 00216XXXXXXXX — préfixe international composé au lieu du "+".
        if (!$hasLeadingPlus && str_starts_with($digits, '00216') && strlen($digits) === 13) {
            return '+'.substr($digits, 2);
        }

        // 216XXXXXXXX sans indicatif international explicite.
        if (!$hasLeadingPlus && str_starts_with($digits, '216') && strlen($digits) === 11) {
            return '+'.$digits;
        }

        // 0XXXXXXXXX — "0" local ajouté par erreur devant les 8 chiffres.
        if (!$hasLeadingPlus && strlen($digits) === 9 && str_starts_with($digits, '0')) {
            return '+216'.substr($digits, 1);
        }

        // XXXXXXXX — les 8 chiffres locaux, sans indicatif.
        if (strlen($digits) === 8) {
            return '+216'.$digits;
        }

        return null;
    }

    public static function isValidTunisianE164(?string $value): bool
    {
        return $value !== null && (bool) preg_match('/^\+216\d{8}$/', $value);
    }
}
