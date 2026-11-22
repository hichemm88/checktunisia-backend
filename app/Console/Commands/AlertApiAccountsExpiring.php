<?php

namespace App\Console\Commands;

use App\Services\Subscription\ApiAccessGuard;
use Illuminate\Console\Command;

/**
 * Alerte les administrateurs de la plateforme 15 jours avant l'échéance d'un
 * abonnement dont l'accès API partenaire est en service — comptes internes
 * compris. Toute la logique (périmètre, idempotence) vit dans ApiAccessGuard.
 */
class AlertApiAccountsExpiring extends Command
{
    protected $signature = 'subscriptions:alert-api-expiring';

    protected $description = "Alerte admin J-15 avant l'échéance des abonnements à accès API en service (comptes internes compris)";

    public function handle(): int
    {
        $sent = ApiAccessGuard::alertUpcomingExpiries();

        $this->info("{$sent} alerte(s) d'échéance envoyée(s) aux administrateurs.");

        return self::SUCCESS;
    }
}
