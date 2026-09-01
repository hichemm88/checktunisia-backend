<?php

namespace App\Console\Commands;

use App\Mail\PoliceDailyDigest;
use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\User;
use App\Services\Delivery\FicheScanImage;
use App\Services\Whatsapp\FicheFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Récapitulatif quotidien : un PDF des arrivées du jour, tous établissements
 * du destinataire, pièces d'identité comprises, envoyé par email.
 *
 * ── Pourquoi cette commande existe ───────────────────────────────────────────
 *
 * Le relais WhatsApp est hors service — Meta a restreint le numéro émetteur le
 * 17/08/2026. L'exploitant s'absente jusqu'au 28 et retransmettra lui-même aux
 * autorités : il lui faut donc, chaque soir, UN document complet et prêt à
 * transférer. L'export existant ne couvre qu'un établissement à la fois et
 * n'écrit qu'au manager de cet établissement.
 *
 * ── Le périmètre est le point sensible ───────────────────────────────────────
 *
 * Qayed héberge les voyageurs de PLUSIEURS clients. « Tous mes établissements »
 * ne peut pas se traduire par « tous les établissements de la plateforme » :
 * envoyer à une boîte personnelle les pièces d'identité des clients d'autrui
 * serait une violation caractérisée. Le périmètre est donc déduit du
 * destinataire — son organisation, plus les établissements qui lui sont
 * explicitement rattachés — et la commande REFUSE de s'exécuter si elle ne peut
 * pas l'établir, plutôt que d'élargir par défaut.
 *
 * Les établissements retenus sont affichés à chaque exécution : un périmètre
 * silencieux est un périmètre que personne ne vérifie.
 */
class SendPoliceDailyDigest extends Command
{
    /**
     * Sans argument, la fenêtre est GLISSANTE sur 24 h — et non « le jour
     * calendaire ».
     *
     * Un envoi à 17 h couvrant la journée civile perdrait définitivement les
     * arrivées enregistrées après 17 h : le lendemain, le récapitulatif couvre
     * le jour suivant, et celles de la veille au soir ne partent jamais. Pour
     * une transmission légale, une omission silencieuse est le pire défaut
     * possible.
     *
     * 24 h s'aligne aussi sur la rétention des pièces d'identité (voir
     * whatsapp.image_retention_hours) : au-delà, les photos ont été purgées et
     * le document partirait incomplet.
     *
     * L'argument `date` force une journée civile — pour un rattrapage.
     */
    protected $signature = 'police:daily-digest
        {date? : Rattrapage sur une journée civile (AAAA-MM-JJ), au lieu des dernières 24 h}
        {--to= : Destinataire. Défaut : POLICE_DIGEST_RECIPIENT}';

    protected $description = 'Envoie par email le PDF des fiches de police des arrivées du jour (tous établissements)';

    public function handle(): int
    {
        $recipient = (string) ($this->option('to') ?: config('police_digest.recipient'));

        if ($recipient === '') {
            $this->warn('POLICE_DIGEST_RECIPIENT non défini — rien à envoyer.');

            return self::SUCCESS;
        }

        if ($this->pastDeadline()) {
            // Fin de l'absence : la tâche s'éteint d'elle-même. Sans cette
            // borne, un envoi quotidien de pièces d'identité continuerait
            // indéfiniment parce que personne n'aurait pensé à l'arrêter.
            $this->info('Au-delà de POLICE_DIGEST_UNTIL ('.config('police_digest.until').') — envoi arrêté.');

            return self::SUCCESS;
        }

        $day = $this->argument('date')
            ? Carbon::parse($this->argument('date'), 'Africa/Tunis')->startOfDay()
            : null;

        $hotels = $this->hotelsInScope($recipient);

        if ($hotels->isEmpty()) {
            $this->error('Aucun établissement dans le périmètre de '.$recipient.'.');
            $this->line('Renseignez POLICE_DIGEST_HOTELS (identifiants séparés par des virgules) pour le fixer explicitement.');

            return self::FAILURE;
        }

        $this->line('Périmètre : '.$hotels->pluck('name')->implode(', '));

        [$groups, $total, $withoutPhoto] = $this->buildGroups($hotels, $day);

        $dateLabel = $day
            ? $day->format('d/m/Y')
            : Carbon::now('Africa/Tunis')->subDay()->format('d/m/Y H:i')
                .' → '.Carbon::now('Africa/Tunis')->format('d/m/Y H:i');

        $pdf = '';

        if ($total > 0) {
            $pdf = Pdf::loadView('pdf.police-daily-digest', [
                'dateLabel' => $dateLabel,
                'generatedAt' => Carbon::now('Africa/Tunis')->format('d/m/Y H:i'),
                'groups' => $groups,
                'total' => $total,
            ])->setPaper('a4')->output();
        }

        Mail::to($recipient)->send(new PoliceDailyDigest(
            $dateLabel,
            $total,
            count($groups),
            $withoutPhoto,
            $pdf,
            'fiches-police-'.($day ?? Carbon::now('Africa/Tunis'))->format('Y-m-d').'.pdf',
        ));

        Log::info('[police-digest] '.$total.' fiche(s) du '.$dateLabel.' envoyées à '.$recipient.'.');
        $this->info($total.' fiche(s) envoyée(s) à '.$recipient.'.');

        return self::SUCCESS;
    }

