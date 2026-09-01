<?php

namespace App\Services\Delivery;

/**
 * Échec passager pendant la préparation d'un envoi (téléversement de la pièce
 * jointe, notamment).
 *
 * Distinguée d'une exception quelconque parce que la conséquence n'est pas la
 * même : la fiche est intacte et repartira au prochain tour, alors qu'une
 * erreur imprévue doit remonter. Sans ce type, un /media momentanément
 * indisponible aurait été indiscernable d'un bug — et traité comme tel.
 */
class TransientDeliveryFailure extends \RuntimeException {}
