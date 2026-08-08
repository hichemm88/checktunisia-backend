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
    ) {}

    public static function sent(?string $messageId = null): self
    {
        return new self(true, $messageId, null, false);
    }

    /** Échec temporaire : réseau, 5xx, limitation de débit. À retenter. */
    public static function failedTemporarily(string $error): self
    {
        return new self(false, null, $error, true);
    }

    /**
     * Échec définitif : numéro invalide, message refusé, compte suspendu.
     * Retenter ne ferait que consommer des tentatives et retarder l'alerte.
     */
    public static function failedPermanently(string $error): self
    {
        return new self(false, null, $error, false);
    }
}
