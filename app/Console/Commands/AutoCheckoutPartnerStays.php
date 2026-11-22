<?php

namespace App\Console\Commands;

use App\Services\PartnerApi\PartnerStayService;
use Illuminate\Console\Command;

/**
 * Départ automatique des séjours issus d'une fiche API (v1.1) — voir
 * PartnerStayService::autoCheckoutDue. Planifié tôt le matin (heure de
 * Tunis) pour que les chambres du jour soient libres avant les arrivées.
 */
class AutoCheckoutPartnerStays extends Command
{
    protected $signature = 'partner-stays:auto-checkout';

    protected $description = "Clôt les séjours des fiches créées par l'API dont la date de départ prévue est atteinte.";

    public function handle(PartnerStayService $stays): int
    {
        $this->info($stays->autoCheckoutDue().' séjour(s) clos.');

        return self::SUCCESS;
    }
}
