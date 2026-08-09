'use strict';

/*
 * MODULE PROVISOIRE — à retirer après homologation MI.
 *
 * Politique de reprise du worker WhatsApp après un échec d'envoi.
 *
 * ── Le problème que ce fichier existe pour régler ────────────────────────────
 *
 * L'auto-réparation d'origine tenait en trois lignes : trois échecs d'envoi
 * consécutifs, quels qu'ils soient → `process.exit(1)`, Railway relance le
 * conteneur. L'intention était bonne (un Chromium pendu ne se répare que par un
 * navigateur neuf), l'application beaucoup moins :
 *
 *  1. TOUT échec comptait. Une photo que le backend ne rend pas (404, 500,
 *     backend en plein redéploiement), un destinataire mal formé, une fiche
 *     refusée — autant de pannes que le redémarrage ne répare PAS. Le worker
 *     redémarrait, reprenait les mêmes fiches, échouait de nouveau, redémarrait.
 *  2. Rien ne bornait ces redémarrages. `restartPolicyMaxRetries: 10` (voir
 *     railway.json) : au dixième, Railway arrête le service pour de bon. La page
 *     /qr devient elle aussi injoignable — impossible même de ré-appairer.
 *  3. `process.exit(1)` coupait Node avec Chromium encore ouvert, en pleine
 *     écriture sur le profil LocalAuth. C'est exactement le scénario que le
 *     gestionnaire SIGTERM prend soin d'éviter (voir index.js) : le redémarrage
 *     « de réparation » abîmait la session qu'il prétendait préserver.
 *  4. Chaque cycle s'annonçait « session temporairement déconnectée » aux
 *     administrateurs. Un recyclage volontaire du navigateur n'est pas une perte
 *     de session : c'était une fausse alerte, répétée à chaque tour de boucle.
 *
 * ── Ce que la politique garantit ─────────────────────────────────────────────
 *
 *  • On ne redémarre QUE pour les pannes qu'un navigateur neuf répare (classe
 *    « page »). Le reste est laissé à la file Laravel, qui sait déjà réessayer.
 *  • Le doute profite à la file : une erreur non reconnue est « job », jamais
 *    « page ». Un vrai Chromium mort reste détecté par la sonde de vivacité
 *    (index.js, toutes les 60 s) — la classification n'a pas besoin d'être
 *    agressive pour que la panne soit vue.
 *  • Le rechargement de page passe avant le redémarrage : un renderer neuf sans
 *    consommer le budget de crashs Railway.
 *  • Le nombre de redémarrages est plafonné sur une fenêtre glissante,
 *    persistée sur le volume (donc elle survit précisément à ce qu'elle
 *    compte). Budget épuisé → le worker reste EN VIE et se met en veille : il
 *    cesse d'envoyer, le dit une fois, et laisse /qr, /health et /debug
 *    joignables.
 */

const fs = require('fs');
const path = require('path');

/*
 * Erreurs venant du backend Laravel ou du réseau : récupération de la photo,
 * indisponibilité passagère, DNS. Redémarrer Chromium n'y change rien.
 */
const BACKEND_SIGNATURES = [
  /request failed with status code/i,
  /timeout of \d+ms exceeded/i,
  /socket hang up/i,
  /network error/i,
  /\b(ECONNREFUSED|ECONNRESET|ENOTFOUND|ETIMEDOUT|EAI_AGAIN|EPIPE|ERR_BAD_RESPONSE|ERR_BAD_REQUEST)\b/,
];

/*
 * Signatures d'une page WhatsApp Web qui ne répond plus. Ce sont les seules qui
 * justifient de recycler le navigateur.
 *
 * « sans réponse après » est le libellé de withTimeout() dans index.js : quand
 * un envoi dépasse le délai dur, c'est que la page n'a rendu aucun verdict —
 * ni succès ni erreur. C'est la signature type du renderer pendu.
 */
const PAGE_SIGNATURES = [
  /sans réponse après/i,
  /protocol error/i,
  /runtime\.callfunctionon/i,
  /target closed/i,
  /session closed/i,
  /execution context was destroyed/i,
  /detached frame/i,
  /page crashed/i,
  /websocket is not open/i,
  /connection closed/i,
  /navigation timeout/i,
  /browser has disconnected/i,
];

/**
 * À quelle famille appartient cet échec d'envoi ?
 *
 * @returns {'page'|'backend'|'job'} 'page' = navigateur à recycler ;
 *   'backend' = Laravel/réseau, on temporise ; 'job' = cette fiche-là, la file
 *   Laravel s'en occupe (backoff, abandon à 24 h). Défaut délibéré : 'job'.
 */
function classifyFailure(error) {
  const message = String(error?.message ?? error ?? '');
  const code = String(error?.code ?? '');
  const haystack = `${message} ${code}`;

  // Une réponse HTTP du backend est une preuve directe : l'appel réseau a
  // abouti, le navigateur n'est pas en cause. On teste cette famille d'abord.
  if (BACKEND_SIGNATURES.some((re) => re.test(haystack))) return 'backend';
  if (PAGE_SIGNATURES.some((re) => re.test(haystack))) return 'page';

  return 'job';
}

