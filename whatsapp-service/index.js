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
const sessionStore = require('./session-store');
const { createRecovery } = require('./recovery');

// ── Configuration (variables d'environnement uniquement) ─────────────────────
const API_BASE = (process.env.LARAVEL_API_BASE || 'http://localhost:8000/api/v1').replace(/\/$/, '');
const WORKER_SECRET = process.env.WHATSAPP_WORKER_SECRET || '';
const SESSION_PATH = process.env.WHATSAPP_SESSION_PATH || './.wwebjs_auth';
const HEALTH_PORT = parseInt(process.env.PORT || '3001', 10);
const IDLE_POLL_MS = parseInt(process.env.WHATSAPP_IDLE_POLL_MS || '5000', 10);
const ERROR_BACKOFF_MS = parseInt(process.env.WHATSAPP_ERROR_BACKOFF_MS || '15000', 10);

// ── Reprise après échec d'envoi (voir recovery.js) ───────────────────────────
// Le plafond de redémarrages tient SOUS restartPolicyMaxRetries (10, cf.
// railway.json) : le worker doit se mettre en veille de lui-même bien avant que
// Railway n'arrête définitivement le service — un service arrêté, c'est aussi
// la page /qr injoignable, donc plus aucun moyen de ré-appairer.
const MAX_CONSECUTIVE_SEND_FAILURES = parseInt(process.env.WHATSAPP_MAX_SEND_FAILURES || '3', 10);
const MAX_PAGE_RELOADS = parseInt(process.env.WHATSAPP_MAX_PAGE_RELOADS || '2', 10);
const MAX_SELF_RESTARTS = parseInt(process.env.WHATSAPP_MAX_SELF_RESTARTS || '4', 10);
/*
 * La fenêtre doit être NETTEMENT plus large que le temps qu'il faut pour
 * consommer le budget, sinon le frein ne serre jamais : avec une échéance de
 * mise en service à 15 min et une fenêtre d'une heure, les recyclages tombent à
 * 15, 30, 45 et 60 min — et le premier sort de la fenêtre juste à temps pour
 * autoriser le suivant. Le worker redémarrerait indéfiniment à raison de
 * quatre fois par heure, ce qui est précisément la boucle qu'on veut arrêter.
 */
const SELF_RESTART_WINDOW_MS = parseInt(process.env.WHATSAPP_SELF_RESTART_WINDOW_MS || String(4 * 3600 * 1000), 10);
// Durée de la mise en veille quand le budget de redémarrages est épuisé : au
// bout du compte à rebours, le worker retente — une panne qui s'est résorbée
// seule (backend rétabli, WhatsApp Web réparé) ne doit pas exiger un humain.
const HALT_COOLDOWN_MS = parseInt(process.env.WHATSAPP_HALT_COOLDOWN_MS || String(1800 * 1000), 10);

// ── Coffre de session (persistance durable, hors volume) ─────────────────────
// Le volume Railway reste le chemin rapide ; le coffre est le filet quand
// l'instance est recréée. Voir session-store.js.
const VAULT_ENABLED = String(process.env.WHATSAPP_SESSION_VAULT_ENABLED || '1') !== '0';
// Délai après « ready » avant le premier dépôt : laisse WhatsApp Web finir
// d'écrire son profil, pour ne pas archiver un état à moitié posé.
const VAULT_FIRST_SNAPSHOT_MS = parseInt(process.env.WHATSAPP_SESSION_SNAPSHOT_DELAY_MS || '180000', 10);
const VAULT_SNAPSHOT_INTERVAL_MS = parseInt(process.env.WHATSAPP_SESSION_SNAPSHOT_INTERVAL_MS || String(6 * 3600 * 1000), 10);

// ── Place sur le volume ──────────────────────────────────────────────────────
// Un seul profil écarté conservé par défaut (contre deux auparavant) : sur un
// volume de 500 Mo, deux profils à ~100 Mo mangeaient 40 % de la place pour un
// besoin d'analyse d'incident que le plus récent couvre déjà.
const KEEP_PARKED_PROFILES = parseInt(process.env.WHATSAPP_KEEP_PARKED_PROFILES || '1', 10);
// Seuil d'occupation au-delà duquel on le dit franchement dans les journaux.
const VOLUME_WARN_RATIO = parseFloat(process.env.WHATSAPP_VOLUME_WARN_RATIO || '0.8');

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
  session: 'initializing', // initializing | ready | disconnected | logged_out | auth_failure
  reason: null,
  // Où en est le démarrage, et depuis quand. « initializing » pendant neuf
  // heures ne disait pas si Chromium n'avait jamais démarré, si WhatsApp Web
  // chargeait encore, ou si l'authentification était passée sans jamais
  // aboutir — trois pannes très différentes sous un seul mot.
  phase: 'démarrage',
  phaseAt: new Date().toISOString(),
  lastSendAt: null,
  lastPollAt: null,
  sentCount: 0,
  failedCount: 0,
  qrDataUrl: null, // QR courant en image (pour la page /qr, scannable au téléphone)
};

let ready = false;

const recovery = createRecovery({
  dataPath: SESSION_PATH,
  maxPageFailures: MAX_CONSECUTIVE_SEND_FAILURES,
  maxReloads: MAX_PAGE_RELOADS,
  maxRestarts: MAX_SELF_RESTARTS,
  restartWindowMs: SELF_RESTART_WINDOW_MS,
});

/*
 * Veille technique : le worker reste en vie mais cesse d'émettre.
 *
 * C'est la porte de sortie du cercle vicieux « échec → redémarrage → échec ».
 * Tant qu'elle est fermée, aucune fiche n'est réclamée : rien ne sert de
 * consommer des tentatives (et de repousser le backoff des fiches) sur un canal
 * dont on sait qu'il ne passe pas. Les fiches restent en file côté Laravel et
 * repartiront à la reprise — le check-in, lui, n'a jamais dépendu de ce worker.
 */
