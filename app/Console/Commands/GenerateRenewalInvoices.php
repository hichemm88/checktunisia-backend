<?php

namespace App\Console\Commands;

use App\Services\Billing\BillingService;
use Illuminate\Console\Command;

class GenerateRenewalInvoices extends Command
{
    protected $signature   = 'invoices:generate-due';
    protected $description = 'Génère les factures de renouvellement des abonnements en auto-renouvellement arrivant à échéance (chantier A2)';

    public function handle(BillingService $billing): void
    {
        $created = $billing->generateDueRenewalInvoices();

        foreach ($created as $invoice) {
            $this->line("Facture {$invoice->invoice_number} générée ({$invoice->total_amount} {$invoice->currency}).");
        }

        $this->info(count($created).' facture(s) de renouvellement générée(s).');
    }
}
