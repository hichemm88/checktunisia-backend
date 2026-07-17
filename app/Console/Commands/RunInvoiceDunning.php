<?php

namespace App\Console\Commands;

use App\Services\Billing\BillingService;
use Illuminate\Console\Command;

class RunInvoiceDunning extends Command
{
    protected $signature   = 'invoices:dunning';
    protected $description = 'Relances impayé J+3/7/14 et suspension automatique à J+21 (chantier A2)';

    public function handle(BillingService $billing): void
    {
        $stats = $billing->runDunning();

        $this->info("{$stats['overdue']} facture(s) passée(s) en retard, {$stats['reminded']} relance(s) envoyée(s), {$stats['suspended']} abonnement(s) suspendu(s).");
    }
}