let sendingSuspendedUntil = 0;
let suspensionReason = null;
// Début de la veille en cours : sert à ignorer un ordre de reprise ANTÉRIEUR
// (un vieux clic « Reprendre » ne doit pas annuler une veille toute neuve).
let suspensionStartedAt = 0;

/** État réel à annoncer au backend — la veille prime sur le « ready » de la bibliothèque. */
function currentStatus() {
  if (sendingSuspendedUntil > Date.now()) return 'disconnected';

  return ready ? 'ready' : state.session;
}

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

/*
 * Stratégie d'authentification NON DESTRUCTIVE.
 *
 * whatsapp-web.js 1.34.7, Client.js:495-501 :
 *
 *     this.pupPage.on('framenavigated', async (frame) => {
 *       if (frame.url().includes('post_logout=1') || this.lastLoggedOut) {
 *         this.emit(Events.DISCONNECTED, 'LOGOUT');
 *         await this.authStrategy.logout();      // <— rm -rf du profil
 *
 * et LocalAuth.logout() fait un fs.rm(userDataDir, { recursive: true }).
 * C'est le SEUL endroit de la bibliothèque qui produit la chaîne « LOGOUT »,
 * celle-là même que portait l'alerte. La suppression a lieu AVANT que notre
 * gestionnaire 'disconnected' ne s'exécute : quand notre code apprend la
 * nouvelle, le profil n'existe déjà plus.
 *
 * On ne peut pas empêcher la bibliothèque d'appeler logout(), mais on peut
 * décider de ce que logout() FAIT. Ici : on écarte le profil au lieu de le
 * détruire, et on pose le marqueur de révocation. Rien n'est perdu — ni pour
 * l'analyse d'incident, ni pour une éventuelle récupération — et surtout le
 * profil révoqué cesse d'être pris pour une session valide.
 */
class NonDestructiveLocalAuth extends LocalAuth {
  async logout() {
    const dir = this.userDataDir;
    if (!dir) return;

    // La révocation d'abord : même si le déplacement échoue, le profil ne doit
    // plus jamais passer pour une session exploitable.
    sessionStore.markRevoked(SESSION_PATH);
    loggedOutForReal = true;

    try {
      const parked = `${dir}.revoked-${Date.now()}`;
      fs.renameSync(dir, parked);
      fs.mkdirSync(dir, { recursive: true }); // la bibliothèque le rouvre juste après
      console.warn(`[whatsapp] LOGOUT WhatsApp — profil ÉCARTÉ (non supprimé) sous « ${path.basename(parked)} ».`);
      pruneRevokedProfiles(SESSION_PATH);
    } catch (err) {
      console.warn('[whatsapp] mise à l\'écart du profil révoqué impossible :', err.message);
    }
  }
}

/**
 * Ne garder que les profils écartés les plus récents : le volume est plafonné
 * (500 Mo) et un profil pèse ~100 Mo. Sans ce ménage, une série de révocations
 * saturerait le disque et empêcherait toute nouvelle session.
 *
 * ⚠️ Ce ménage-ci ne s'exécutait QU'À la création d'un nouveau profil écarté.
 * Après une révocation isolée, les ~100 Mo restaient donc à demeure jusqu'à la
 * révocation suivante — qui pouvait ne jamais venir. La reprise de place
 * complète a lieu désormais au démarrage (sessionStore.reclaimSpace).
 */
function pruneRevokedProfiles(dataPath, keep = KEEP_PARKED_PROFILES) {
  try {
    for (const name of sessionStore.parkedProfiles(dataPath).slice(keep)) {
      fs.rmSync(path.join(dataPath, name), { recursive: true, force: true });
      console.log('[whatsapp] ancien profil écarté purgé :', name);
    }
  } catch (err) {
    console.warn('[whatsapp] purge des profils écartés :', err.message);
  }
}

/** Vrai dès qu'un LOGOUT WhatsApp a été constaté : un QR devient indispensable. */
let loggedOutForReal = false;

const client = new Client({
  authStrategy: new NonDestructiveLocalAuth({ dataPath: SESSION_PATH }),
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
    // Numéro réellement appairé : c'est le backend qui décide, à partir de lui,
    // si la réputation repart de zéro (montée en charge). Un ré-appairage avec
    // un AUTRE numéro est un compte neuf pour Meta, même si le service, lui, a
    // des mois de service. Best-effort : `client.info` n'existe qu'une fois prêt.
    await api.post('/internal/whatsapp/session', {
      status,
      reason,
      phone_number: connectedNumber(),
    });
  } catch (err) {
    console.warn('[whatsapp] report session failed:', err.message);
  }
}

/** @returns {string|null} numéro connecté (chiffres), ou null si pas encore prêt. */
function connectedNumber() {
  try {
    return client?.info?.wid?.user ?? null;
  } catch {
    return null;
  }
}

/**
 * Recyclage volontaire du conteneur (Chromium neuf), quand c'est le seul geste
 * qui puisse encore réparer.
 *
 * Trois différences avec le `process.exit(1)` d'avant, et chacune corrige une
 * panne constatée :
 *
 *  1. Le budget est consulté AVANT. Au-delà de N recyclages par heure, la
 *     réparation n'en est plus une : on passe en veille au lieu de brûler le
 *     quota de crashs Railway (10, après quoi le service est arrêté pour de bon).
 *  2. L'arrêt est PROPRE : Chromium est fermé et la session déposée au coffre,
 *     comme sur SIGTERM. Couper Node avec le navigateur en pleine écriture
 *     laissait un profil LocalAuth incohérent — le « redémarrage réparateur »
 *     abîmait la session qu'il devait préserver.
 *  3. L'état annoncé est « initializing », pas « disconnected ». Un recyclage
 *     décidé par le worker n'est pas une perte de session : l'annoncer comme
 *     telle envoyait aux administrateurs, à chaque tour de boucle, un email
 *     « session temporairement déconnectée » qui ne décrivait rien de réel.
 */
