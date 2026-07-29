<?php

namespace App\Console\Commands;

use App\Models\CheckIn;
use App\Models\CheckInGuest;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\TravelDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Supprime UNIQUEMENT les fiches de démo marquées SEED-DEMO-2026 de
 * l'établissement « Dar El Kenz » (créées par demo:seed-dar-el-kenz), ainsi
 * que leurs voyageurs/documents seedés devenus orphelins.
 *
 * Garde-fous :
 *  - Établissement résolu par nom exact ; 0 ou >1 match → ABORT.
 *  - Sélection = hotel_id résolu ET marqueur metadata.seed_batch (jamais l'un
 *    sans l'autre). Suppression physique (forceDelete) pour purger réellement,
 *    la FK check_in_guests cascade, travel_documents cascade via guests.
 *  - Un guest seedé encore lié à une fiche NON seedée n'est pas supprimé.
 *  - Dry-run par défaut ; suppression réelle avec --commit. Counts par
 *    établissement comparés AVANT/APRÈS dans la transaction : si un autre
 *    établissement a bougé → rollback + erreur.
 */
class CleanupDarElKenzDemoFiches extends Command
{
    protected $signature = 'demo:cleanup-dar-el-kenz {--commit : Supprime réellement (sinon dry-run)}';

    protected $description = 'Supprime les fiches de démo SEED-DEMO-2026 de Dar El Kenz uniquement';

    private const HOTEL_NAME = 'Dar El Kenz';
    private const MARKER     = 'SEED-DEMO-2026';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');

        $matches = Hotel::where('name', self::HOTEL_NAME)->get();
        if ($matches->count() !== 1) {
            $this->error(sprintf(
                'ABORT — établissement « %s » : %d correspondance(s) exacte(s) (1 attendue). Aucune écriture.',
                self::HOTEL_NAME,
                $matches->count(),
            ));

            return self::FAILURE;
        }
        $hotel = $matches->first();

        DB::beginTransaction();

        try {
            $before = $this->snapshotCounts();

            // Fiches seedées de CET établissement uniquement (marqueur + hotel_id)
            $checkIns = CheckIn::withTrashed()
                ->where('hotel_id', $hotel->id)
                ->where('metadata->seed_batch', self::MARKER)
                ->get();

            if ($checkIns->isEmpty()) {
                DB::rollBack();
                $this->info('Aucune fiche marquée '.self::MARKER.' trouvée pour '.$hotel->name.'. Rien à faire.');

                return self::SUCCESS;
            }

            $checkInIds = $checkIns->pluck('id');

            // Guests seedés liés à ces fiches…
            $guestIds = CheckInGuest::whereIn('check_in_id', $checkInIds)->pluck('guest_id')->unique();
            $seedGuests = Guest::withTrashed()
                ->whereIn('id', $guestIds)
                ->where('metadata->seed_batch', self::MARKER)
                ->get();

            // …mais on ne supprime jamais un guest encore lié à une fiche non seedée.
            $deletableGuestIds = $seedGuests->pluck('id')->filter(function ($gid) use ($checkInIds) {
                return ! CheckInGuest::where('guest_id', $gid)
                    ->whereNotIn('check_in_id', $checkInIds)
                    ->exists();
            })->values();

            $docsCount = TravelDocument::whereIn('guest_id', $deletableGuestIds)
                ->where('metadata->seed_batch', self::MARKER)
                ->count();

            $this->info(sprintf(
                '%s (%s) — à supprimer : %d fiches, %d voyageurs seedés (%d conservés car liés ailleurs), %d documents [%s]',
                $hotel->name,
                $hotel->id,
                $checkIns->count(),
                $deletableGuestIds->count(),
                $seedGuests->count() - $deletableGuestIds->count(),
                $docsCount,
                $commit ? 'COMMIT' : 'DRY-RUN',
            ));

            $this->table(
                ['Réf', 'Statut', 'Arrivée', 'Notes'],
                $checkIns->map(fn ($c) => [$c->reference, $c->status, $c->check_in_date?->toDateString(), $c->notes])->toArray(),
            );

            // Suppression physique : check_in_guests + document_scans cascadent
            // sur le forceDelete des check_ins ; travel_documents cascadent sur
            // celui des guests.
            foreach ($checkIns as $checkIn) {
                $checkIn->forceDelete();
            }
            Guest::withTrashed()->whereIn('id', $deletableGuestIds)->get()->each->forceDelete();

            $after = $this->snapshotCounts();

            // Seuls les counts de Dar El Kenz ont le droit d'avoir bougé.
            foreach ($before['check_ins_by_hotel'] as $hid => $prev) {
                $now = $after['check_ins_by_hotel'][$hid] ?? 0;
                if ($hid === $hotel->id) {
                    if ($prev - $now !== $checkIns->count()) {
                        throw new \RuntimeException(sprintf('Delta inattendu sur Dar El Kenz : -%d (attendu -%d).', $prev - $now, $checkIns->count()));
                    }
                    continue;
                }
                if ($now !== $prev) {
                    throw new \RuntimeException(sprintf('VIOLATION : le count de l\'établissement %s a changé (%d → %d).', $hid, $prev, $now));
                }
            }

            $this->line(sprintf(
                'Counts — check_ins %s : %d → %d | guests : %d → %d | travel_documents : %d → %d',
                $hotel->name,
                $before['check_ins_by_hotel'][$hotel->id] ?? 0,
                $after['check_ins_by_hotel'][$hotel->id] ?? 0,
                $before['guests'],
                $after['guests'],
                $before['travel_documents'],
                $after['travel_documents'],
            ));

            if ($commit) {
                DB::commit();
                $this->info('✔ COMMIT — fiches de démo supprimées.');
            } else {
                DB::rollBack();
                $this->warn('DRY-RUN — transaction annulée, rien n\'a été supprimé. Relancer avec --commit pour purger.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('ROLLBACK — '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function snapshotCounts(): array
    {
        return [
            'check_ins_by_hotel' => CheckIn::withTrashed()
                ->select('hotel_id', DB::raw('count(*) as n'))
                ->groupBy('hotel_id')
                ->pluck('n', 'hotel_id')
                ->toArray(),
            'guests'           => Guest::withTrashed()->count(),
            'travel_documents' => TravelDocument::count(),
        ];
    }
}
