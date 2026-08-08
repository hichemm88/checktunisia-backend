'use strict';

/*
 * Persistance durable de la session WhatsApp Web.
 *
 * LE PROBLÈME — la session (profil Chromium appairé au téléphone) ne vivait
 * que sur le volume Railway de ce service. Un volume est attaché à UNE
 * instance : il survit à un redéploiement ordinaire, mais pas à une recréation
 * du service, à une migration, ni à une fausse manœuvre. Et le seul moyen de
 * reconstituer la session est de re-scanner un QR sur place — c'est-à-dire une
 * coupure du canal de transmission légal du produit.
 *
 * LA SOLUTION — le volume reste le chemin rapide (aucun changement au
 * démarrage nominal), et une copie chiffrée part dans le stockage objet DÉJÀ
 * utilisé pour les sauvegardes de base, via le backend Laravel qui détient
 * seul les clés. Au démarrage, on ne réclame cette copie que si le disque
 * local n'a PAS de session exploitable.
 *
 * RÈGLES, dans l'ordre d'importance :
 *
 *   1. Ne JAMAIS supprimer une session locale existante. Aucune fonction de ce
 *      fichier n'efface quoi que ce soit dans le dossier de session.
 *   2. Ne JAMAIS déposer une archive issue d'un dossier sans session valide :
 *      c'est ainsi qu'un worker démarré sans volume écraserait les credentials.
 *   3. Ne JAMAIS journaliser le contenu de la session. Les traces ne portent
 *      que des noms de fichiers de l'archive temporaire, des tailles et des
 *      empreintes.
 *   4. Un échec du coffre n'empêche jamais le worker de démarrer. La session
 *      locale, si elle existe, prime toujours.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { spawn } = require('child_process');

/*
 * Caches volumineux et reconstructibles, exclus de l'archive. Même liste que
 * celle qu'utilise la stratégie RemoteAuth de whatsapp-web.js : ces dossiers
 * pèsent l'essentiel du profil, changent en permanence (donc s'archivent mal à
 * chaud) et WhatsApp Web les reconstruit tout seul.
 *
 * ⚠️ IndexedDB et Local Storage ne sont PAS exclus : c'est là que vivent les
 * credentials. Les exclure produirait une archive qui restaure un profil vide.
 */
const EXCLUDED = [
  'Default/Cache',
  'Default/Code Cache',
  'Default/GPUCache',
  'Default/DawnCache',
  'Default/DawnGraphiteCache',
  'Default/DawnWebGPUCache',
  'Default/Service Worker/CacheStorage',
  'Default/Service Worker/ScriptCache',
  'Default/Shared Dictionary',
  'GrShaderCache',
  'ShaderCache',
  'GraphiteDawnCache',
  'component_crx_cache',
  'extensions_crx_cache',
];

/** Fichiers de verrou Chromium : jamais archivés, ils bloqueraient la restauration. */
const EXCLUDED_GLOBS = ['Singleton*', '*.lock', 'lockfile'];

/*
 * Marqueur d'appairage, posé par le worker quand WhatsApp confirme la session.
 *
 * Il répond à un piège constaté en production le 2026-08-08 : un démarrage qui
 * affiche un QR fabrique lui aussi un profil Chromium complet, avec IndexedDB
 * et Local Storage — 112 Mo de fichiers parfaitement formés, mais appairés à
 * personne. La seule présence des magasins ne distingue donc PAS une session
 * valide d'un profil né d'un QR jamais scanné.
 *
 * Sans marqueur, ce profil fantôme passerait pour une session et masquerait la
 * copie saine du coffre à chaque démarrage suivant.
 *
 * Ne contient aucun secret : un horodatage, rien d'autre.
 */
const PAIRED_MARKER = '.qayed-paired.json';

/*
 * Marqueur de RÉVOCATION, posé quand WhatsApp a réellement invalidé l'appareil
 * lié (événement « LOGOUT »).
 *
 * Il répond au piège constaté le 2026-08-08 : le marqueur d'appairage prouve
 * qu'un profil a UN JOUR été accepté, pas qu'il l'est ENCORE. Après une
 * révocation côté WhatsApp, le profil restait donc « exploitable » aux yeux du
 * worker — qui le restaurait du coffre à chaque démarrage, se voyait refuser,
 * affichait un QR, et recommençait indéfiniment en se déclarant « en cours
 * d'initialisation ». Les fiches s'accumulaient sans que rien ne signale qu'un
 * ré-appairage humain était devenu indispensable.
 *
 * Un profil révoqué n'est plus « usable » : il n'est ni déposé dans le coffre
 * (on n'écrase pas la dernière archive avec des credentials morts) ni considéré
 * comme une session au démarrage.
 */
