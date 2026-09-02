<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappConversation;

/**
 * Refus AVANT l'appel à Meta : la fenêtre de service de 24 h est fermée.
 *
 * Ce n'est pas une panne, c'est une règle de la plateforme — hors des 24 h qui
 * suivent le dernier message entrant, seul un modèle approuvé passe, et nos
 * deux modèles (fiche, code de connexion) ne sont pas des réponses.
 *
 * Le refus est prononcé ici plutôt que laissé à Meta (erreur 131047) pour deux
 * raisons : l'écran peut le dire à l'avance, en désactivant le champ de saisie
 * plutôt qu'en accueillant un message par un échec ; et un appel qu'on sait
 * refusé n'a pas à être fait.
 */
class ServiceWindowClosed extends \RuntimeException
{
    public function __construct(public readonly WhatsappConversation $conversation)
    {
        parent::__construct(
            'La fenêtre de réponse de 24 h est fermée : seule une autorité qui vient '
            .'d\'écrire peut recevoir un message libre.'
        );
    }
}
