<?php

namespace App\Services\Payment;

use App\Models\Payment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingService;

/**
 * Constatation d'un paiement en ligne — le point de passage UNIQUE.
 *
 * Deux chemins mènent ici et peuvent arriver en même temps : le retour du
 * client sur la page de succès, et le webhook du prestataire. Konnect appelle
 * le second même quand le client ferme son navigateur avant de revenir — c'est
 * précisément ce que Flouci ne savait pas faire, et c'est ce qui évite qu'une
 * facture réglée reste impayée, parte en relance, puis suspende un client à
 * jour.
 *
 * D'où la règle : ce code existe en UN exemplaire. Une copie dans le webhook
 * dériverait de l'original au premier correctif, et la divergence ne se verrait
 * que le jour d'un double encaissement.
 */
class PaymentSettlement
{
    public function __construct(private readonly BillingService $billing) {}

    /**
     * Applique le verdict de la passerelle au paiement, et n'enchaîne la suite
     * qu'une seule fois.
     *
     * @param  array{success: bool, pending?: bool, status?: string, raw?: array<string, mixed>} $result
     * @return string  le statut final du paiement
     */
    public function apply(Payment $payment, array $result, ?User $actor = null): string
    {
        // Le prestataire n'a pas tranché : on ne tranche pas non plus. Marquer
        // « échoué » ici scellerait le paiement, et le webhook qui arrive juste
        // après le trouverait clos — un règlement parti mais jamais constaté.
        // Il reste en attente jusqu'à son expiration, qui est un vrai verdict.
        if (! ($result['success'] ?? false) && ($result['pending'] ?? false)) {
            return $payment->status;
        }

        if (! ($result['success'] ?? false)) {
            $payment->update([
                'status'            => 'failed',
                'provider_response' => $result['raw'] ?? [],
            ]);

            return 'failed';
        }

        // Transition conditionnelle : seule la requête qui fait réellement
        // passer le paiement de « pending » à « completed » poursuit.
        //
        // Un verrou posé plus haut aurait été relâché avant l'appel au
        // prestataire — le tenir pendant un aller-retour réseau serait pire. Ce
        // compare-and-set, lui, est atomique : le retour navigateur et le
        // webhook ne peuvent pas gagner tous les deux.
        $claimed = Payment::whereKey($payment->id)
            ->where('status', 'pending')
            ->update([
                'status'            => 'completed',
                'completed_at'      => now(),
                'provider_response' => $result['raw'] ?? [],
            ]);

        if ($claimed === 0) {
            // L'autre chemin a déjà tout enchaîné : on rend son résultat.
            return (string) $payment->fresh()?->status;
        }

        $payment->refresh();

        $payment->invoice()->update([
            'status'            => 'paid',
            'paid_at'           => now(),
            'payment_method'    => $payment->provider,
            'payment_reference' => $payment->provider_payment_id,
        ]);

        $invoice = $payment->invoice()->first();

        AuditLogger::log('payment.completed', $invoice, actor: $actor);

        // Réactivation/prolongation automatique de l'abonnement + email
        // « Paiement reçu » — même circuit que le virement validé. Second
        // verrou, en base celui-là (invoices.metadata.payment_settled_at).
        $this->billing->handleInvoicePaid($invoice, $actor?->id);

        return 'completed';
    }
}