const REVOKED_MARKER = '.qayed-revoked.json';

/**
 * État de la session sur le disque local.
 *
 * `usable` est la seule chose qui compte, et elle est volontairement stricte :
 * l'existence du dossier ne prouve rien (un point de montage de volume vide
 * existe aussi). Ce qui prouve un appairage, c'est la présence des magasins
 * où WhatsApp Web écrit ses credentials.
 *
 * @returns {{root:string, exists:boolean, usable:boolean, stores:string[], bytes:number}}
 */
function localSessionState(dataPath, { clientId } = {}) {
  const root = path.join(dataPath, clientId ? `session-${clientId}` : 'session');
  const profile = path.join(root, 'Default');

  const exists = safeIsDir(root);
  const stores = ['IndexedDB', 'Local Storage'].filter((s) => safeIsDir(path.join(profile, s)));
  const paired = safeIsFile(path.join(root, PAIRED_MARKER));
  const revoked = safeIsFile(path.join(root, REVOKED_MARKER));

  return {
    root,
    exists,
    stores,
    paired,
    revoked,
    // Les deux magasins, le marqueur d'appairage, ET l'absence de révocation.
    //  • Les magasins seuls ne prouvent rien : un profil né d'un QR jamais
    //    scanné les possède aussi.
    //  • Le marqueur d'appairage seul ne prouve rien non plus : il dit qu'on a
    //    été appairé un jour, pas qu'on l'est encore. Sans la condition de
    //    révocation, un profil que WhatsApp a invalidé continuait de passer
    //    pour une session valide, indéfiniment.
    usable: exists && stores.length === 2 && paired && !revoked,
    bytes: exists ? directoryBytes(root) : 0,
  };
}

/**
 * Marque le profil comme révoqué par WhatsApp et retire le marqueur
 * d'appairage : à partir de là, plus rien ne le prend pour une session valide.
 *
 * @returns {boolean} true si le marqueur a pu être posé
 */
function markRevoked(dataPath, { clientId, reason = 'LOGOUT' } = {}) {
  const root = path.join(dataPath, clientId ? `session-${clientId}` : 'session');

  try {
    fs.mkdirSync(root, { recursive: true });
    fs.writeFileSync(
      path.join(root, REVOKED_MARKER),
      JSON.stringify({ revoked_at: new Date().toISOString(), reason: String(reason).slice(0, 200) }),
    );
    // Retirer l'appairage est ce qui rend la révocation effective même si le
    // marqueur de révocation venait à disparaître d'une archive plus ancienne.
    try { fs.rmSync(path.join(root, PAIRED_MARKER), { force: true }); } catch { /* au mieux */ }

    return true;
  } catch {
    return false;
  }
}

/**
 * Pose le marqueur d'appairage. Appelé quand WhatsApp confirme la session
 * (« ready »), jamais avant : c'est ce qui donne au marqueur sa valeur.
 */
function markPaired(dataPath, { clientId } = {}) {
  const root = path.join(dataPath, clientId ? `session-${clientId}` : 'session');

  try {
    fs.mkdirSync(root, { recursive: true });
    // Horodatage seul — aucun identifiant, aucun secret.
    fs.writeFileSync(path.join(root, PAIRED_MARKER), JSON.stringify({ paired_at: new Date().toISOString() }));

    /*
     * Lever la révocation fait PARTIE de l'appairage.
     *
     * Constaté en production le 2026-08-08 à 15:54 : le QR venait d'être
     * scanné, la session était « prête »… et le dépôt au coffre a été refusé
     * — « aucune session locale exploitable ». Le marqueur de révocation posé
     * pendant l'incident était toujours là, et rendait `usable` faux malgré
     * un appairage tout neuf. La session réparée n'aurait JAMAIS été archivée,
     * et la panne suivante serait repartie d'une archive morte.
     *
     * WhatsApp vient de confirmer la session : c'est la seule preuve qui
     * compte, et elle prime sur toute révocation antérieure.
     */
    fs.rmSync(path.join(root, REVOKED_MARKER), { force: true });

    return true;
  } catch (err) {
    return false;
  }
}

/**
 * Faut-il réclamer la session au coffre ?
 * Uniquement quand le disque local n'a rien d'exploitable — une session locale
 * valide n'est jamais remplacée par celle du coffre, qui peut être plus vieille.
 */
function shouldRestore(state) {
  return !state.usable;
}

/**
 * Faut-il déposer la session dans le coffre ?
 * Jamais sans session locale exploitable : c'est LA garantie que des
 * credentials valides ne peuvent pas être écrasés par un démarrage à vide.
 */
