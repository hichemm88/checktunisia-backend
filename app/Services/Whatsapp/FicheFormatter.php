<?php

namespace App\Services\Whatsapp;

use App\Models\CheckIn;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Guest;
use Illuminate\Support\Carbon;

/**
 * MODULE PROVISOIRE — à retirer après homologation MI.
 * Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
 *
 * Rend la fiche de police en texte brut, prête à servir de légende WhatsApp.
 *
 * Contraintes produit :
 *  - Nom de la propriété EN TÊTE (le destinataire trie sur ce fil unique vers
 *    quel poste transférer).
 *  - Emoji-free sauf le 🔹 d'en-tête (décision produit « emoji-free » mobile v2).
 *  - Format transférable tel quel (photo + légende dans un seul message).
 */
class FicheFormatter
{
    private const TZ = 'Africa/Tunis';

    /**
     * @param  CheckIn  $checkIn  avec hotel, room et guests chargés.
     * @param  Guest  $guest  le voyageur concerné par cette fiche.
     */
    public static function format(CheckIn $checkIn, Guest $guest): string
    {
        $propertyName = $checkIn->hotel?->name ?? 'Propriété';

        $companions = $checkIn->guests->reject(fn ($g) => $g->id === $guest->id)->values();

        $lines = [];
        $lines[] = '🔹 FICHE DE POLICE — '.mb_strtoupper($propertyName);
        $lines[] = 'Nom : '.self::fullName($guest);
        $lines[] = 'Nationalité : '.self::nationality($guest->nationality_code);
        $lines[] = 'Document : '.self::document($guest);
        $lines[] = 'Date de naissance : '.self::date($guest->date_of_birth);
        $lines[] = 'Arrivée : '.self::date($checkIn->check_in_date)
            .' — Départ : '.self::date($checkIn->expected_check_out_date);
        $lines[] = 'Chambre : '.self::room($checkIn);
        $lines[] = 'Nb. accompagnants : '.$companions->count()
            .($companions->isNotEmpty()
                ? ' ('.$companions->map(fn ($g) => self::fullName($g))->implode(', ').')'
                : '');
        $lines[] = '—';
        $lines[] = 'Envoyé via Qayed ('.Carbon::now(self::TZ)->format('d/m/Y H:i').')';

        return implode("\n", $lines);
    }

    /** Fiche factice marquée [TEST] pour le bouton « message test » admin. */
    public static function testFiche(?string $propertyName = null): string
    {
        $now = Carbon::now(self::TZ);

        return implode("\n", [
            '[TEST] 🔹 FICHE DE POLICE — '.mb_strtoupper($propertyName ?? 'QAYED DÉMO'),
            'Nom : EXEMPLE Voyageur',
            'Nationalité : Tunisie',
            'Document : Passeport n° X0000000',
            'Date de naissance : 01/01/1990',
            'Arrivée : '.$now->format('d/m/Y').' — Départ : '.$now->copy()->addDays(2)->format('d/m/Y'),
            'Chambre : 000',
            'Nb. accompagnants : 0',
            '—',
            'Message de test — Envoyé via Qayed ('.$now->format('d/m/Y H:i').')',
        ]);
    }

    private static function fullName(Guest $guest): string
    {
        return trim(mb_strtoupper((string) $guest->last_name).' '.$guest->first_name);
    }

    private static function nationality(?string $alpha3): string
    {
        if (! $alpha3) {
            return '—';
        }

        return Country::where('alpha3', $alpha3)->value('name_fr') ?? $alpha3;
    }

    private static function document(Guest $guest): string
    {
        $doc = $guest->documents->first();
        if (! $doc) {
            return '—';
        }
        $label = DocumentType::where('code', $doc->type)->value('name_fr') ?? ucfirst((string) $doc->type);
        $number = $doc->document_number ?: '—';

        return "{$label} n° {$number}";
    }

    private static function room(CheckIn $checkIn): string
    {
        $number = $checkIn->room?->number;

        return $number !== null && $number !== '' ? (string) $number : '—';
    }

    private static function date($value): string
    {
        if (! $value) {
            return '—';
        }

        return $value instanceof Carbon
            ? $value->format('d/m/Y')
            : Carbon::parse($value)->format('d/m/Y');
    }
}
