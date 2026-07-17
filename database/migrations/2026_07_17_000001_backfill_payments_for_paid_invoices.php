<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Une facture payée doit toujours avoir une trace dans l'historique des
 * paiements. Les factures marquées « paid » manuellement avant l'ajout de
 * recordPaymentForPaidInvoice (ex. INV-2026-0001) n'en ont aucune : on les
 * backfill ici avec un paiement « completed » reconstitué depuis la facture.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('invoices')
            ->where('status', 'paid')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('payments')
                    ->whereColumn('payments.invoice_id', 'invoices.id')
                    ->where('payments.status', 'completed');
            })
            ->get();

        foreach ($orphans as $invoice) {
            // Un virement déclaré encore « pending » sur cette facture ? On le
            // complète plutôt que de créer un doublon.
            $pending = DB::table('payments')
                ->where('invoice_id', $invoice->id)
                ->where('status', 'pending')
                ->orderByDesc('created_at')
                ->first();

            if ($pending) {
                DB::table('payments')->where('id', $pending->id)->update([
                    'status'       => 'completed',
                    'completed_at' => $invoice->paid_at ?? now(),
                    'updated_at'   => now(),
                ]);
                continue;
            }

            DB::table('payments')->insert([
                'id'                 => (string) Str::uuid(),
                'invoice_id'         => $invoice->id,
                'hotel_id'           => $invoice->hotel_id,
                'provider'           => $invoice->payment_method ?: 'virement',
                'declared_reference' => $invoice->payment_reference,
                'status'             => 'completed',
                'amount'             => $invoice->total_amount,
                'currency'           => $invoice->currency,
                'completed_at'       => $invoice->paid_at ?? now(),
                'provider_response'  => json_encode(['source' => 'backfill_migration']),
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('payments')
            ->where('provider_response', json_encode(['source' => 'backfill_migration']))
            ->delete();
    }
};
