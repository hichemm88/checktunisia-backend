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
}
