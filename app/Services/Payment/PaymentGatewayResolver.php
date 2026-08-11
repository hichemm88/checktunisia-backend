<?php

namespace App\Services\Payment;

use App\Models\Payment;
use App\Models\PlatformSetting;

/**
 * Choisit la passerelle à employer.
 *
 * La distinction entre les deux méthodes n'est pas cosmétique. Un NOUVEAU
 * paiement part chez le prestataire actuellement ouvert ; un paiement DÉJÀ
 * ENGAGÉ ne peut être constaté que chez celui qui l'a créé. Résoudre
 * globalement casserait la vérification de tous les paiements Flouci en cours
 * ou passés au moment de la bascule — donc des factures réglées qui resteraient
 * impayées, partiraient en relance, et suspendraient des clients à jour.
 */
class PaymentGatewayResolver
{
    /** Passerelle pour un paiement à créer, ou null si le canal est fermé. */
    public function forNewPayment(): ?PaymentGateway
    {
        return $this->byProvider(PlatformSetting::get()->onlineProvider());
    }

    /** Passerelle capable de constater CE paiement-là. */
    public function forPayment(Payment $payment): ?PaymentGateway
    {
        return $this->byProvider($payment->provider);
    }

    public function byProvider(?string $provider): ?PaymentGateway
    {
        return match ($provider) {
            'konnect' => app(KonnectService::class),
            'flouci'  => app(FlouciService::class),
            default   => null,
        };
    }
}
