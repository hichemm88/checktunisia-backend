'use strict';

/*
 * MODULE PROVISOIRE — à retirer après homologation MI.
 * Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
 *
 * Service émetteur WhatsApp (whatsapp-web.js) — mono-émetteur → mono-destinataire.
 *
 * Rôle volontairement « bête » : il tient la session WhatsApp (LocalAuth, QR au
 * premier démarrage, événements) et envoie ce que le backend Laravel lui donne.
 * Toute la logique métier (file, retries, journal, destinataire, alertes) vit
 * dans Laravel. Ce worker ne décide de rien : il réclame, envoie, rend le verdict.
 *
 * Sécurité : la session IGNORE tous les messages entrants (aucun bot
 * conversationnel, surface d'attaque nulle) et ne s'authentifie que par secret
 * partagé auprès du backend.
 */

require('dotenv').config();

const fs = require('fs');
const path = require('path');
const express = require('express');
const qrcode = require('qrcode-terminal');
const QRCode = require('qrcode');
const axios = require('axios');
const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');

// ── Configuration (variables d'environnement uniquement) ─────────────────────
const API_BASE = (process.env.LARAVEL_API_BASE || 'http://localhost:8000/api/v1').replace(/\/$/, '');
const WORKER_SECRET = process.env.WHATSAPP_WORKER_SECRET || '';
const SESSION_PATH = process.env.WHATSAPP_SESSION_PATH || './.wwebjs_auth';
const HEALTH_PORT = parseInt(process.env.PORT || '3001', 10);
const IDLE_POLL_MS = parseInt(process.env.WHATSAPP_IDLE_POLL_MS || '5000', 10);
const ERROR_BACKOFF_MS = parseInt(process.env.WHATSAPP_ERROR_BACKOFF_MS || '15000', 10);

if (!WORKER_SECRET) {
  console.error('[whatsapp] FATAL: WHATSAPP_WORKER_SECRET manquant.');
  process.exit(1);
}

// Client HTTP vers le backend Laravel, authentifié par secret partagé.
const api = axios.create({
  baseURL: API_BASE,
  timeout: 20000,
  headers: { 'X-Whatsapp-Worker-Secret': WORKER_SECRET },
});

// ── État courant (exposé sur /health) ────────────────────────────────────────
const state = {
  session: 'initializing', // initializing | ready | disconnected | auth_failure
  reason: null,
  lastSendAt: null,
  lastPollAt: null,
  sentCount: 0,
  failedCount: 0,
  qrDataUrl: null, // QR courant en image (pour la page /qr, scannable au téléphone)
};

let ready = false;

// ── Session whatsapp-web.js (LocalAuth persistant) ───────────────────────────
/**
 * Supprime les fichiers de verrou Chromium périmés (SingletonLock/Cookie/Socket)
 * laissés par un conteneur coupé brutalement. Sans ça, au redémarrage (surtout
 * avec un volume persistant), Chromium refuse de démarrer et whatsapp-web.js
 * reste bloqué en « initializing » sans jamais émettre de QR.
 */
function cleanupStaleLocks(dir) {
  try {
    if (!fs.existsSync(dir)) return;
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        cleanupStaleLocks(full);
      } else if (/^Singleton(Lock|Cookie|Socket)$/.test(entry.name)) {
        try { fs.rmSync(full, { force: true }); console.log('[whatsapp] verrou périmé supprimé :', full); } catch (_) { /* ignore */ }
      }
    }
  } catch (err) {
    console.warn('[whatsapp] cleanup locks:', err.message);
  }
}

/** Vide le contenu du dossier de session (sans retirer le point de montage du volume). */
function wipeSession(dir) {
  try {
    if (!fs.existsSync(dir)) return;
    for (const entry of fs.readdirSync(dir)) {
      fs.rmSync(path.join(dir, entry), { recursive: true, force: true });
    }
    console.warn('[whatsapp] session vidée :', dir);
  } catch (err) {
    console.warn('[whatsapp] wipe session:', err.message);
  }
}

const client = new Client({
  authStrategy: new LocalAuth({ dataPath: SESSION_PATH }),
  puppeteer: {
    headless: true,
    // Arguments indispensables en conteneur (Railway/Docker).
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
    ],
    executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
  },
});

/** Rapporte l'état de session au backend (source de vérité pour /health/whatsapp + alertes). */
async function reportSession(status, reason = null) {
  state.session = status;
  state.reason = reason;
  try {
    await api.post('/internal/whatsapp/session', { status, reason });
  } catch (err) {
    console.warn('[whatsapp] report session failed:', err.message);
  }
}

// Watchdog anti-blocage : si ni QR ni connexion sous WATCHDOG_MS, la session
// sur le volume est probablement corrompue → on la vide et on redémarre le
// conteneur (Railway relance) pour repartir sur un QR frais.
const WATCHDOG_MS = parseInt(process.env.WHATSAPP_WATCHDOG_MS || '120000', 10);
const watchdog = setTimeout(() => {
  if (!ready && !state.qrDataUrl) {
    console.error('[whatsapp] bloqué au démarrage (ni QR ni connexion) — réinitialisation de la session.');
    wipeSession(SESSION_PATH);
    process.exit(1); // Railway relance le conteneur → session fraîche → nouveau QR
  }
}, WATCHDOG_MS);

client.on('qr', (qr) => {
  clearTimeout(watchdog);
  // Premier démarrage : scanner ce QR avec la SIM dédiée Qayed (jamais un numéro perso).
  // 1) dans les logs (ASCII) ; 2) en image sur la page /qr (scannable depuis un téléphone).
  console.log('[whatsapp] Scannez ce QR avec le téléphone émetteur Qayed (ou ouvrez /qr) :');
  qrcode.generate(qr, { small: true });
  QRCode.toDataURL(qr, { margin: 2, width: 320 })
    .then((url) => { state.qrDataUrl = url; })
    .catch((err) => console.warn('[whatsapp] QR image error:', err.message));
});

