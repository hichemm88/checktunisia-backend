<?php

namespace App\Services\Whatsapp;

/**
 * Le coupe-circuit global est tiré : rien ne part vers Meta, réponses comprises.
 *
 * ── Pourquoi ce contrôle existe sur ce chemin-là ────────────────────────
 *
 * `WHATSAPP_SENDING_ENABLED=false` est un geste d'EXPLOITATION : on le baisse
 * quand Meta signale la qualité du numéro émetteur, ou pendant un incident. Il
 * a déjà coûté un numéro à ce produit ; c'est la commande qui arrête tout,
 * immédiatement, sans redéploiement.
 *
 * La boîte de réception ouvrait un chemin d'émission de plus. Sans ce
 * contrôle, un administrateur aurait continué à écrire à des postes de police
 * pendant que le coupe-circuit était baissé — c'est-à-dire exactement pendant
 * la période où l'on cherche à ne plus rien envoyer.
 *
 * ── Ce qui N'EST PAS vérifié ici, et pourquoi ───────────────────────────
 *
 * Les autres garde-fous (`WhatsappSendingGuard`) sont taillés pour les
 * FICHES : approbation du modèle, arriéré, plafond quotidien, bascule armée.
 * Les opposer à une réponse reviendrait à refuser de répondre à un agent parce
 * qu'un modèle de fiche attend sa validation — un problème qui n'est pas le
 * sien. Même raisonnement que pour les codes de connexion
 * (`WhatsappOtpSender`) : deux flux, deux budgets, un seul interrupteur
 * commun.
 */
class WhatsappSendingDisabled extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Coupe-circuit actif (WHATSAPP_SENDING_ENABLED=false) : aucun message ne part vers WhatsApp.'
        );
    }
}
