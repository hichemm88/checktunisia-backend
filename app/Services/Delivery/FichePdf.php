<?php

namespace App\Services\Delivery;

use App\Models\WhatsappSendLog;
use App\Services\Whatsapp\FicheFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

/**
 * Rend UNE fiche de police en PDF, pièce d'identité comprise.
 *
 * ── Pourquoi un PDF plutôt qu'un message texte ───────────────────────────────
 *
 * La Cloud API refuse le texte libre hors de la fenêtre de 24 h, et nos
 * destinataires ne répondent jamais : tout passe donc par un modèle approuvé.
 * Or une variable de modèle ne peut contenir aucun retour à la ligne — la
 * fiche, multi-ligne, ne rentre dans aucune variable.
 *
 * La fiche part donc en pièce jointe, dans l'en-tête « document » du modèle.
 * Ce détour règle au passage la photo : elle est dans le PDF, alors que
 * l'adaptateur Cloud ne savait jusqu'ici envoyer que du texte nu.
 *
 * Même vue Blade que l'export par email (`pdf.police-fiches`, avec une seule
 * fiche) : ce que l'autorité reçoit par WhatsApp et ce qu'elle reçoit par mail
 * sont le même document, et non deux mises en page à maintenir en parallèle.
 */
class FichePdf
{
    /**
     * @return string|null octets PDF, ou null si le job ne porte pas de fiche
     *                     réelle (message de test, check-in supprimé).
     */
    public static function forJob(WhatsappSendLog $job): ?string
    {
        $checkIn = $job->checkIn;
        $guest = $job->guest;

        if (!$checkIn || !$guest) {
            // Fiche de test, ou check-in supprimé depuis l'enfilage. L'en-tête
            // du modèle étant obligatoire, on rend un document factice plutôt
            // que rien : sans lui, la fiche de test — le seul moyen d'exercer
            // la chaîne sans déranger un policier — serait inenvoyable.
            return $job->is_test ? self::sample() : null;
        }

        $checkIn->loadMissing(['hotel.address', 'room', 'guests.documents']);

        $fiche = FicheFormatter::fields($checkIn, $guest);
        $fiche['photo'] = FicheScanImage::dataUri($checkIn, $guest);

        $hotel = $checkIn->hotel;

        return Pdf::loadView('pdf.police-fiches', [
            'hotelName' => $hotel?->name ?? '—',
            'hotelAddress' => trim(implode(', ', array_filter([
                $hotel?->address?->line1, $hotel?->address?->city, $hotel?->address?->governorate,
            ]))) ?: '—',
            'rangeLabel' => Carbon::parse($checkIn->check_in_date)->format('d/m/Y'),
            'count' => 1,
            'generatedAt' => Carbon::now('Africa/Tunis')->format('d/m/Y H:i'),
            'fiches' => [$fiche],
        ])->setPaper('a4')->output();
    }

    /**
     * PDF factice, pour les essais et pour l'exemple soumis à Meta.
     *
     * Deux besoins que rien d'autre ne couvrait :
     *
     *  - l'en-tête du modèle est un DOCUMENT, donc OBLIGATOIRE à l'envoi. Une
     *    fiche de test sans check-in n'avait aucun PDF, et l'essai échouait en
     *    132000 — c'est-à-dire que le seul moyen de vérifier la chaîne était
     *    d'envoyer une vraie fiche à un vrai policier ;
     *  - la création du modèle chez Meta exige un exemple de média.
     *
     * Aucune donnée réelle : ni ici, ni dans ce qui part chez Meta.
     */
    public static function sample(): string
    {
        $now = Carbon::now('Africa/Tunis');

        return Pdf::loadView('pdf.police-fiches', [
            'hotelName' => 'RESIDENCE EXEMPLE',
            'hotelAddress' => '12 rue de l\'Exemple, Tunis',
            'rangeLabel' => $now->format('d/m/Y'),
            'count' => 1,
            'generatedAt' => $now->format('d/m/Y H:i'),
            'fiches' => [[
                'last_name' => 'EXEMPLE',
                'first_name' => 'Voyageur',
                'nationality' => 'Tunisie',
                'sex' => '—',
                'dob' => '01/01/1990',
                'birth_place' => '—',
                'document' => 'Passeport n° X0000000',
                'arrival' => $now->format('d/m/Y'),
                'departure' => $now->copy()->addDays(2)->format('d/m/Y'),
                'room' => '000',
                'reference' => 'TEST',
                'companions' => '',
                'photo' => null,
            ]],
        ])->setPaper('a4')->output();
    }

    /** Nom de fichier lisible pour le destinataire, qui empile toutes les fiches. */
    public static function filenameFor(WhatsappSendLog $job): string
    {
        $guest = $job->guest;
        $who = $guest
            ? trim(mb_strtoupper((string) $guest->last_name).' '.(string) $guest->first_name)
            : 'fiche';

        // Le destinataire trie à la main dans un fil unique : le nom du fichier
        // est souvent la seule chose qu'il voit avant d'ouvrir.
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $who);

        return 'fiche-police-'.trim((string) $slug, '-').'.pdf';
    }
}
