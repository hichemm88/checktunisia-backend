<?php

namespace App\Services\PartnerApi;

/**
 * Les données de test ont changé entre l'aperçu confirmé et l'exécution :
 * rien n'a été supprimé (la transaction est annulée).
 */
class PurgeConfirmationMismatch extends \RuntimeException
{
    /** @param  array<string,int>  $currentCounts */
    public function __construct(public readonly array $currentCounts)
    {
        parent::__construct('Purge confirmation mismatch.');
    }
}