client.on('authenticated', () => console.log('[whatsapp] authentifié.'));

client.on('ready', () => {
  clearTimeout(watchdog);
  ready = true;
  state.qrDataUrl = null; // plus besoin du QR une fois connecté
  console.log('[whatsapp] session prête.');
  reportSession('ready');
});

client.on('auth_failure', (msg) => {
  ready = false;
  console.error('[whatsapp] auth_failure:', msg);
  reportSession('auth_failure', String(msg || 'auth_failure'));
});

client.on('disconnected', (reason) => {
  ready = false;
  console.warn('[whatsapp] déconnecté:', reason);
  reportSession('disconnected', String(reason || 'disconnected'));
});

// Sécurité : IGNORER tout message entrant. Aucun handler 'message' → aucune
// surface conversationnelle. (Listener explicite pour documenter l'intention.)
client.on('message', () => { /* ignoré volontairement — pas de bot */ });

// ── Boucle de travail (FIFO, un envoi à la fois) ─────────────────────────────
async function sendJob(job, minIntervalSeconds) {
  let media = null;

  if (job.has_photo && job.photo_url) {
    const res = await api.get(job.photo_url, { responseType: 'arraybuffer' });
    const base64 = Buffer.from(res.data).toString('base64');
    const mime = res.headers['content-type'] || 'image/jpeg';
    media = new MessageMedia(mime, base64, 'document');
  }

  // Photo + fiche en légende dans UN SEUL message → transférable en un geste.
  const sent = media
    ? await client.sendMessage(job.recipient, media, { caption: job.caption })
    : await client.sendMessage(job.recipient, job.caption);

  state.lastSendAt = new Date().toISOString();
  state.sentCount += 1;

  await api.post(`/internal/whatsapp/jobs/${job.id}/result`, {
    status: 'sent',
    message_id: sent?.id?._serialized || sent?.id?.id || null,
  });

  // Délai minimum anti-ban entre deux messages.
  await sleep(Math.max(minIntervalSeconds, 1) * 1000);
}

async function tick() {
  state.lastPollAt = new Date().toISOString();

  try {
    // Le backend décide si on peut avancer (activé, non en pause, session prête).
    const control = (await api.get('/internal/whatsapp/control')).data.data;
    if (!ready || !control.enabled || control.paused) {
      return IDLE_POLL_MS;
    }

    const { job } = (await api.get('/internal/whatsapp/next')).data.data;
    if (!job) {
      return IDLE_POLL_MS;
    }

    try {
      await sendJob(job, control.min_interval_seconds);
    } catch (err) {
      state.failedCount += 1;
      console.warn(`[whatsapp] envoi ${job.id} échoué:`, err.message);
      await api.post(`/internal/whatsapp/jobs/${job.id}/result`, {
        status: 'failed',
        error: String(err.message || err).slice(0, 500),
      }).catch(() => {});
    }

    // Enchaîner immédiatement sur le job suivant (l'intervalle a déjà été respecté).
    return 0;
  } catch (err) {
    console.warn('[whatsapp] tick error:', err.message);
    return ERROR_BACKOFF_MS;
  }
}

async function loop() {
  // eslint-disable-next-line no-constant-condition
  while (true) {
    const wait = await tick();
    if (wait > 0) await sleep(wait);
  }
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

// ── Endpoint santé local du service Node ─────────────────────────────────────
const app = express();
app.get('/health', (_req, res) => {
  const { qrDataUrl, ...safe } = state;
  res.json({ ok: ready, has_qr: !!qrDataUrl, ...safe });
});

// Page de connexion : affiche le QR en image (scannable au téléphone) ou l'état.
// Se rafraîchit toute seule jusqu'à ce que la session soit prête.
app.get('/qr', (_req, res) => {
  const body = ready
    ? '<h2 style="color:#1a7f4b">✅ Session WhatsApp connectée</h2><p>Rien à scanner. Tu peux fermer cette page.</p>'
    : state.qrDataUrl
      ? `<h2>Scanne ce code avec le téléphone de la SIM Qayed</h2>
         <p>WhatsApp → Appareils connectés → Connecter un appareil</p>
         <img src="${state.qrDataUrl}" alt="QR WhatsApp" style="width:320px;height:320px" />`
      : `<h2>En attente du QR…</h2><p>Le service démarre. Cette page se rafraîchit automatiquement.</p>`;

  res.set('Content-Type', 'text/html; charset=utf-8').send(
    `<!doctype html><html lang="fr"><head><meta charset="utf-8">
     <meta name="viewport" content="width=device-width,initial-scale=1">
     <meta http-equiv="refresh" content="10">
     <title>Connexion WhatsApp Qayed</title></head>
     <body style="font-family:system-ui,sans-serif;text-align:center;padding:24px;max-width:420px;margin:0 auto">
     ${body}</body></html>`,
  );
});

app.listen(HEALTH_PORT, () => console.log(`[whatsapp] health sur :${HEALTH_PORT}/health, QR sur :${HEALTH_PORT}/qr`));

// ── Démarrage ────────────────────────────────────────────────────────────────
process.on('unhandledRejection', (err) => console.warn('[whatsapp] unhandledRejection:', err?.message || err));

reportSession('initializing');
cleanupStaleLocks(SESSION_PATH); // retire les verrous Chromium périmés avant de démarrer
client.initialize();
loop();
