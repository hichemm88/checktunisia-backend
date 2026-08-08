<?php

namespace App\Jobs;

use App\Mail\PoliceFichesExport;
use App\Models\CheckIn;
use App\Models\Hotel;
use App\Services\Whatsapp\FicheFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Génère le PDF des fiches de police d'un établissement sur une plage de dates
 * (fiche texte par voyageur, statuts finalisés) et l'envoie par email au
 * manager. En tâche de fond : une grande plage = beaucoup de fiches.
 */
class ExportPoliceFichesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Politique de reprise portée par le job, pas par la ligne de commande.
     *
     * Elle ne dépendait jusqu'ici que du `--tries=3` du drainage par le
     * planificateur : un worker lancé autrement (service dédié, exécution
     * manuelle) aurait fait échouer le job à la première erreur réseau du SMTP,
     * sans aucune reprise. Le manager n'aurait jamais reçu son export et
     * personne n'aurait été prévenu.
     */
    public $tries = 3;

    /** Attente croissante : 10 s, 1 min, 5 min — laisse le temps à un SMTP ou une base de revenir. */
    public $backoff = [10, 60, 300];

    /** Génération PDF d'une longue plage : le défaut de 60 s est trop court. */
    public $timeout = 300;

    public function __construct(
        public readonly string $hotelId,
        public readonly string $dateFrom,
        public readonly string $dateTo,
        public readonly string $email,
    ) {}

    public function handle(): void
    {
        $hotel = Hotel::with('address')->find($this->hotelId);
        if (! $hotel) {
            return;
        }

        $checkIns = CheckIn::with(['guests.documents', 'room'])
            ->where('hotel_id', $hotel->id)
            ->whereIn('status', ['active', 'completed'])
            ->whereBetween('check_in_date', [$this->dateFrom, $this->dateTo])
            ->orderBy('check_in_date')
            ->get();

        // Une fiche par voyageur (principal d'abord).
        $fiches = [];
        foreach ($checkIns as $ci) {
            $guests = $ci->guests->sortByDesc(fn ($g) => (bool) ($g->pivot->is_primary ?? false));
            foreach ($guests as $guest) {
                $fiches[] = FicheFormatter::fields($ci, $guest);
            }
        }

        $fmt = fn ($d) => Carbon::parse($d)->format('d/m/Y');
        $rangeLabel = $fmt($this->dateFrom).' – '.$fmt($this->dateTo);

        $pdf = Pdf::loadView('pdf.police-fiches', [
            'hotelName'    => $hotel->name,
            'hotelAddress' => trim(implode(', ', array_filter([
                $hotel->address?->line1, $hotel->address?->city, $hotel->address?->governorate,
            ]))) ?: '—',
            'rangeLabel'   => $rangeLabel,
            'count'        => count($fiches),
            'generatedAt'  => Carbon::now('Africa/Tunis')->format('d/m/Y H:i'),
            'fiches'       => $fiches,
        ])->setPaper('a4');

        $filename = 'fiches-police-'.$this->dateFrom.'_'.$this->dateTo.'.pdf';

        try {
            Mail::to($this->email)->send(new PoliceFichesExport(
                $hotel->name, $rangeLabel, count($fiches), $pdf->output(), $filename,
            ));
        } catch (\Throwable $e) {
            Log::warning('[export-fiches] envoi email échoué ('.$this->email.') : '.$e->getMessage());
            throw $e; // laisse la file retenter
        }
    }

    /**
     * Appelé après épuisement des tentatives, quand le job part en dead-letter.
     *
     * Sans ceci, un export définitivement perdu ne laissait qu'une ligne dans
     * `failed_jobs` : le manager attend un email qui n'arrivera jamais, et rien
     * ne le signale. La trace ici porte de quoi rejouer manuellement le job
     * (établissement, plage), et le compteur d'échecs remonte dans
     * GET /admin/health.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('[export-fiches] abandon définitif après '.$this->tries.' tentatives', [
            'hotel_id'  => $this->hotelId,
            'date_from' => $this->dateFrom,
            'date_to'   => $this->dateTo,
            'error'     => $e->getMessage(),
            // Pas d'adresse email dans les logs : c'est une donnée personnelle
            // (§ journalisation PII du rapport d'audit).
        ]);
    }
}
