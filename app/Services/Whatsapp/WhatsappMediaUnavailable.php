<?php

namespace App\Services\Whatsapp;

/**
 * Meta ne rend plus ce média — le cas normal étant qu'il a dépassé sa
 * fenêtre de conservation (~30 jours), pas une panne de notre côté. Distingué
 * d'une erreur générique pour que l'écran affiche « média expiré » plutôt
 * qu'un message d'échec technique qui laisserait croire à un bug à corriger.
 */
class WhatsappMediaUnavailable extends \RuntimeException
{
}
