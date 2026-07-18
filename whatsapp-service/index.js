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
    // Chromium en conteneur (Railway) répond lentement sous charge : sans ce
    // timeout élargi, puppeteer coupe à 30 s → « Runtime.callFunctionOn timed out ».
    protocolTimeout: 300000,
    // Arguments indispensables en conteneur (Railway/Docker).
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      // NB : les bridages mémoire de l'époque « plan 1 Go » (max-old-space-size,
      // renderer-process-limit, isolation désactivée, imagesEnabled=false) ont
      // été retirés le 2026-07-17 après le passage à 8 Go. Un tas plafonné à
      // 460 Mo pouvait étrangler le traitement d'une photo de passeport, et
      // couper les images fausse le diagnostic des envois de médias.
      '--disable-extensions',
    ],
    executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
  },
  // Version web de WhatsApp épinglée (wppconnect) : évite qu'une version
  // poussée par Meta et incompatible avec whatsapp-web.js casse la session.
  // ⚠️ Doit être un fichier EXACT du repo wa-version (l'alias 2.3000.x.html
  // n'existe pas → 404 → fallback silencieux sur la version live). Les versions
  // expirent ~2 mois après publication (champ expire de versions.json).
  // Les builds à partir du ~14/07/2026 renomment id._serialized en id.$1 et
  // cassent sendMessage sur toutes les versions de whatsapp-web.js (issues
  // wwebjs #201829/#201832/#201840) : on reste sur le build du 10/07, qui
  // expire le 2026-09-10 — à rafraîchir avant (ou dès que la lib publie le fix).
  webVersionCache: {
    type: 'remote',
    remotePath: 'https://raw.githubusercontent.com/wppconnect-team/wa-version/main/html/2.3000.1042991638-alpha.html',
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

// Tampon circulaire des derniers messages/erreurs de la page WhatsApp Web —
// exposé sur /debug pour diagnostiquer les blocages d'envoi de médias.
const pageLog = [];
function pushPageLog(kind, text) {
  pageLog.push(`${new Date().toISOString()} [${kind}] ${String(text).slice(0, 300)}`);
  if (pageLog.length > 40) pageLog.shift();
}
let pageHooksAttached = false;
function attachPageDiagnostics() {
  if (pageHooksAttached || !client.pupPage) return;
  pageHooksAttached = true;
  client.pupPage.on('pageerror', (err) => pushPageLog('pageerror', err.message));
  client.pupPage.on('error', (err) => pushPageLog('error', err.message));
  client.pupPage.on('console', (msg) => {
    const t = msg.type();
    if (t === 'error' || t === 'warning') pushPageLog('console:' + t, msg.text());
  });
}

client.on('ready', () => {
  clearTimeout(watchdog);
  ready = true;
  state.qrDataUrl = null; // plus besoin du QR une fois connecté
  console.log('[whatsapp] session prête.');
  reportSession('ready');
  attachPageDiagnostics();
  startPageLivenessWatchdog();
});

/**
 * Watchdog de vivacité de la page : quand le renderer WhatsApp Web se fait
 * OOM-kill (conteneur plafonné à 1 Go), la session reste « ready » mais la page
 * ne répond plus à AUCUN evaluate — sans erreur ni protocolTimeout. On sonde
 * la page toutes les 60 s ; après 3 sondes muettes consécutives, on redémarre
 * le conteneur (session LocalAuth conservée sur le volume).
 */
let livenessTimer = null;
function startPageLivenessWatchdog() {
  if (livenessTimer) return;
  let misses = 0;
  let reloads = 0;
  livenessTimer = setInterval(async () => {
    try {
      await withTimeout(client.pupPage.evaluate('1'), 15000, 'sonde de vivacité');
      misses = 0;
      reloads = 0;
    } catch (err) {
      misses += 1;
      console.warn(`[whatsapp] page muette (${misses}/3):`, err.message);
      if (misses < 3) return;

      // 1er niveau : recharger la page — un reload crée un renderer NEUF sans
      // consommer le budget de redémarrages Railway (10 crashs max avant arrêt
      // définitif du service). La session LocalAuth du profil est réutilisée.
      if (reloads < 2) {
        reloads += 1;
        misses = 0;
        console.warn(`[whatsapp] rechargement de la page WhatsApp (tentative ${reloads}/2)…`);
        try {
          await withTimeout(client.pupPage.reload({ waitUntil: 'domcontentloaded' }), 60000, 'reload');
          console.log('[whatsapp] page rechargée.');
        } catch (reloadErr) {
          console.warn('[whatsapp] reload échoué:', reloadErr.message);
        }
        return;
      }

      // 2e niveau : le reload ne suffit pas → redémarrage complet du conteneur.
      console.error('[whatsapp] page WhatsApp morte malgré les reloads (OOM renderer probable) — redémarrage du conteneur.');
      reportSession('disconnected', 'page morte (OOM renderer probable) — auto-restart').finally(() => process.exit(1));
    }
  }, 60000);
}

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

/**
 * Timeout dur autour d'une promesse. Nécessaire car certains hangs de
 * Chromium/WhatsApp Web ne déclenchent ni erreur ni le protocolTimeout de
 * puppeteer — sans ceci, la boucle d'envoi reste suspendue indéfiniment.
 * ⚠️ La promesse sous-jacente n'est pas annulée : un envoi « timeouté » peut
 * malgré tout aboutir plus tard (doublon possible côté destinataire, préférable
 * à une file bloquée).
 */
const SEND_TIMEOUT_MS = parseInt(process.env.WHATSAPP_SEND_TIMEOUT_MS || '120000', 10);
function withTimeout(promise, ms, label) {
  let timer;
  const timeout = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new Error(`${label} sans réponse après ${Math.round(ms / 1000)}s`)), ms);
  });
  return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}

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

