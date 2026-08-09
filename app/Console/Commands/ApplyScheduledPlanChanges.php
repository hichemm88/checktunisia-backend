<?php

namespace App\Console\Commands;

use App\Services\Subscription\PlanChangeService;
use Illuminate\Console\Command;

/**
 * Applique les downgrades arrivés au terme de la période payée.
 *
 * Un downgrade n'est jamais rétroactif : il attend ici que la période que le
 * client a réglée soit écoulée. Idempotente — chaque changement est relu
 * sous verrou et ignoré s'il a déjà été appliqué, si bien qu'une double
 * exécution du planificateur ne rebascule aucun plan.
 */
class ApplyScheduledPlanChanges extends Command
{
    protected $signature = 'subscriptions:apply-plan-changes';

    protected $description = 'Applique les changements de plan programmés (downgrades) dont la date d\'effet est atteinte';

    public function handle(PlanChangeService $service): int
    {
        $applied = $service->applyDue();

        $this->info("{$applied} changement(s) de plan appliqué(s).");

        return self::SUCCESS;
    }
}
