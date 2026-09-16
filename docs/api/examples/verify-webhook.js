/**
 * Vérification de la signature d'un webhook Qayed (Node.js, sans dépendance
 * externe — uniquement le module natif `crypto`).
 *
 * En-tête reçu : `Qayed-Signature: t={unix_timestamp},v1={hex}`
 * où `hex = HMAC-SHA256("{timestamp}.{corps_brut}", secret_endpoint)` en hexadécimal.
 *
 * IMPORTANT : le HMAC est calculé sur les OCTETS BRUTS du corps de la requête,
 * exactement comme envoyés par Qayed — jamais sur le résultat de
 * JSON.stringify(JSON.parse(body)), qui peut réordonner des clés ou changer
 * l'espacement et casser la vérification. Utilisez toujours le corps brut
 * (Buffer ou string non modifiée).
 */

const crypto = require('crypto');

const DEFAULT_TOLERANCE_SECONDS = 300; // partner_api.webhooks.signature_tolerance_seconds

/**
 * @param {string} rawBody Corps brut de la requête (string ou Buffer), NON parsé en JSON.
 * @param {string} signatureHeader Valeur brute de l'en-tête Qayed-Signature, ex. "t=1758000000,v1=abcdef...".
 * @param {string} secret Secret de l'endpoint webhook (visible une seule fois à sa création dans Qayed).
 * @param {number} [toleranceSeconds] Fenêtre de tolérance anti-rejeu, en secondes (300 par défaut côté serveur).
 * @returns {boolean} true si la signature est valide et dans la fenêtre de tolérance.
 */
function verifyQayedSignature(rawBody, signatureHeader, secret, toleranceSeconds = DEFAULT_TOLERANCE_SECONDS) {
  if (!signatureHeader) {
    return false;
  }

  const parts = Object.fromEntries(
    signatureHeader.split(',').map((pair) => {
      const [key, value] = pair.split('=');
      return [key, value];
    }),
  );

  const timestamp = parseInt(parts.t, 10);
  const signature = parts.v1;

  if (!timestamp || !signature) {
    return false;
  }

  // 1. Tolérance anti-rejeu : rejeter tout événement trop vieux (ou dont
  // l'horloge serait dans le futur au-delà de la tolérance).
  const now = Math.floor(Date.now() / 1000);
  if (Math.abs(now - timestamp) > toleranceSeconds) {
    return false;
  }

  // 2. Recalcul du HMAC sur "{timestamp}.{corps_brut}" avec le secret de
  // l'endpoint, comparaison à temps constant pour éviter les attaques par
  // timing.
  const signedPayload = `${timestamp}.${rawBody}`;
  const expected = crypto.createHmac('sha256', secret).update(signedPayload, 'utf8').digest('hex');

  const expectedBuffer = Buffer.from(expected, 'hex');
  const receivedBuffer = Buffer.from(signature, 'hex');

  if (expectedBuffer.length !== receivedBuffer.length) {
    return false;
  }

  return crypto.timingSafeEqual(expectedBuffer, receivedBuffer);
}

module.exports = { verifyQayedSignature };

/*
 * Exemple d'utilisation avec Express — le point piégeux est de récupérer le
 * corps BRUT (pas le JSON déjà parsé par express.json()) pour ce endpoint
 * précis :
 *
 *   const express = require('express');
 *   const { verifyQayedSignature } = require('./verify-webhook');
 *
 *   const app = express();
 *
 *   app.post(
 *     '/webhooks/qayed',
 *     express.raw({ type: 'application/json' }), // <- corps brut en Buffer, PAS express.json()
 *     (req, res) => {
 *       const rawBody = req.body; // Buffer
 *       const signatureHeader = req.get('Qayed-Signature');
 *       const eventType = req.get('Qayed-Event');
 *
 *       const isValid = verifyQayedSignature(
 *         rawBody,
 *         signatureHeader,
 *         process.env.QAYED_WEBHOOK_SECRET,
 *       );
 *
 *       if (!isValid) {
 *         return res.status(401).send('invalid signature');
 *       }
 *
 *       const event = JSON.parse(rawBody.toString('utf8'));
 *
 *       switch (eventType) {
 *         case 'fiche.submitted':
 *           // event = { fiche_id, session_id, booking_ref, establishment_id,
 *           //           guest_count, submitted_at, metadata }
 *           break;
 *         case 'fiche.failed':
 *           // event = { session_id, booking_ref, error_code }
 *           break;
 *         case 'session.expired':
 *           // event = { session_id, booking_ref }
 *           break;
 *       }
 *
 *       res.status(200).send('ok');
 *     },
 *   );
 *
 * Si express.json() est déjà monté globalement sur votre app, montez
 * express.raw() spécifiquement sur cette route AVANT tout middleware qui
 * parserait le corps, sinon le corps brut ne sera plus disponible.
 */
