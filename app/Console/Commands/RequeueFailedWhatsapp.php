<?php

namespace App\Console\Commands;

use App\Models\WhatsappSendLog;
use App\Services\Whatsapp\WhatsappSendingGuard;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Remet en file les fiches tombées en « échec définitif » sur une erreur qui
 * ne leur appartenait pas.
 *
 * Le cas qui l'a fait naître : WHATSAPP_SENDING_ENABLED est passé à true alors
 * que le modèle des fiches était encore en attente d'approbation chez Meta.
 * Chaque fiche présentée a reçu un 132001, a brûlé ses tentatives, et a été
 * abandonnée au bout de 24 h avec une alerte — alors qu'aucune n'avait la
 * moindre chance d'aboutir et que toutes redeviennent envoyables à la seconde
 * où le modèle est approuvé.
 *
 * Ce sont des fiches de police : elles ne peuvent pas rester « échouées ».
 *
 * Trois propriétés, reprises de whatsapp:cancel-backlog parce qu'elles valent
 * pour toute écriture de masse sur le registre légal :
 *
 *  - DRY-RUN PAR DÉFAUT. On lit d'abord le décompte, on écrit ensuite.
 *  - BORNÉE À L'APRÈS-BASCULE. Une fiche antérieure à la bascule Cloud API ne
 *    doit JAMAIS repartir : la remettre en file ne ferait que la représenter à
 *    un garde-fou qui la refuserait, et elle n'a de toute façon plus d'objet —
 *    les séjours sont terminés.
 *  - IDEMPOTENTE. Ne vise que les lignes encore en échec.
 *
 *   php artisan whatsapp:requeue-failed --reason=132001            # décompte
 *   php artisan whatsapp:requeue-failed --reason=132001 --apply    # écrit
 */
class RequeueFailedWhatsapp extends Command
{
    protected $signature = 'whatsapp:requeue-failed
        {--reason=132001 : Code d\'erreur Meta à rejouer (ex. 132001)}
        {--apply : Écrit réellement la remise en file (sans ce drapeau, rien n\'est modifié)}
        {--since= : Date de coupure ISO 8601 (défaut : WHATSAPP_CLOUD_API_CUTOVER_AT)}';

    protected $description = 'Remet en file les fiches WhatsApp en échec définitif sur un code d\'erreur donné.';

    public function handle(WhatsappSendingGuard $guard): int
    {
        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->error('--reason est obligatoire : il n\'existe pas de remise en file « de tout ».');

            return self::FAILURE;
        }

        $since = $this->resolveSince($guard);

        if ($since === null) {
            $this->error(
                'Aucune date de coupure. Définir WHATSAPP_CLOUD_API_CUTOVER_AT, ou passer --since=2026-09-01T00:00:00+01:00.'
            );

            return self::FAILURE;
        }

        $this->line('Code rejoué : '.$reason);
        $this->line('Postérieures à : '.$since->toIso8601String());

        $query = $this->target($reason, $since);
        $total = (clone $query)->count();

        $this->newLine();
        $this->line('Fiches en échec définitif sur ce code : '.$total);

        if ($total === 0) {
            $this->info('Rien à remettre en file.');

            return self::SUCCESS;
        }

        $this->renderBreakdown($reason, $since);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn("{$total} fiche(s) SERAIENT remises en file. Rien n'a été modifié.");
            $this->line('Relancer avec --apply une fois le modèle approuvé (ou tout de suite : le garde-fou');
            $this->line('d\'approbation retiendra les envois jusqu\'à l\'approbation, sans rien retenter).');

            return self::SUCCESS;
        }

        $requeued = 0;

        (clone $query)->orderBy('created_at')->chunkById(200, function ($jobs) use (&$requeued) {
            foreach ($jobs as $job) {
                $job->update([
                    'status' => WhatsappSendLog::STATUS_PENDING,
                    // Les tentatives passées n'ont éprouvé que le réglage du
                    // canal. Les conserver rapprocherait l'entrée d'un second
                    // abandon pour la même raison qu'au premier.
                    'attempts' => 0,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    /*
                     * L'horloge des 24 h repart d'ici.
                     *
                     * `queued_at` la porte ; `created_at` ne bouge PAS, et
                     * c'est ce qui importe : c'est lui, et lui seul, que le
                     * garde-fou de bascule regarde pour distinguer une fiche
                     * de l'arriéré d'une fiche légitime.
                     */
                    'queued_at' => now(),
                ]);
                $requeued++;
            }
        });

        $this->newLine();
        $this->info("{$requeued} fiche(s) remises en file.");
        $this->line('Elles partiront à la prochaine passe de « whatsapp:dispatch » où le modèle sera approuvé.');

        return self::SUCCESS;
    }

    /**
     * Les fiches visées.
     *
     * Le code est cherché dans `error_code` ET dans le libellé : `error_code`
     * n'existe que depuis la migration Cloud API, et le journal admin affiche
     * les erreurs sous la forme « [132001] … ». Rater des lignes parce que la
     * colonne était vide serait laisser des fiches de police en échec.
     */
    private function target(string $reason, Carbon $since): Builder
    {
        return WhatsappSendLog::query()
            ->where('status', WhatsappSendLog::STATUS_FAILED)
            ->where('created_at', '>=', $since)
            ->where(fn ($q) => $q
                ->where('error_code', $reason)
                ->orWhere('last_error', 'like', '%['.$reason.']%'));
    }

    /** De quoi vérifier les chiffres avant d'écrire. */
    private function renderBreakdown(string $reason, Carbon $since): void
    {
        $rows = $this->target($reason, $since)
            ->selectRaw('date(created_at) as jour, count(*) as total, count(distinct hotel_id) as etablissements')
            ->groupByRaw('date(created_at)')
            ->orderByRaw('date(created_at)')
            ->get();

        $this->newLine();
        $this->table(
            ['Jour', 'Fiches', 'Établissements'],
            $rows->map(fn ($r) => [$r->jour, $r->total, $r->etablissements])->all(),
        );

        $oldest = $this->target($reason, $since)->min('created_at');

        $this->line('Plus ancienne : '.($oldest ? Carbon::parse($oldest)->toDateTimeString() : '—'));
    }

    private function resolveSince(WhatsappSendingGuard $guard): ?Carbon
    {
        if ($raw = $this->option('since')) {
            try {
                return Carbon::parse((string) $raw);
            } catch (\Throwable $e) {
                $this->error('--since illisible : '.$raw);

                return null;
            }
        }

        return $guard->cutoverAt();
    }
}