/*
 * Journal des redémarrages volontaires, à la racine du dossier de session.
 *
 * Posé à côté du profil (et non dedans) : createArchive() n'archive que le
 * sous-dossier « session », ce compteur ne part donc jamais dans le coffre.
 * Il vit sur le volume Railway, seul endroit qui survit à un redémarrage —
 * un compteur en mémoire serait remis à zéro par ce qu'il est censé compter.
 */
const RESTART_LEDGER_FILE = '.qayed-restarts.json';

/** @returns {string[]} horodatages ISO des redémarrages encore dans la fenêtre. */
function readRestarts(dataPath, { windowMs, now = Date.now() }) {
  try {
    const raw = fs.readFileSync(path.join(dataPath, RESTART_LEDGER_FILE), 'utf8');
    const parsed = JSON.parse(raw);
    const list = Array.isArray(parsed?.restarts) ? parsed.restarts : [];

    return list
      .filter((entry) => {
        const at = Date.parse(entry?.at ?? entry);

        return Number.isFinite(at) && now - at < windowMs;
      })
      .map((entry) => entry?.at ?? entry);
  } catch {
    // Absent, illisible ou corrompu : on repart d'un budget entier. Un compteur
    // qu'on n'arrive pas à lire ne doit pas paralyser le worker.
    return [];
  }
}

/** Ajoute un redémarrage au journal et renvoie le nombre retenu dans la fenêtre. */
function recordRestart(dataPath, { windowMs, now = Date.now(), reason = null }) {
  const kept = readRestarts(dataPath, { windowMs, now })
    .map((at) => ({ at }))
    .concat([{ at: new Date(now).toISOString(), reason }]);

  try {
    fs.mkdirSync(dataPath, { recursive: true });
    fs.writeFileSync(
      path.join(dataPath, RESTART_LEDGER_FILE),
      JSON.stringify({ restarts: kept.slice(-50) }),
    );
  } catch {
    // Volume en lecture seule ou plein : on préfère un redémarrage non compté à
    // un worker qui refuse de se réparer.
  }

  return kept.length;
}

/**
 * Suivi des échecs d'envoi et décision de reprise.
 *
 * Décisions possibles :
 *  • 'continue' — enchaîner sur la fiche suivante ;
 *  • 'backoff'  — attendre avant de reprendre (panne backend/réseau) ;
 *  • 'reload'   — recharger la page WhatsApp Web (renderer neuf) ;
 *  • 'restart'  — recycler le conteneur (Chromium neuf).
 *
 * Le franchissement du plafond de redémarrages ne se décide pas ici mais dans
 * `restartBudgetExhausted()` : la sonde de vivacité, le watchdog de démarrage
 * et la boucle d'envoi passent tous par le même frein.
 */
function createRecovery({
  dataPath,
  maxPageFailures = 3,
  maxReloads = 2,
  maxRestarts = 4,
  restartWindowMs = 3600000,
  clock = Date.now,
} = {}) {
  let pageFailures = 0;
  let reloads = 0;

  return {
    /** Un envoi a abouti : la page répond, tout repart de zéro. */
    success() {
      pageFailures = 0;
      reloads = 0;
    },

    /** @returns {{kind:string, decision:string, pageFailures:number}} */
    failure(error) {
      const kind = classifyFailure(error);

      if (kind === 'backend') return { kind, decision: 'backoff', pageFailures };

      // Une fiche qui échoue proprement prouve au contraire que la page tourne :
      // le backoff par fiche vit côté Laravel, le worker enchaîne.
      if (kind === 'job') return { kind, decision: 'continue', pageFailures };

      pageFailures += 1;
      if (pageFailures < maxPageFailures) {
        return { kind, decision: 'continue', pageFailures };
      }

      // Seuil atteint : on agit, et on redonne au navigateur un compteur neuf
      // pour juger de l'effet de l'action.
      const reached = pageFailures;
      pageFailures = 0;

      if (reloads < maxReloads) {
        reloads += 1;

        return { kind, decision: 'reload', pageFailures: reached, reloads };
      }

      return { kind, decision: 'restart', pageFailures: reached };
    },

    /**
     * Le budget de redémarrages de la fenêtre est-il épuisé ? Vrai → il faut
     * cesser de redémarrer : le problème n'est pas de ceux qu'un navigateur
     * neuf répare, et s'obstiner ferait tomber le service pour de bon.
     */
    restartBudgetExhausted(now = clock()) {
      return readRestarts(dataPath, { windowMs: restartWindowMs, now }).length >= maxRestarts;
    },

    /** Consigne un redémarrage volontaire. @returns {number} total dans la fenêtre. */
    noteRestart(reason, now = clock()) {
      return recordRestart(dataPath, { windowMs: restartWindowMs, now, reason });
    },

    /** Redémarrages retenus dans la fenêtre (diagnostic /health). */
    restartsInWindow(now = clock()) {
      return readRestarts(dataPath, { windowMs: restartWindowMs, now }).length;
    },
  };
}

module.exports = {
  BACKEND_SIGNATURES,
  PAGE_SIGNATURES,
  RESTART_LEDGER_FILE,
  classifyFailure,
  createRecovery,
  readRestarts,
  recordRestart,
};
