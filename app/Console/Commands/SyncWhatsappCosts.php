<?php

namespace App\Console\Commands;

use App\Services\Whatsapp\WhatsappCloudApi;
use App\Services\Whatsapp\WhatsappCostRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Rapatrie les coûts RÉELS facturés par Meta (analytics du WABA) et les
 * substitue au calcul local sur la période couverte.
 *
 * ── Pourquoi deux sources plutôt qu'une ──────────────────────────────────
 *
 * Le calcul local ne peut pas être exact, et ce n'est pas un défaut
 * d'implémentation : il ignore les paliers de volume (utility et
 * authentication deviennent moins chers au-delà de 750 k messages/mois), il
 * ignore les points d'entrée gratuits, et il applique un tarif « Tunisie »
 * uniforme à des destinataires qui peuvent être ailleurs. Il a en revanche
 * deux qualités que les analytics n'ont pas : il est disponible à la seconde,
 * et il sait à quel ÉTABLISSEMENT imputer chaque message.
 *
 * D'où le partage : Meta fait autorité sur le montant, l'estimation locale
 * fait autorité sur la ventilation par client. Les deux sont conservées.
 *
 * ── Pourquoi l'échec est silencieux ──────────────────────────────────────
 *
 * `pricing_analytics` n'est pas garanti : il dépend de la version de l'API, du
 * type de compte et des permissions du jeton système. Une absence de réponse
 * ne casse rien — la page continue d'afficher l'estimation, marquée comme
 * telle, et le champ « dernière synchro Meta » reste sur sa valeur précédente.
 * Alerter chaque nuit sur une capacité optionnelle apprendrait aux
 * administrateurs à ignorer les alertes.
 */
class SyncWhatsappCosts extends Command
{
    protected $signature = 'whatsapp:sync-costs
                            {--days= : profondeur de la fenêtre relue (défaut : whatsapp.pricing.sync.days)}';

    protected $description = 'Récupère les coûts réels WhatsApp chez Meta et remplace l\'estimation locale sur la période.';

    public function handle(WhatsappCloudApi $api, WhatsappCostRecorder $costs): int
    {
        if (! config('whatsapp.pricing.sync.enabled', true)) {
            $this->line('Synchronisation désactivée (WHATSAPP_COST_SYNC_ENABLED=false).');

            return self::SUCCESS;
        }

        if (! $api->canManageTemplates()) {
            // Même prérequis que la lecture des modèles : jeton + WABA. Sans
            // eux, il n'y a pas de compte à interroger.
            $this->warn('WHATSAPP_WABA_ID ou WHATSAPP_API_TOKEN absent — coûts réels illisibles, estimation locale conservée.');

            return self::SUCCESS;
        }

        $days = max(1, (int) ($this->option('days') ?: config('whatsapp.pricing.sync.days', 7)));

        /*
         * La fenêtre commence au DÉBUT du jour le plus ancien et finit à la FIN
         * d'aujourd'hui. Meta borne sur des timestamps UNIX : partir de
         * « maintenant moins N jours » découperait la journée la plus ancienne
         * en deux et rendrait un total partiel qui écraserait un total complet.
         */
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();

        try {
            $points = $api->pricingAnalytics($start, $end);
        } catch (\Throwable $e) {
            // Volontairement non fatal : voir l'en-tête de classe.
            Log::info('[whatsapp-costs] analytics Meta indisponibles : '.$e->getMessage());
            $this->warn('Analytics Meta indisponibles ('.$e->getMessage().') — estimation locale conservée.');

            return self::SUCCESS;
        }

        $applied = $this->apply($points, $costs);

        // L'instant est mémorisé même quand la période est vide : « aucun
        // message facturé hier » est une réponse, et la page doit pouvoir la
        // distinguer de « la synchro ne tourne plus ».
        $costs->rememberMetaSync();

        $this->info(sprintf(
            'Coûts Meta synchronisés : %d agrégat(s) sur %d jour(s), du %s au %s.',
            $applied,
            $days,
            $start->toDateString(),
            $end->toDateString(),
        ));

        return self::SUCCESS;
    }

    /**
     * Consolide les `data_points` en agrégats quotidiens par catégorie.
     *
     * Meta rend un point par jour ET par valeur de dimension, mais peut aussi
     * en rendre plusieurs pour la même paire (numéro émetteur, palier). On
     * additionne donc avant d'écrire, plutôt que d'écraser au fil des points :
     * sinon le dernier point de la journée effacerait tous les précédents.
     *
     * @param  array<int,array<string,mixed>>  $points
     */
    private function apply(array $points, WhatsappCostRecorder $costs): int
    {
        /** @var array<string,array{messages:int,cost:float}> $buckets */
        $buckets = [];

        foreach ($points as $point) {
            if (! is_array($point)) {
                continue;
            }

            $start = $point['start'] ?? null;

            if (! is_numeric($start)) {
                continue;
            }

            $date = Carbon::createFromTimestampUTC((int) $start)->toDateString();
            $category = strtolower((string) ($point['pricing_category'] ?? 'utility'));
            $key = $date.'|'.$category;

            $buckets[$key] ??= ['messages' => 0, 'cost' => 0.0];
            $buckets[$key]['messages'] += (int) ($point['volume'] ?? 0);
            $buckets[$key]['cost'] += (float) ($point['cost'] ?? 0);
        }

        foreach ($buckets as $key => $bucket) {
            [$date, $category] = explode('|', $key, 2);
            $costs->applyMetaDaily($date, $category, $bucket['messages'], round($bucket['cost'], 6));
        }

        return count($buckets);
    }
}