function shouldSnapshot(state) {
  return state.usable;
}

/**
 * Le blocage au démarrage doit-il effacer la session locale ?
 *
 * NON, par défaut — et c'est un changement de comportement délibéré. L'ancien
 * watchdog vidait le dossier de session dès que ni QR ni connexion n'arrivaient
 * sous 120 s. Or un démarrage à froid derrière un volume réseau dépasse
 * régulièrement ce délai : le worker détruisait lui-même une session parfaitement
 * valide, puis affichait un QR. C'était la cause des « déconnexions à chaque
 * déploiement ». Un redémarrage simple suffit ; effacer ne se justifie que sur
 * décision humaine, via WHATSAPP_ALLOW_SESSION_WIPE=1.
 */
function shouldWipeOnStartupTimeout(env = process.env) {
  return String(env.WHATSAPP_ALLOW_SESSION_WIPE || '') === '1';
}

// ── Archive ──────────────────────────────────────────────────────────────────

/**
 * Fabrique un tar.gz du dossier de session, caches exclus.
 * @returns {Promise<{path:string, bytes:number, sha256:string}>}
 */
async function createArchive(dataPath, state, { tmpDir = os.tmpdir() } = {}) {
  const out = path.join(tmpDir, `wa-session-${process.pid}-${Date.now()}.tar.gz`);
  const sessionDir = path.basename(state.root);

  // Aucun chemin absolu dans les arguments : on se place dans le dossier et on
  // écrit sur la sortie standard. Sous Windows (tests), GNU tar prendrait un
  // « C:\… » pour un hôte distant et échouerait.
  const args = ['-czf', '-'];
  for (const dir of EXCLUDED) args.push('--exclude', `${sessionDir}/${dir}`);
  for (const glob of EXCLUDED_GLOBS) args.push('--exclude', glob);
  args.push(sessionDir);

  // Code 1 accepté : le profil est VIVANT, Chromium y écrit pendant qu'on lit.
  // tar signale ces fichiers en avertissement — refuser l'archive pour cela
  // reviendrait à ne jamais réussir un dépôt à chaud.
  await pipeTar(args, { cwd: dataPath, stdout: fs.createWriteStream(out), acceptExitCodes: [0, 1] });

  const bytes = fs.statSync(out).size;

  return { path: out, bytes, sha256: await sha256File(out) };
}

/**
 * Extrait une archive dans le dossier de session.
 *
 * N'efface rien au préalable : tar écrase les entrées présentes dans l'archive
 * et laisse le reste. On n'appelle de toute façon cette fonction que lorsqu'il
 * n'y a pas de session exploitable.
 */
async function extractArchive(archivePath, dataPath) {
  fs.mkdirSync(dataPath, { recursive: true });
  await pipeTar(['-xzf', '-'], { cwd: dataPath, stdin: fs.createReadStream(archivePath) });
}

// ── Coffre (via le backend Laravel) ──────────────────────────────────────────

/**
 * Réclame la session au coffre si le disque local n'a rien d'exploitable.
 *
 * Ne lève jamais : un coffre injoignable (backend en plein redéploiement, ce
 * qui est précisément le cas au moment où l'on démarre) ne doit pas empêcher
 * le worker de tourner avec ce qu'il a.
 *
 * @returns {Promise<'local'|'restored'|'empty'|'unavailable'|'disabled'|'revoked'>}
 */
