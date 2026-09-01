<?php

namespace App\Console\Commands;

use App\Models\WhatsappSendLog;
use App\Services\Whatsapp\WhatsappOutboxService;
use App\Services\Whatsapp\WhatsappSendingGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Neutralise l'arriéré de fiches accumulé pendant le bannissement du relais
 * WhatsApp Web.
 *
 * Le relais est hors service depuis le 17/08/2026 ; plusieurs centaines de
 * fiches sont restées « en attente ». Les laisser partir à la bascule
 * produirait une rafale depuis un numéro NEUF vers des comptes d'officiels
 * qui ne lui ont jamais écrit — le profil exact que Meta bannit, et les
 * séjours concernés sont de toute façon terminés.
 *
 * Trois propriétés non négociables :
 *
 *  - DRY-RUN PAR DÉFAUT. Une écriture de masse sur le registre légal ne
 *    s'exécute pas parce qu'on a tapé une commande, mais parce qu'on a lu ce
 *    qu'elle allait faire.
 *  - RIEN N'EST SUPPRIMÉ. Les fiches passent en « annulé » avec un motif et un
 *    horodatage, et restent consultables dans le journal admin.
 *  - IDEMPOTENTE. Ne vise que les lignes encore « en attente » : relancée, la
 *    commande ne trouve plus rien.
 *
 *   php artisan whatsapp:cancel-backlog             # décompte, n'écrit rien
 *   php artisan whatsapp:cancel-backlog --apply     # annule + rapport CSV
 */
class CancelWhatsappBacklog extends Command
{
    protected $signature = 'whatsapp:cancel-backlog
        {--apply : Écrit réellement les annulations (sans ce drapeau, rien n\'est modifié)}
        {--before= : Date de coupure ISO 8601 (défaut : WHATSAPP_CLOUD_API_CUTOVER_AT)}
        {--csv= : Chemin du rapport CSV (défaut : storage/app/whatsapp/…)}';

    protected $description = 'Annule les fiches WhatsApp en attente antérieures à la bascule Cloud API.';

    public function handle(WhatsappOutboxService $outbox, WhatsappSendingGuard $guard): int
    {
        $cutover = $this->resolveCutover($guard);

        if ($cutover === null) {
            $this->error(
                'Aucune date de coupure. Définir WHATSAPP_CLOUD_API_CUTOVER_AT, ou passer --before=2026-08-31T12:00:00+01:00.'
            );

            return self::FAILURE;
        }

        $this->line('Coupure : '.$cutover->toIso8601String());

        $query = WhatsappSendLog::query()
            ->where('status', WhatsappSendLog::STATUS_PENDING)
            ->where('created_at', '<', $cutover);

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Aucune fiche en attente antérieure à la coupure — rien à faire.');

            return self::SUCCESS;
        }

        $this->renderBreakdown($cutover);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn("{$total} fiche(s) SERAIENT annulées. Rien n'a été modifié.");
            $this->line('Relancer avec --apply pour écrire, après vérification des chiffres ci-dessus.');

            return self::SUCCESS;
        }

        $path = $this->csvPath();
        $written = $this->writeReport($path, $cutover);

        // Le rapport est écrit AVANT l'annulation : si l'écriture échoue, on
        // n'a pas encore touché au registre — l'inverse laisserait des fiches
        // annulées sans trace exploitable de ce qu'elles contenaient.
        $this->info("Rapport CSV : {$path} ({$written} ligne(s))");

        $cancelled = 0;
        $query->orderBy('created_at')->chunkById(200, function ($jobs) use ($outbox, &$cancelled) {
            foreach ($jobs as $job) {
                $outbox->cancelPreCutover($job);
                $cancelled++;
            }
        });

        $this->info("{$cancelled} fiche(s) annulées (motif « ".WhatsappOutboxService::PRE_CUTOVER_REASON.' »).');
        $this->line('Elles restent consultables dans Administration > WhatsApp, compteur « Annulés ».');

        return self::SUCCESS;
    }

    private function resolveCutover(WhatsappSendingGuard $guard): ?Carbon
    {
        $override = $this->option('before');

        if (filled($override)) {
            try {
                return Carbon::parse($override);
            } catch (\Throwable $e) {
                $this->error('--before illisible : '.$override);

                return null;
            }
        }

        return $guard->cutoverAt();
    }

    /** Décompte par établissement et par jour — ce qu'on relit avant d'écrire. */
    private function renderBreakdown(Carbon $cutover): void
    {
        $rows = WhatsappSendLog::query()
            ->where('whatsapp_send_log.status', WhatsappSendLog::STATUS_PENDING)
            ->where('whatsapp_send_log.created_at', '<', $cutover)
            ->leftJoin('hotels', 'hotels.id', '=', 'whatsapp_send_log.hotel_id')
            ->selectRaw('COALESCE(hotels.name, \'(sans établissement)\') as hotel')
            ->selectRaw('DATE(whatsapp_send_log.created_at) as jour')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('hotel', 'jour')
            ->orderBy('hotel')
            ->orderBy('jour')
            ->get();

        $this->newLine();
        $this->table(
            ['Établissement', 'Jour', 'Fiches'],
            $rows->map(fn ($r) => [$r->hotel, $r->jour, $r->total])->all(),
        );
    }

    private function csvPath(): string
    {
        if (filled($option = $this->option('csv'))) {
            return $option;
        }

        if (filled($configured = config('whatsapp.guard.backlog_report_path'))) {
            return $configured;
        }

        // storage/ est hors du dépôt (ignoré par git) : le rapport contient
        // des données de voyageurs, il n'a rien à faire dans le code source.
        return storage_path('app/whatsapp/backlog-annule-'.now()->format('Ymd-His').'.csv');
    }

    /**
     * Rapport des fiches annulées, pour un traitement manuel ultérieur.
     *
     * @return int nombre de lignes écrites
     */
    private function writeReport(string $path, Carbon $cutover): int
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new \RuntimeException("Rapport CSV non ouvrable : {$path}");
        }

        // BOM UTF-8 : sans lui, Excel massacre les accents des noms propres.
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            'etablissement', 'voyageur', 'arrivee', 'depart',
            'destinataire', 'fiche_creee_le', 'journal_id', 'check_in_id',
        ], ';');

        $count = 0;

        WhatsappSendLog::query()
            ->with(['hotel', 'guest', 'checkIn'])
            ->where('status', WhatsappSendLog::STATUS_PENDING)
            ->where('created_at', '<', $cutover)
            ->orderBy('created_at')
            ->chunkById(200, function ($jobs) use ($handle, &$count) {
                foreach ($jobs as $job) {
                    fputcsv($handle, [
                        $job->hotel?->name ?? '',
                        trim(($job->guest?->last_name ?? '').' '.($job->guest?->first_name ?? '')),
                        $job->checkIn?->check_in_date,
                        $job->checkIn?->expected_check_out_date,
                        $job->recipient,
                        $job->created_at?->toDateTimeString(),
                        $job->id,
                        $job->check_in_id ?? '',
                    ], ';');
                    $count++;
                }
            });

        fclose($handle);

        return $count;
    }
}