async function selfRestart(reason) {
  if (recovery.restartBudgetExhausted()) {
    return haltSending(`${reason} — budget de redémarrages épuisé`);
  }

  const count = recovery.noteRestart(reason);
  console.error(`[whatsapp] redémarrage du conteneur (${count}/${MAX_SELF_RESTARTS} sur la fenêtre) — ${reason}. La session LocalAuth est conservée.`);
  await reportSession('initializing', `Recyclage technique du worker — ${reason}`);

  return shutdown('auto-restart', 1);
}

/**
 * Mise en veille : on arrête d'émettre pour un temps, sans quitter.
 *
 * Contrairement au recyclage, la veille décrit un vrai problème non résolu —
 * elle s'annonce donc en « disconnected », ce qui alerte les administrateurs
 * (une fois : la déduplication d'alerte porte sur l'événement, et
 * `last_ready_at` ne bouge plus tant qu'on n'a pas repris).
 */
async function haltSending(reason) {
  // Idempotente : la sonde de vivacité et la boucle d'envoi peuvent toutes deux
  // buter sur le même mur. Sans ce garde, chacune repousserait le compte à
  // rebours de la précédente — une veille qui ne finit jamais.
  if (sendingSuspendedUntil > Date.now()) return;

  sendingSuspendedUntil = Date.now() + HALT_COOLDOWN_MS;
  suspensionStartedAt = Date.now();
  suspensionReason = reason;
  const minutes = Math.round(HALT_COOLDOWN_MS / 60000);
  console.error(`[whatsapp] envois suspendus ${minutes} min — ${reason}. Le service reste joignable (/health, /qr, /debug).`);
  await reportSession(
    'disconnected',
    `Envois suspendus ${minutes} min après échecs répétés — ${reason}`,
  );
}

/**
 * Annonce l'occupation du volume, et la signale franchement quand elle devient
 * inquiétante. Sans cette ligne, la seule façon de l'apprendre était d'ouvrir
 * la console Railway — encore fallait-il soupçonner que c'était là qu'il fallait
 * regarder.
 */
function logVolumeSpace(moment) {
  const space = sessionStore.volumeSpace(SESSION_PATH);
  if (!space) return;

  const used = Math.round(space.usedRatio * 100);
  const freeMb = (space.freeBytes / 1048576).toFixed(0);
  const totalMb = (space.totalBytes / 1048576).toFixed(0);
  const line = `[whatsapp] volume ${moment} : ${used} % occupé (${freeMb} Mo libres sur ${totalMb} Mo).`;

  if (space.usedRatio >= VOLUME_WARN_RATIO) {
    console.warn(`${line} ⚠️ Chromium a besoin de place pour écrire sa session : au-delà, les envois échouent sans raison visible.`);
  } else {
    console.log(line);
  }
}

/** Fin de veille : on redonne sa chance au canal. */
function resumeSendingIfDue() {
  if (!sendingSuspendedUntil || sendingSuspendedUntil > Date.now()) return false;

  sendingSuspendedUntil = 0;
  suspensionReason = null;
  recovery.success(); // compteurs remis à neuf : la veille valait la sanction
  console.log('[whatsapp] fin de la veille technique — reprise des envois.');

  return true;
}

/*
 * Reprise sur ordre d'un administrateur (bouton « Reprendre »).
 *
 * La veille technique est décidée par le worker et vit dans SA mémoire : la
 * base n'en sait rien. Un exploitant qui venait de réparer la panne (session
 * ré-appairée, worker recyclé) n'avait donc aucun moyen d'écourter les 30 min
 * — il attendait sans savoir pourquoi rien ne repartait.
 *
 * On ne retient que les demandes POSTÉRIEURES au début de la veille : sinon un
 * vieil horodatage, laissé en base par un clic d'hier, annulerait aussitôt
 * chaque nouvelle mise en veille.
 */
let lastHandledResumeRequest = 0;
function liftSuspensionIfRequested(resumeRequestedAt) {
  if (!resumeRequestedAt || sendingSuspendedUntil <= Date.now()) return false;

  const requestedMs = Date.parse(resumeRequestedAt);
  if (!Number.isFinite(requestedMs)) return false;
  if (requestedMs <= suspensionStartedAt || requestedMs <= lastHandledResumeRequest) return false;

  lastHandledResumeRequest = requestedMs;
  sendingSuspendedUntil = 0;
  suspensionReason = null;
  recovery.success();
  console.log('[whatsapp] veille levée à la demande d\'un administrateur — reprise des envois.');

  return true;
}

/*
 * Watchdog anti-blocage au démarrage.
 *
 * ⚠️ CE WATCHDOG EFFAÇAIT LA SESSION — c'était la cause des « déconnexions à
 * chaque déploiement ». Dès que ni QR ni connexion n'arrivaient sous 120 s, il
 * vidait le dossier de session sur le volume puis redémarrait. Or un démarrage
 * à froid (conteneur neuf, Chromium à lancer, profil à ouvrir depuis un volume
 * réseau, WhatsApp Web à charger) dépasse régulièrement 120 s — et l'événement
 * `authenticated` ne désarmait même pas le compte à rebours. Le worker
 * détruisait donc lui-même une session parfaitement valide, puis affichait un
 * QR. Comme les deux services Railway se redéploient depuis le même dépôt,
 * chaque livraison du backend rejouait ce tirage au sort.
 *
 * Désormais : délai plus large, désarmé dès le moindre signe de vie, et un
 * dépassement ne fait que REDÉMARRER — la session reste sur le volume. Effacer
 * n'est plus qu'une décision humaine (WHATSAPP_ALLOW_SESSION_WIPE=1).
 */