async function restoreIfMissing({ api, dataPath, log = console, enabled = true, attempts = 3, delayMs = 5000, sleep = defaultSleep, tmpDir = os.tmpdir() }) {
  const state = localSessionState(dataPath);

  if (!shouldRestore(state)) {
    log.log(`[wa-session] session locale présente (${state.stores.join(', ')}, ${mb(state.bytes)}) — le coffre n'est pas sollicité.`);
    return 'local';
  }

  if (!enabled) {
    log.warn('[wa-session] coffre désactivé : démarrage sans session (un QR sera demandé).');
    return 'disabled';
  }

  /*
   * Restaurer une archive que WhatsApp a déjà révoquée ne sert à RIEN — et
   * c'est exactement la boucle observée le 2026-08-08 : à chaque démarrage le
   * worker remettait en place des credentials morts, WhatsApp les refusait, un
   * QR s'affichait, et le système se déclarait « en cours d'initialisation »
   * pendant que les fiches s'empilaient. Le backend, lui, sait DEPUIS QUAND la
   * session est révoquée : si l'archive lui est antérieure, on ne la restaure
   * pas et on le dit franchement.
   */
  const revocation = await revocationState(api, log);
  if (revocation.revoked_at && revocation.stored_at && revocation.stored_at <= revocation.revoked_at) {
    log.warn(`[wa-session] l'archive du coffre (${revocation.stored_at}) est antérieure à la révocation WhatsApp (${revocation.revoked_at}) — restauration inutile, un ré-appairage par QR est nécessaire.`);
    return 'revoked';
  }

  log.log('[wa-session] aucune session locale exploitable — tentative de restauration depuis le coffre…');

  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    try {
      const res = await api.get('/internal/whatsapp/session-archive', { responseType: 'arraybuffer' });
      const archive = path.join(tmpDir, `wa-session-restore-${process.pid}.tar.gz`);
      fs.writeFileSync(archive, Buffer.from(res.data));

      /*
       * Extraire D'ABORD à l'écart, valider, et seulement ensuite prendre la
       * place. L'ordre inverse — écarter le profil puis extraire par-dessus —
       * touche au disque avant de savoir si le remplaçant vaut quelque chose,
       * et une archive décevante laisse alors les lieux en désordre.
       *
       * On n'extrait jamais par-dessus un profil existant : tar écrase les
       * entrées de l'archive et laisse le reste, ce qui produirait un hybride
       * de deux profils Chromium — le cas où l'on perdrait les deux.
       */
      const staging = path.join(dataPath, '.restore-staging');
      fs.rmSync(staging, { recursive: true, force: true });

      try {
        await extractArchive(archive, staging);
      } finally {
        safeUnlink(archive);
      }

      const restored = localSessionState(staging);

      if (!restored.usable) {
        // Archive présente mais sans session appairée : on n'y touche pas et
        // on le dit. Le disque local n'a pas bougé d'un octet.
        fs.rmSync(staging, { recursive: true, force: true });
        log.warn('[wa-session] le coffre ne contient pas de session appairée — un QR sera demandé, le disque local est intact.');
        return 'empty';
      }

      // Le remplaçant est prouvé bon : on peut maintenant écarter l'ancien.
      // Écarter, pas supprimer — et remettre en place si le basculement rate.
      const orphan = `${state.root}.orphan`;
      let setAside = false;

      if (state.exists) {
        try {
          fs.rmSync(orphan, { recursive: true, force: true });
          fs.renameSync(state.root, orphan);
          setAside = true;
          log.warn(`[wa-session] profil local non appairé écarté sous « ${path.basename(orphan)} » (conservé, non supprimé).`);
        } catch (err) {
          fs.rmSync(staging, { recursive: true, force: true });
          log.warn(`[wa-session] impossible d'écarter le profil local : ${err.message} — restauration abandonnée, rien n'est touché.`);
          return 'unavailable';
        }
      }

      try {
        fs.renameSync(restored.root, state.root);
      } catch (err) {
        if (setAside) {
          try { fs.renameSync(orphan, state.root); } catch { /* au mieux */ }
        }
        fs.rmSync(staging, { recursive: true, force: true });
        throw err;
      }

      fs.rmSync(staging, { recursive: true, force: true });

      const after = localSessionState(dataPath);

      log.log(`[wa-session] session restaurée depuis le coffre (${mb(after.bytes)}) — aucun QR ne devrait être demandé.`);
      return 'restored';
    } catch (err) {
      if (err?.response?.status === 404) {
        log.log('[wa-session] coffre vide (premier appairage) — un QR sera demandé.');
        return 'empty';
      }

      log.warn(`[wa-session] restauration impossible (essai ${attempt}/${attempts}) : ${err.message}`);

      if (attempt < attempts) await sleep(delayMs);
    }
  }

  return 'unavailable';
}

/**
 * Dépose la session courante dans le coffre.
 *
 * @returns {Promise<'stored'|'skipped'|'failed'|'disabled'>}
 */
