<?php

namespace App\Services\Payment;

/**
 * Contrat commun aux passerelles de paiement en ligne.
 *
 * Il ne décrit rien de plus que ce que `PaymentController` consomme déjà, à
 * dessein : créer une session de paiement, puis constater son sort côté
 * serveur. Tout le reste — facture, encaissement, prolongation d'abonnement —
 * appartient au domaine et ne connaît aucun prestataire.
 *
 * Les deux implémentations rendent des tableaux de forme identique : c'est ce
 * qui permet à un paiement Flouci historique de continuer à se vérifier après
 * la bascule vers Konnect.
 */
interface PaymentGateway
{
    /**
     * Crée une session de paiement hébergée.
     *
     * @param  int    $amountMillimes  Montant en millimes TND (1,500 TND = 1500)
     * @param  string $trackingId      Notre référence interne (UUID)
     * @param  array<string, mixed> $context  Coordonnées client et n° de facture,
     *                                        pour préremplir la page du prestataire.
     * @return array{payment_id: string, payment_url: string}
     *
     * @throws \RuntimeException si la passerelle est indisponible ou refuse
     */
    public function createPayment(int $amountMillimes, string $trackingId, array $context = []): array;

    /**
     * Constate le sort d'un paiement, côté serveur.
     *
     * Trois issues, pas deux. `pending` à true signifie « le prestataire n'a
     * pas encore tranché » : c'est différent d'un échec, et les confondre a
     * une conséquence concrète. Un paiement marqué « échoué » chez nous ferait
     * court-circuiter le webhook qui arrive juste après — le règlement ne
     * serait alors JAMAIS constaté, alors que l'argent est bien parti.
     *
     * @return array{success: bool, pending: bool, status: string, payment_id: string, raw: array<string, mixed>}
     *
     * @throws \RuntimeException si la passerelle est indisponible
     */
    public function verifyPayment(string $providerPaymentId): array;
}