const WATCHDOG_MS = parseInt(process.env.WHATSAPP_WATCHDOG_MS || '420000', 10);
const watchdog = setTimeout(() => {
  if (ready || state.qrDataUrl) return;

  if (sessionStore.shouldWipeOnStartupTimeout()) {
    console.error('[whatsapp] bloqué au démarrage — effacement de la session EXPLICITEMENT autorisé (WHATSAPP_ALLOW_SESSION_WIPE=1).');
    wipeSession(SESSION_PATH);
  }

  // Passe par le frein commun : une boucle de démarrages ratés est le plus sûr
  // moyen d'épuiser le quota Railway et de rendre /qr injoignable — c'est-à-dire
  // de supprimer le seul geste qui aurait pu réparer.
  selfRestart('bloqué au démarrage (ni QR ni connexion)')
    .catch((err) => console.warn('[whatsapp] recyclage au démarrage :', err.message));
}, WATCHDOG_MS);

/** Désarme le compte à rebours dès qu'un signe de vie arrive, quel qu'il soit. */
let watchdogDisarmed = false;
function disarmWatchdog(signal) {
  state.phase = signal;
  state.phaseAt = new Date().toISOString();

  if (watchdogDisarmed) return;
  watchdogDisarmed = true;
  clearTimeout(watchdog);
  console.log(`[whatsapp] démarrage confirmé (${signal}).`);
}

/*
 * ÉCHÉANCE DE MISE EN SERVICE — le garde-fou qui manquait.
 *
 * Constaté le 2026-08-10 : le worker est resté NEUF HEURES en « initializing »
 * sans jamais tenter quoi que ce soit. Pas de QR, pas de recyclage, pas
 * d'alerte du worker — et pour cause :
 *
 *   • le watchdog de démarrage ci-dessus est désarmé DÉFINITIVEMENT au premier
 *     signe de vie, y compris un `loading_screen` à 1 %. Il répond à « est-ce
 *     que quelque chose s'est passé ? », jamais à « est-ce qu'on est devenu
 *     utilisable ? » ;
 *   • le heartbeat, lui, continuait de battre — la boucle d'envoi tourne très
 *     bien sur une session qui n'est pas prête. Le backend voyait donc un
 *     worker parfaitement vivant, et son alerte « worker injoignable » ne
 *     pouvait pas se déclencher.
 *
 * Résultat : un worker qui commence à démarrer et n'y arrive jamais était le
 * seul état du système que RIEN ne reprenait. Les fiches s'accumulaient en
 * silence, exactement comme au premier jour.
 *
 * Cette échéance-ci ne se désarme QUE sur « ready ». Un signe de vie prouve
 * que le démarrage progresse, jamais qu'il aboutira.
 */
const READY_DEADLINE_MS = parseInt(process.env.WHATSAPP_READY_DEADLINE_MS || '900000', 10);
const readyDeadline = setTimeout(() => {
  if (ready) return;

  /*
   * Un QR affiché attend un geste humain. Redémarrer le remplacerait par un
   * autre sans rien réparer — et ferait disparaître celui que quelqu'un est
   * peut-être en train de scanner. On le DIT, au lieu de s'agiter.
   */
  if (state.qrDataUrl) {
    console.error(`[whatsapp] QR affiché et non scanné depuis ${Math.round(READY_DEADLINE_MS / 60000)} min — ré-appairage attendu, aucun recyclage.`);
    reportSession('logged_out', 'Un QR attend d\'être scanné — ré-appairage nécessaire pour que les fiches repartent.');

    return;
  }

  console.error(`[whatsapp] session jamais prête après ${Math.round(READY_DEADLINE_MS / 60000)} min (phase : ${state.phase}) — recyclage.`);
  selfRestart(`session jamais prête après ${Math.round(READY_DEADLINE_MS / 60000)} min (phase : ${state.phase})`)
    .catch((err) => console.warn('[whatsapp] recyclage sur échéance :', err.message));
}, READY_DEADLINE_MS);

/*
 * Cadence d'impression du QR dans les journaux.
 *
 * WhatsApp régénère le QR toutes les ~20 s. Chaque impression occupe ~35
 * lignes : une session non appairée produisait donc ~100 lignes/minute de
 * damier. Lors de l'analyse de l'incident du 2026-08-08, ce flot avait évincé
 * TOUT l'historique exploitable des journaux Railway en une quinzaine de
 * minutes — impossible de reconstituer la séquence qui avait mené au LOGOUT.
 * Le QR reste disponible en permanence, et à jour, sur la page /qr.
 */
const QR_LOG_INTERVAL_MS = parseInt(process.env.WHATSAPP_QR_LOG_INTERVAL_MS || '300000', 10);
let lastQrLoggedAt = 0;
let qrCount = 0;