async function snapshot({ api, dataPath, log = console, enabled = true, reason = 'périodique', tmpDir = os.tmpdir() }) {
  if (!enabled) return 'disabled';

  const state = localSessionState(dataPath);

  if (!shouldSnapshot(state)) {
    // Refus le plus important du fichier : sans lui, un worker démarré sans
    // volume déposerait une archive vide par-dessus des credentials valides.
    log.warn('[wa-session] aucune session locale exploitable — dépôt REFUSÉ (le coffre n\'est pas écrasé).');
    return 'skipped';
  }

  let archive = null;

  try {
    archive = await createArchive(dataPath, state, { tmpDir });

    // FormData/Blob natifs (Node ≥ 18) plutôt qu'une dépendance de plus ;
    // axios v1 les sérialise et pose l'en-tête multipart tout seul.
    const form = new FormData();
    form.append(
      'archive',
      new Blob([fs.readFileSync(archive.path)], { type: 'application/gzip' }),
      'session.tar.gz',
    );
    form.append('sha256', archive.sha256);

    await api.post('/internal/whatsapp/session-archive', form, {
      maxBodyLength: Infinity,
      maxContentLength: Infinity,
      timeout: 120000,
    });

    // Taille et empreinte seulement — jamais un octet du contenu.
    log.log(`[wa-session] session déposée dans le coffre (${reason}, ${mb(archive.bytes)}, sha256 ${archive.sha256.slice(0, 12)}…).`);
    return 'stored';
  } catch (err) {
    // Un dépôt raté n'est pas une panne : la session locale est intacte et le
    // coffre garde la version précédente.
    log.warn(`[wa-session] dépôt impossible (${reason}) : ${err?.response?.status || ''} ${err.message}`);
    return 'failed';
  } finally {
    if (archive) safeUnlink(archive.path);
  }
}

/**
 * Date de révocation connue du backend et date de l'archive du coffre.
 *
 * Ne lève jamais : si le backend est injoignable (il redéploie souvent au même
 * moment que nous), on préfère TENTER la restauration plutôt que refuser une
 * session peut-être parfaitement valide.
 *
 * @returns {Promise<{revoked_at:?string, stored_at:?string}>}
 */
async function revocationState(api, log = console) {
  try {
    const meta = (await api.get('/internal/whatsapp/session-archive/meta')).data.data;

    return { revoked_at: meta?.revoked_at ?? null, stored_at: meta?.stored_at ?? null };
  } catch (err) {
    log.warn(`[wa-session] état de révocation indisponible (${err.message}) — on tente la restauration.`);

    return { revoked_at: null, stored_at: null };
  }
}

// ── Utilitaires ──────────────────────────────────────────────────────────────

/**
 * Lance tar en flux (stdin/stdout), sans jamais lui passer de chemin absolu.
 * Le message d'erreur ne reprend que la sortie d'erreur de tar — des noms de
 * fichiers, jamais leur contenu.
 */
function pipeTar(args, { cwd, stdin = null, stdout = null, acceptExitCodes = [0] }) {
  return new Promise((resolve, reject) => {
    const child = spawn('tar', args, { cwd, stdio: [stdin ? 'pipe' : 'ignore', stdout ? 'pipe' : 'ignore', 'pipe'] });

    let stderr = '';
    child.stderr.on('data', (chunk) => { stderr += chunk.toString(); });

    let writeDone = Promise.resolve();

    if (stdin) stdin.pipe(child.stdin);

    if (stdout) {
      child.stdout.pipe(stdout);
      writeDone = new Promise((res, rej) => {
        stdout.on('finish', res);
        stdout.on('error', rej);
      });
    }

    child.on('error', reject);
    child.on('close', (code) => {
      if (!acceptExitCodes.includes(code)) {
        reject(new Error(`tar a échoué (code ${code}) : ${stderr.slice(0, 300)}`));
        return;
      }
      writeDone.then(resolve, reject);
    });
  });
}

function sha256File(file) {
  return new Promise((resolve, reject) => {
    const hash = crypto.createHash('sha256');
    const stream = fs.createReadStream(file);
    stream.on('error', reject);
    stream.on('data', (chunk) => hash.update(chunk));
    stream.on('end', () => resolve(hash.digest('hex')));
  });
}

function safeIsDir(p) {
  try { return fs.statSync(p).isDirectory(); } catch { return false; }
}

function safeIsFile(p) {
  try { return fs.statSync(p).isFile(); } catch { return false; }
}

function safeUnlink(p) {
  try { fs.rmSync(p, { force: true }); } catch { /* le fichier temporaire disparaîtra avec le conteneur */ }
}

function directoryBytes(dir) {
  let total = 0;
  try {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) total += directoryBytes(full);
      else if (entry.isFile()) { try { total += fs.statSync(full).size; } catch { /* course avec Chromium */ } }
    }
  } catch { /* dossier illisible : la taille n'est qu'indicative */ }
  return total;
}

function mb(bytes) {
  return `${(bytes / 1048576).toFixed(1)} Mo`;
}

function defaultSleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

module.exports = {
  EXCLUDED,
  PAIRED_MARKER,
  REVOKED_MARKER,
  localSessionState,
  markPaired,
  markRevoked,
  revocationState,
  shouldRestore,
  shouldSnapshot,
  shouldWipeOnStartupTimeout,
  createArchive,
  extractArchive,
  restoreIfMissing,
  snapshot,
};
