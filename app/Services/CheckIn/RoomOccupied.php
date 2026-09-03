<?php

namespace App\Services\CheckIn;

/**
 * La chambre porte déjà un séjour ouvert.
 *
 * Distincte de `DomainException` — qui signale un statut incompatible et se
 * traduit en 409 — parce que la cause et le geste correctif ne sont pas les
 * mêmes : ici, la demande est cohérente, c'est l'état de la chambre qui s'y
 * oppose. La réception doit déplacer le client suivant, pas réessayer.
 */
class RoomOccupied extends \RuntimeException
{
    public function __construct(string $message = 'Cette chambre porte déjà un séjour en cours.')
    {
        parent::__construct($message);
    }
}