client.on('qr', (qr) => {
  disarmWatchdog('QR émis');
  qrCount += 1;

  // Un QR demandé alors qu'on croyait avoir une session : c'est la preuve que
  // les credentials restaurés ne valent plus rien. On le signale une fois, au
  // lieu de rester indéfiniment en « initialisation » pendant que les fiches
  // s'accumulent sans que personne ne sache qu'un QR est attendu.
  if (!loggedOutForReal && restoredFromStoredSession && qrCount === 1) {
    loggedOutForReal = true;
    console.error('[whatsapp] un QR est demandé alors qu\'une session existait : ces credentials sont révoqués.');
    sessionStore.markRevoked(SESSION_PATH, { reason: 'QR demandé malgré une session restaurée' });
    reportSession('logged_out', 'Session existante refusée par WhatsApp — ré-appairage par QR nécessaire.');
  }

  // Premier démarrage : scanner ce QR avec la SIM dédiée Qayed (jamais un numéro perso).
  // 1) dans les logs (ASCII) ; 2) en image sur la page /qr (scannable depuis un téléphone).
  QRCode.toDataURL(qr, { margin: 2, width: 320 })
    .then((url) => { state.qrDataUrl = url; })
    .catch((err) => console.warn('[whatsapp] QR image error:', err.message));

  const now = Date.now();
  if (lastQrLoggedAt && now - lastQrLoggedAt < QR_LOG_INTERVAL_MS) return;
  lastQrLoggedAt = now;

  console.log(`[whatsapp] Scannez ce QR avec le téléphone émetteur Qayed (ou ouvrez /qr) — QR n° ${qrCount}, réimprimé au plus toutes les ${Math.round(QR_LOG_INTERVAL_MS / 60000)} min :`);
  qrcode.generate(qr, { small: true });
});

client.on('authenticated', () => {
  // Désarmement indispensable : une session restaurée s'authentifie bien avant
  // d'être « ready », et c'est exactement pendant cet intervalle que l'ancien
  // watchdog effaçait le profil.
  disarmWatchdog('authentifié');
  console.log('[whatsapp] authentifié.');
});

// Chargement de la conversation après authentification : encore un signe de vie.
client.on('loading_screen', (percent) => disarmWatchdog(`chargement ${percent}%`));

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
  disarmWatchdog('session prête');
  clearTimeout(readyDeadline); // seul événement qui lève l'échéance de mise en service
  ready = true;
  state.qrDataUrl = null; // plus besoin du QR une fois connecté
  console.log('[whatsapp] session prête.');
  // Une veille technique en cours n'est pas levée par un simple « ready » : le
  // canal a échoué de façon répétée alors que la bibliothèque se disait déjà
  // prête. C'est la fin du compte à rebours qui décide, pas cet événement.
  if (sendingSuspendedUntil <= Date.now()) reportSession('ready');
  attachPageDiagnostics();
  startPageLivenessWatchdog();

  // Marqueur d'appairage : c'est ICI, et nulle part avant, qu'on sait que le
  // profil sur le volume correspond à une session réellement acceptée par
  // WhatsApp. Un démarrage qui affiche un QR fabrique un profil complet lui
  // aussi — sans ce marqueur, il passerait pour une session valide et
  // masquerait la copie saine du coffre.
  sessionStore.markPaired(SESSION_PATH);

  startSessionSnapshots();
});

/**
 * Dépôts périodiques de la session dans le coffre.
 *
 * Le premier est différé : WhatsApp Web finit d'écrire son profil après
 * « ready », et archiver trop tôt donnerait une copie à moitié posée.
 */
let snapshotTimer = null;
function startSessionSnapshots() {
  if (snapshotTimer || !VAULT_ENABLED) return;

  const take = (reason) => sessionStore
    .snapshot({ api, dataPath: SESSION_PATH, enabled: VAULT_ENABLED, reason })
    .catch((err) => console.warn('[wa-session] dépôt en erreur :', err.message));

  setTimeout(() => {
    take('après appairage');
    snapshotTimer = setInterval(() => take('périodique'), VAULT_SNAPSHOT_INTERVAL_MS);
  }, VAULT_FIRST_SNAPSHOT_MS);
}

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
    // En veille technique, la décision est déjà prise : sonder pour escalader de
    // nouveau ne ferait que relancer la boucle qu'on vient d'interrompre. On
    // repart d'une ardoise vierge quand la veille se lève.
    if (sendingSuspendedUntil > Date.now()) {
      misses = 0;
      reloads = 0;

      return;
    }

    try {
      // On sonde la PRÉSENCE DES HELPERS, pas seulement la vivacité de la page.
      // `evaluate('1')` réussissait sur une page bien vivante mais dont
      // l'injection whatsapp-web.js avait disparu (ré-appairage, a fortiori avec
      // un autre numéro) : la sonde voyait tout vert pendant que chaque envoi
      // mourait sur « Cannot read properties of undefined (reading 'getChat') ».
      // Un helper manquant vaut une page muette : reload, puis recyclage.
      const injected = await withTimeout(
        client.pupPage.evaluate('typeof window.WWebJS !== "undefined" && typeof window.Store !== "undefined"'),
        15000,
        'sonde de vivacité',
      );
      if (!injected) throw new Error('injection whatsapp-web.js absente de la page (window.WWebJS/Store)');
      misses = 0;
      reloads = 0;
    } catch (err) {
      misses += 1;
      console.warn(`[whatsapp] page muette (${misses}/3):`, err.message);
      if (misses < 3) return;

      // 1er niveau : recharger la page — un reload crée un renderer NEUF sans
      // consommer le budget de redémarrages Railway (10 crashs max avant arrêt
      // définitif du service). La session LocalAuth du profil est réutilisée.
      if (reloads < MAX_PAGE_RELOADS) {
        reloads += 1;
        misses = 0;
        console.warn(`[whatsapp] rechargement de la page WhatsApp (tentative ${reloads}/${MAX_PAGE_RELOADS})…`);
        await reloadPage();
        return;
      }

      // 2e niveau : le reload ne suffit pas → recyclage complet du conteneur.
      await selfRestart('page WhatsApp morte malgré les reloads (OOM renderer probable)');
    }
  }, 60000);
}

