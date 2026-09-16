<?php

/**
 * Vérification de la signature d'un webhook Qayed (PHP pur, sans framework),
 * miroir exact du schéma utilisé côté serveur dans
 * App\Services\PartnerApi\PartnerWebhookOutboxService::sign() :
 *
 *   hash_hmac('sha256', "{timestamp}.{corps_brut}", $secret)
 *
 * En-tête reçu : `Qayed-Signature: t={unix_timestamp},v1={hex}`
 *
 * IMPORTANT : calculez toujours le HMAC sur le corps brut de la requête tel
 * que reçu (php://input), jamais sur un tableau re-encodé via json_encode()
 * après json_decode() — le ré-encodage peut changer l'ordre des clés ou
 * l'échappement et invalider la signature.
 */

const DEFAULT_TOLERANCE_SECONDS = 300; // partner_api.webhooks.signature_tolerance_seconds

/**
 * @param string $rawBody Corps brut de la requête (php://input), NON décodé.
 * @param string|null $signatureHeader Valeur brute de l'en-tête Qayed-Signature, ex. "t=1758000000,v1=abcdef...".
 * @param string $secret Secret de l'endpoint webhook (visible une seule fois à sa création dans Qayed).
 * @param int $toleranceSeconds Fenêtre de tolérance anti-rejeu, en secondes (300 par défaut côté serveur).
 */
function verifyQayedSignature(
    string $rawBody,
    ?string $signatureHeader,
    string $secret,
    int $toleranceSeconds = DEFAULT_TOLERANCE_SECONDS
): bool {
    if ($signatureHeader === null || $signatureHeader === '') {
        return false;
    }

    $parts = [];
    foreach (explode(',', $signatureHeader) as $pair) {
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
        if ($key !== null && $value !== null) {
            $parts[$key] = $value;
        }
    }

    $timestamp = isset($parts['t']) ? (int) $parts['t'] : 0;
    $signature = $parts['v1'] ?? null;

    if ($timestamp === 0 || $signature === null) {
        return false;
    }

    // 1. Tolérance anti-rejeu : rejeter tout événement trop vieux (ou dont
    // l'horloge serait dans le futur au-delà de la tolérance).
    $now = time();
    if (abs($now - $timestamp) > $toleranceSeconds) {
        return false;
    }

    // 2. Recalcul du HMAC sur "{timestamp}.{corps_brut}" avec le secret de
    // l'endpoint, comparaison à temps constant via hash_equals().
    $signedPayload = $timestamp.'.'.$rawBody;
    $expected = hash_hmac('sha256', $signedPayload, $secret);

    return hash_equals($expected, $signature);
}

/*
 * Exemple d'utilisation dans un endpoint PHP simple (sans framework) :
 *
 *   <?php
 *   require __DIR__.'/verify-webhook.php';
 *
 *   $rawBody = file_get_contents('php://input'); // corps brut, jamais $_POST
 *   $signatureHeader = $_SERVER['HTTP_QAYED_SIGNATURE'] ?? null;
 *   $eventType = $_SERVER['HTTP_QAYED_EVENT'] ?? null;
 *
 *   $secret = getenv('QAYED_WEBHOOK_SECRET');
 *
 *   if (! verifyQayedSignature($rawBody, $signatureHeader, $secret)) {
 *       http_response_code(401);
 *       exit('invalid signature');
 *   }
 *
 *   $event = json_decode($rawBody, true);
 *
 *   switch ($eventType) {
 *       case 'fiche.submitted':
 *           // $event = ['fiche_id', 'session_id', 'booking_ref', 'establishment_id',
 *           //           'guest_count', 'submitted_at', 'metadata']
 *           break;
 *       case 'fiche.failed':
 *           // $event = ['session_id', 'booking_ref', 'error_code']
 *           break;
 *       case 'session.expired':
 *           // $event = ['session_id', 'booking_ref']
 *           break;
 *   }
 *
 *   http_response_code(200);
 *   echo 'ok';
 *
 * Dans un contrôleur Laravel côté partenaire (si le partenaire est lui-même
 * en Laravel), utilisez $request->getContent() pour obtenir le corps brut —
 * jamais $request->input() ou $request->json(), qui passent par le tableau
 * déjà décodé.
 */