    /** La période d'envoi est-elle terminée ? */
    private function pastDeadline(): bool
    {
        $until = (string) config('police_digest.until');

        if ($until === '') {
            return false;
        }

        return Carbon::now('Africa/Tunis')->startOfDay()
            ->gt(Carbon::parse($until, 'Africa/Tunis')->startOfDay());
    }

    /**
     * Établissements couverts par l'envoi.
     *
     * Priorité à la liste explicite : c'est la seule façon d'élargir, et elle
     * demande un geste délibéré. Sinon, déduction depuis le destinataire —
     * jamais « tous les établissements de la plateforme ».
     *
     * @return Collection<int,Hotel>
     */
    private function hotelsInScope(string $recipient)
    {
        if ($explicit = (string) config('police_digest.hotels')) {
            $ids = array_filter(array_map('trim', explode(',', $explicit)));

            return Hotel::with('address')->whereIn('id', $ids)->orderBy('name')->get();
        }

        $user = User::where('email', $recipient)->first();

        if (!$user) {
            $this->warn('Aucun compte Qayed pour '.$recipient.' — le périmètre ne peut pas être déduit.');

            return collect();
        }

        $ids = $user->hotels()->pluck('hotels.id');

        if ($user->organization_id) {
            $ids = $ids->merge(Hotel::where('organization_id', $user->organization_id)->pluck('id'));
        }

        return Hotel::with('address')->whereIn('id', $ids->unique())->orderBy('name')->get();
    }

    /**
     * Fiches du jour, groupées par établissement.
     *
     * @return array{0:array<int,array<string,mixed>>,1:int,2:int}
     */
    private function buildGroups($hotels, ?Carbon $day): array
    {
        $groups = [];
        $total = 0;
        $withoutPhoto = 0;

        foreach ($hotels as $hotel) {
            $checkIns = CheckIn::with(['guests.documents', 'room'])
                ->where('hotel_id', $hotel->id)
                ->whereIn('status', ['active', 'completed'])
                ->when(
                    $day,
                    // Rattrapage : journée civile, sur la date de séjour.
                    fn ($q) => $q->whereDate('check_in_date', $day->toDateString()),
                    // Nominal : fenêtre glissante sur la date d'ENREGISTREMENT.
                    // C'est le moment où la fiche est née, donc le seul critère
                    // qui garantisse qu'aucune n'est sautée entre deux envois.
                    fn ($q) => $q->where('created_at', '>=', now()->subDay()),
                )
                ->orderBy('created_at')
                ->get();

            $fiches = [];
            $withPhoto = 0;

            foreach ($checkIns as $checkIn) {
                $guests = $checkIn->guests->sortByDesc(fn ($g) => (bool) ($g->pivot->is_primary ?? false));

                foreach ($guests as $guest) {
                    $fiche = FicheFormatter::fields($checkIn, $guest);
                    $fiche['photo'] = FicheScanImage::dataUri($checkIn, $guest);
                    $fiche['photo'] ? $withPhoto++ : $withoutPhoto++;
                    $fiches[] = $fiche;
                }
            }

            // Un établissement sans arrivée n'encombre pas le document : le
            // sommaire annonce ce qui existe, pas une liste de zéros.
            if ($fiches === []) {
                continue;
            }

            $total += count($fiches);
            $groups[] = [
                'name' => $hotel->name,
                'address' => trim(implode(', ', array_filter([
                    $hotel->address?->line1, $hotel->address?->city, $hotel->address?->governorate,
                ]))) ?: '—',
                'fiches' => $fiches,
                'with_photo' => $withPhoto,
            ];
        }

        return [$groups, $total, $withoutPhoto];
    }
}