/**
 * Recharge la page WhatsApp Web : un renderer neuf, sans quitter le conteneur
 * ni toucher au profil LocalAuth. C'est toujours le geste à tenter avant le
 * recyclage — il ne coûte rien au budget de crashs Railway.
 */
async function reloadPage() {
  try {
    await withTimeout(client.pupPage.reload({ waitUntil: 'domcontentloaded' }), 60000, 'reload');
    console.log('[whatsapp] page rechargée.');

    return true;
  } catch (err) {
    console.warn('[whatsapp] reload échoué:', err.message);

    return false;
  }
}

client.on('auth_failure', (msg) => {
  ready = false;
  console.error('[whatsapp] auth_failure:', msg);
  reportSession('auth_failure', String(msg || 'auth_failure'));
});

/*
 * Une déconnexion n'est pas l'autre, et les confondre était le vrai problème
 * d'exploitation : le système annonçait « scannez un QR » pour une coupure
 * réseau de trente secondes, et restait muet — « en cours d'initialisation » —
 * quand un ré-appairage était réellement devenu obligatoire.
 *
 *  • LOGOUT  → WhatsApp a révoqué l'appareil lié. Aucune reconnexion possible,
 *              un QR est INDISPENSABLE. C'est un état durable, qui doit
 *              survivre aux redémarrages.
 *  • le reste (CONFLICT, UNPAIRED, TIMEOUT, NAVIGATION…) → incident technique,
 *              la session reste valide, la reconnexion se fait toute seule.
 */
client.on('disconnected', (reason) => {
  ready = false;
  const isLogout = String(reason || '').toUpperCase() === 'LOGOUT';

  if (isLogout) {
    loggedOutForReal = true;
    console.error('[whatsapp] LOGOUT : WhatsApp a révoqué cet appareil lié — un ré-appairage par QR est nécessaire.');
    reportSession('logged_out', 'WhatsApp a révoqué l\'appareil lié (LOGOUT) — ré-appairage par QR nécessaire.');

    return;
  }

  console.warn('[whatsapp] déconnecté (incident technique, reconnexion attendue) :', reason);
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
async function sendJob(job, minIntervalSeconds, jitterRatio) {
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
    // `$1` : les builds WhatsApp Web récents renomment `_serialized` (voir le
    // correctif patches/whatsapp-web.js). Sans ce repli, un envoi réussi était
    // journalisé sans identifiant de message — donc introuvable en cas de litige.
    status: 'sent',
    message_id: sent?.id?._serialized ?? sent?.id?.$1 ?? sent?.id?.id ?? null,
  });

  // Délai minimum anti-ban entre deux messages, volontairement IRRÉGULIER : un
  // intervalle constant à la milliseconde près est en soi une signature
  // d'automate. La gigue n'écourte jamais l'attente sous le plancher, elle
  // ne fait qu'ajouter.
  await sleep(jitteredIntervalMs(minIntervalSeconds, jitterRatio));
}

/** Plancher, plus un tirage aléatoire dans [0, ratio × plancher]. */
function jitteredIntervalMs(minIntervalSeconds, jitterRatio = 0) {
  const floorMs = Math.max(Number(minIntervalSeconds) || 0, 1) * 1000;
  const ratio = Math.min(Math.max(Number(jitterRatio) || 0, 0), 1);

  return Math.round(floorMs + Math.random() * ratio * floorMs);
}

