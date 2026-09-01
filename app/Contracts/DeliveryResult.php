<?php

namespace App\Contracts;

/**
 * Résultat d'une tentative de transmission.
 *
 * Immuable et sans dépendance au canal : c'est ce qui permettra de comparer
 * deux canaux sur le même job (mode ombre) avant de basculer le trafic.
 */
final readonly class DeliveryResult
{
    private function __construct(
        public bool $success,
        public ?string $messageId,
        public ?string $error,
        /** Le canal juge-t-il l'erreur réessayable ? Un numéro invalide ne l'est pas ; un 503 l'est. */
        public bool $retryable,
        /**
         * Code d'erreur natif du canal (code Meta pour la Cloud API), conservé
         * tel quel. Un libellé se traduit et se reformule ; le code est ce qui
         * permet de recouper une panne avec la documentation du fournisseur —
         * et il est aussi ce que renvoie le webhook, donc le seul point commun
         * entre un échec à l'envoi et un échec à la livraison.
         */
        public ?string $errorCode = null,
        /**
         * L'erreur arrête-t-elle tout le canal (jeton révoqué, modèle
         * suspendu) plutôt qu'une seule fiche ? Ces cas-là exigent une alerte,
         * pas une ligne de journal.
         */
        public bool $critical = false,
    ) {}

    public static function sent(?string $messageId = null): self
    {
        return new self(true, $messageId, null, false);
    }

    /** Échec temporaire : réseau, 5xx, limitation de débit. À retenter. */
    public static function failedTemporarily(string $error, ?string $errorCode = null): self
    {
        return new self(false, null, $error, true, $errorCode);
    }

    /**
     * Échec définitif : numéro invalide, message refusé, compte suspendu.
     * Retenter ne ferait que consommer des tentatives et retarder l'alerte.
     */
    public static function failedPermanently(string $error, ?string $errorCode = null, bool $critical = false): self
    {
        return new self(false, null, $error, false, $errorCode, $critical);
    }
}