// Auto-réparation : au-delà de N échecs d'envoi consécutifs, Chromium est
// considéré comme « zombie » (page WhatsApp Web pendue, ex. Runtime.callFunctionOn
// timed out) — on quitte, Railway relance le conteneur avec un navigateur frais.
const MAX_CONSECUTIVE_SEND_FAILURES = parseInt(process.env.WHATSAPP_MAX_SEND_FAILURES || '3', 10);
let consecutiveSendFailures = 0;

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
      await withTimeout(sendJob(job, control.min_interval_seconds), SEND_TIMEOUT_MS, `envoi ${job.id}`);
      consecutiveSendFailures = 0;
    } catch (err) {
      state.failedCount += 1;
      console.warn(`[whatsapp] envoi ${job.id} échoué:`, err.message);
      await api.post(`/internal/whatsapp/jobs/${job.id}/result`, {
        status: 'failed',
        error: String(err.message || err).slice(0, 500),
      }).catch(() => {});

      consecutiveSendFailures += 1;
      if (consecutiveSendFailures >= MAX_CONSECUTIVE_SEND_FAILURES) {
        console.error(`[whatsapp] ${consecutiveSendFailures} échecs d'envoi consécutifs — redémarrage du conteneur (Chromium frais). La session LocalAuth est conservée sur le volume.`);
        await reportSession('disconnected', 'auto-restart après échecs d\'envoi consécutifs');
        process.exit(1); // Railway relance le conteneur
      }
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

// Diagnostic : version WhatsApp Web réellement chargée dans Chromium (permet de
// vérifier que le pin webVersionCache s'applique) + réactivité de la page.
app.get('/debug', async (_req, res) => {
  const out = { ready, session: state.session };
  try {
    out.wweb_version = await withTimeout(client.getWWebVersion(), 10000, 'getWWebVersion');
  } catch (err) {
    out.wweb_version_error = err.message;
  }
  try {
    out.page_url = client.pupPage ? await withTimeout(client.pupPage.url(), 5000, 'page.url') : null;
  } catch (err) {
    out.page_url_error = err.message;
  }
  out.page_log = pageLog.slice(-20);
  res.json(out);
});

/**
 * Self-test d'envoi de média isolé de la file d'attente : envoie une image
 * minuscule (1×1 px) au destinataire configuré, en instrumentant chaque étape,
 * et renvoie EXACTEMENT où ça bloque (upload vs sérialisation du retour) plus
 * les erreurs de la page pendant l'envoi. Sert à diagnostiquer le figement
 * des fiches AVEC photo sans polluer le vrai flux.
 */
const TINY_JPEG_B64 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAAAv/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AfwD/2Q==';
app.get('/selftest-media', async (_req, res) => {
  const t0 = Date.now();
  const steps = [];
  const mark = (s) => steps.push(`${Date.now() - t0}ms ${s}`);
  const before = pageLog.length;
  try {
    if (!ready) return res.status(503).json({ ok: false, error: 'session non prête' });
    mark('début');
    const control = (await api.get('/internal/whatsapp/control')).data.data;
    const to = control.recipient;
    if (!to) return res.status(400).json({ ok: false, error: 'recipient absent du control' });
    mark('recipient récupéré');
    const media = new MessageMedia('image/jpeg', TINY_JPEG_B64, 'selftest.jpg');
    mark('MessageMedia construit');
    const sent = await withTimeout(
      client.sendMessage(to, media, { caption: '[SELFTEST] ignore' }),
      60000,
      'sendMessage média',
    );
    mark('sendMessage résolu');
    res.json({
      ok: true,
      ms: Date.now() - t0,
      steps,
      message_id: sent?.id?._serialized ?? sent?.id?.$1 ?? sent?.id?.id ?? null,
      has_media_returned: sent?.hasMedia ?? null,
      type_returned: sent?.type ?? null,
      page_log_during: pageLog.slice(before),
    });
  } catch (err) {
    res.status(500).json({
      ok: false,
      ms: Date.now() - t0,
      steps,
      error: err.message,
      stack: String(err.stack || '').split('\n').slice(0, 4),
      page_log_during: pageLog.slice(before),
    });
  }
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