async function tick() {
  state.lastPollAt = new Date().toISOString();

  // Sortie de veille : les compteurs repartent à neuf et on réannonce l'état
  // réel, faute de quoi le backend nous croirait déconnectés indéfiniment.
  if (resumeSendingIfDue() && ready) {
    await reportSession('ready');
  }

  try {
    // Le backend décide si on peut avancer (activé, non en pause, session prête).
    const control = (await api.get('/internal/whatsapp/control')).data.data;

    // Resynchronisation d'état : si le backend a une autre vision de la session
    // (ex. notre POST « ready » perdu pendant un redéploiement backend), on
    // re-signale notre état réel. Sans ça, le backend restait « initializing »
    // pour toujours → distribution gelée alors que la session était connectée.
    // ⚠️ Pendant une veille technique, l'état réel EST « disconnected » : sans
    // cette nuance, la resynchronisation aurait aussitôt réannoncé « ready » et
    // annulé la mise en veille qu'on venait de décider.
    // Reprise demandée par un administrateur : elle lève la veille technique.
    // Sans ça, « Reprendre » ne remettait que `paused` à false côté base — la
    // veille du worker, elle, courait jusqu'à son terme (30 min) sans qu'aucun
    // bouton n'y puisse rien, y compris après une panne déjà réparée.
    if (liftSuspensionIfRequested(control.resume_requested_at) && ready) {
      await reportSession('ready');
    }

    const localStatus = currentStatus();
    if (control.session_status && control.session_status !== localStatus) {
      console.warn(`[whatsapp] resynchronisation d'état : backend=${control.session_status}, local=${localStatus}`);
      await reportSession(localStatus, state.reason);
    }

    if (!ready || sendingSuspendedUntil > Date.now() || !control.enabled || control.paused) {
      return IDLE_POLL_MS;
    }

    const { job } = (await api.get('/internal/whatsapp/next')).data.data;
    if (!job) {
      return IDLE_POLL_MS;
    }

    try {
      await withTimeout(
        sendJob(job, control.min_interval_seconds, control.interval_jitter_ratio),
        SEND_TIMEOUT_MS,
        `envoi ${job.id}`,
      );
      recovery.success();
    } catch (err) {
      state.failedCount += 1;

      /*
       * Toute la nuance est ici (voir recovery.js) : un échec d'envoi n'est pas
       * forcément un navigateur à jeter. Une photo que le backend ne rend pas,
       * un destinataire refusé, une fiche rejetée — le redémarrage n'y change
       * rien, et le déclencher revenait à transformer l'incident d'UNE fiche en
       * panne de tout le canal, jusqu'à épuisement du quota Railway.
       */
      const outcome = recovery.failure(err, { maxRefusals: control.circuit_breaker_failures });
      console.warn(`[whatsapp] envoi ${job.id} échoué [${outcome.kind}]:`, err.message);
      await api.post(`/internal/whatsapp/jobs/${job.id}/result`, {
        status: 'failed',
        // La famille est journalisée avec l'erreur : le journal admin distingue
        // désormais d'un coup d'œil « le backend n'a pas rendu la photo » de
        // « la page WhatsApp ne répond plus » — la question qu'on ne pouvait
        // trancher qu'en fouillant des journaux Railway déjà évincés.
        error: `[${outcome.kind}] ${String(err.message || err)}`.slice(0, 500),
      }).catch(() => {});

      switch (outcome.decision) {
        // Panne backend/réseau : laisser au backend le temps de revenir plutôt
        // que de consommer les tentatives des fiches suivantes pour rien.
        case 'backoff':
          return ERROR_BACKOFF_MS;

        /*
         * Disjoncteur. WhatsApp refuse en série alors que la page répond : le
         * canal est bloqué, pas les fiches. Le worker s'arrête ET demande au
         * backend de couper durablement — sa propre veille ne survivrait pas au
         * prochain redémarrage de conteneur, un compte restreint si.
         *
         * C'est le seul cas où réessayer est PIRE que ne rien faire : chaque
         * tentative sous restriction est une infraction de plus.
         */
        case 'halt':
          console.error(`[whatsapp] disjoncteur : ${outcome.refusals} refus consécutifs [${outcome.kind}] — arrêt des envois.`);
          await api.post('/internal/whatsapp/halt', {
            reason: `${outcome.refusals} envois refusés d'affilée alors que la session répondait — dernière erreur : ${String(err.message || err)}`.slice(0, 500),
          }).catch((e) => console.warn('[whatsapp] coupure non transmise au backend :', e.message));
          await haltSending(`${outcome.refusals} envois refusés d'affilée — restriction de compte probable`);

          return IDLE_POLL_MS;

        case 'reload':
          console.warn(`[whatsapp] ${outcome.pageFailures} échecs d'envoi consécutifs imputables à la page — rechargement (tentative ${outcome.reloads}/${MAX_PAGE_RELOADS}).`);
          await reloadPage();

          return ERROR_BACKOFF_MS;

        case 'restart':
          await selfRestart(`${outcome.pageFailures} échecs d'envoi consécutifs, rechargements sans effet`);

          return IDLE_POLL_MS;

        default:
          // Une page qui vient de faillir mérite un souffle : enchaîner sur la
          // fiche suivante pendant qu'un envoi précédent est peut-être encore
          // en vol, c'est empiler les évaluations sur un renderer déjà en peine.
          return outcome.kind === 'page' ? ERROR_BACKOFF_MS : 0;
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

// Jeton d'accès aux pages sensibles (/qr, /debug, /selftest-media) : quiconque
// voit le QR peut capter la session WhatsApp, et /selftest-media envoie un vrai
// message. Le jeton passe en query (?token=…) — il est intégré à WHATSAPP_QR_URL
// côté backend, donc le bouton de l'email d'alerte l'inclut automatiquement.
// Vide => pages ouvertes (compatibilité), avec avertissement au démarrage.
const QR_TOKEN = process.env.WHATSAPP_QR_TOKEN || '';
if (!QR_TOKEN) {
  console.warn('[whatsapp] WHATSAPP_QR_TOKEN absent : /qr, /debug et /selftest-media sont accessibles sans jeton.');
}
const crypto = require('crypto');
function requireToken(req, res, next) {
  if (!QR_TOKEN) return next();
  const given = Buffer.from(String(req.query.token || ''));
  const expected = Buffer.from(QR_TOKEN);
  if (given.length === expected.length && crypto.timingSafeEqual(given, expected)) return next();
  // 404 volontaire : ne pas révéler l'existence de la page à qui n'a pas le jeton.
  res.status(404).type('text/plain').send('Not found');
}

app.get('/health', (_req, res) => {
  const { qrDataUrl, ...safe } = state;
  const suspended = sendingSuspendedUntil > Date.now();
  // `ok` reste l'état de la SESSION, pas celui de la file : une veille technique
  // ne doit pas pouvoir être lue comme un conteneur à tuer — ce serait rouvrir
  // la boucle de redémarrages que la veille existe pour fermer.
  res.json({
    ok: ready,
    has_qr: !!qrDataUrl,
    ...safe,
    // Rend visible ce qui était le plus difficile à diagnostiquer : le worker
    // se réparait en boucle sans que rien, hors des journaux Railway déjà
    // évincés, n'en garde la trace.
    self_restarts_in_window: recovery.restartsInWindow(),
    // Un volume plein fait échouer les envois sans rien dire : la place
    // restante appartient au diagnostic de premier niveau.
    volume: sessionStore.volumeSpace(SESSION_PATH),
    sending_suspended: suspended,
    suspended_until: suspended ? new Date(sendingSuspendedUntil).toISOString() : null,
    suspension_reason: suspended ? suspensionReason : null,
    // Refus consécutifs de WhatsApp : au seuil, le disjoncteur coupe le relais.
    // Un compteur qui grimpe est le signe avant-coureur d'une restriction.
    refusal_streak: recovery.refusalStreak(),
  });
});

// Diagnostic : version WhatsApp Web réellement chargée dans Chromium (permet de
// vérifier que le pin webVersionCache s'applique) + réactivité de la page.
app.get('/debug', requireToken, async (_req, res) => {
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
 * Diagnostic du coffre de session — sert au contrôle après déploiement :
 * « la session a-t-elle survécu, et une copie durable existe-t-elle ? ».
 * Ne renvoie QUE des métadonnées (présence, magasins, tailles) — jamais un
 * octet de la session elle-même.
 */
app.get('/session-vault', requireToken, async (_req, res) => {
  const local = sessionStore.localSessionState(SESSION_PATH);

  const out = {
    ready,
    session: state.session,
    vault_enabled: VAULT_ENABLED,
    local: {
      exists: local.exists,
      usable: local.usable,
      paired: local.paired,
      stores: local.stores,
      megabytes: +(local.bytes / 1048576).toFixed(1),
    },
  };

  try {
    out.vault = (await api.get('/internal/whatsapp/session-archive/meta')).data.data;
  } catch (err) {
    out.vault_error = err.message;
  }

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
app.get('/selftest-media', requireToken, async (_req, res) => {
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
app.get('/qr', requireToken, (_req, res) => {
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

// ── Arrêt propre ─────────────────────────────────────────────────────────────
/*
 * Railway envoie SIGTERM avant de couper le conteneur. Sans gestionnaire, Node
 * s'arrête net : Chromium est tué au milieu de ses écritures et laisse un
 * profil (LevelDB, IndexedDB) potentiellement incohérent sur le volume, ce qui
 * rallonge — ou fait échouer — le démarrage suivant.
 *
 * On ferme donc Chromium proprement, PUIS on archive : c'est le seul moment où
 * le profil est certainement au repos, donc la meilleure copie possible. Tout
 * est borné dans le temps, un arrêt ne doit jamais traîner.
 */
let shuttingDown = false;
async function shutdown(signal, exitCode = 0) {
  if (shuttingDown) return;
  shuttingDown = true;
  console.log(`[whatsapp] ${signal} reçu — arrêt propre.`);

  /*
   * Budget d'arrêt tenu SOUS le délai de grâce.
   *
   * Railway envoie SIGKILL une trentaine de secondes après SIGTERM. L'ancien
   * budget (15 s de fermeture + 20 s de dépôt = 35 s) pouvait donc dépasser :
   * le conteneur était tué en plein téléversement d'archive, soit exactement
   * pendant l'écriture qu'on cherchait à sécuriser. On garde une marge.
   */
  try {
    await withTimeout(client.destroy(), 8000, 'fermeture de Chromium');
    console.log('[whatsapp] Chromium fermé proprement.');
  } catch (err) {
    console.warn('[whatsapp] fermeture de Chromium :', err.message);
  }

  // Profil au repos : la copie prise ici est la plus fiable de toutes.
  if (VAULT_ENABLED) {
    try {
      await withTimeout(
        sessionStore.snapshot({ api, dataPath: SESSION_PATH, enabled: VAULT_ENABLED, reason: 'arrêt' }),
        15000,
        'dépôt de session',
      );
    } catch (err) {
      console.warn('[wa-session] dépôt à l\'arrêt impossible :', err.message);
    }
  }

  // Code 1 pour un recyclage volontaire : Railway ne relance que sur échec
  // (restartPolicyType ON_FAILURE). Code 0 pour un arrêt demandé par la
  // plateforme — la relance, s'il y en a une, ne nous regarde pas.
  process.exit(exitCode);
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

// ── Démarrage ────────────────────────────────────────────────────────────────
process.on('unhandledRejection', (err) => console.warn('[whatsapp] unhandledRejection:', err?.message || err));

/**
 * Vrai quand on démarre avec une session déjà constituée (présente sur le
 * volume ou restaurée du coffre). Dans ce cas, un QR n'est pas une étape
 * normale du démarrage : c'est le signe que les credentials sont révoqués.
 */
let restoredFromStoredSession = false;

async function start() {
  reportSession('initializing');
  cleanupStaleLocks(SESSION_PATH); // retire les verrous Chromium périmés avant de démarrer

  /*
   * Reprendre la place AVANT Chromium, et avant toute restauration : c'est le
   * seul moment où le navigateur ne tient aucun fichier ouvert — sous Linux,
   * effacer un fichier qu'un processus tient ouvert ne rend pas un octet.
   *
   * Le volume était à 87 % le soir de l'incident d'envoi du 2026-08-09. Rien ne
   * le vidait jamais : ni les caches Chromium du profil vivant, ni les profils
   * écartés après une révocation isolée, ni le dossier d'une restauration
   * interrompue. Un volume plein, c'est un IndexedDB qui n'écrit plus — donc
   * une page WhatsApp Web qui échoue sans que rien n'en dise la raison.
   */
  sessionStore.reclaimSpace({ dataPath: SESSION_PATH, keepParked: KEEP_PARKED_PROFILES });
  logVolumeSpace('au démarrage');

  // AVANT d'initialiser le client : s'il n'y a pas de session exploitable sur
  // le volume, on la réclame au coffre. Une session locale valide n'est jamais
  // remplacée — le volume reste le chemin nominal.
  const outcome = await sessionStore.restoreIfMissing({ api, dataPath: SESSION_PATH, enabled: VAULT_ENABLED });

  restoredFromStoredSession = outcome === 'local' || outcome === 'restored';

  if (outcome === 'revoked') {
    // Le coffre ne contient que des credentials déjà révoqués : inutile de
    // laisser croire que le service « démarre ». On l'annonce tout de suite,
    // le QR reste servi sur /qr pour le ré-appairage.
    loggedOutForReal = true;
    await reportSession('logged_out', 'Coffre antérieur à la révocation WhatsApp — ré-appairage par QR nécessaire.');
  }

  client.initialize();
  loop();
}

start();
