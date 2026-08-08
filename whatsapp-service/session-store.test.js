'use strict';

/*
 * Tests de résilience de la session WhatsApp.
 *
 * Ce que ces tests protègent n'est pas une fonctionnalité, c'est un secret
 * irremplaçable : la session appairée. La perdre impose d'aller re-scanner un
 * QR sur le téléphone émetteur — donc une coupure du canal de transmission
 * légal du produit.
 *
 * Aucune vraie session WhatsApp n'est utilisée ici : les profils sont des
 * arborescences factices contenant des octets aléatoires.
 *
 * Exécution : `npm test` (node --test, aucune dépendance).
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');

const store = require('./session-store');

// ── Utilitaires de banc d'essai ──────────────────────────────────────────────

function tmpRoot(label) {
  return fs.mkdtempSync(path.join(os.tmpdir(), `wa-test-${label}-`));
}

/** Profil Chromium factice ayant la forme d'une session appairée. */
function makePairedSession(dataPath) {
  const profile = path.join(dataPath, 'session', 'Default');

  for (const dir of ['IndexedDB', 'Local Storage', 'Cache', 'Service Worker/CacheStorage']) {
    fs.mkdirSync(path.join(profile, dir), { recursive: true });
  }

  // Contenu factice : ce qui compte est qu'il soit non trivial et identifiable.
  fs.writeFileSync(path.join(profile, 'IndexedDB', 'creds.ldb'), crypto.randomBytes(4096));
  fs.writeFileSync(path.join(profile, 'Local Storage', 'leveldb.log'), crypto.randomBytes(2048));
  fs.writeFileSync(path.join(profile, 'Preferences'), '{"factice":true}');

  // Caches volumineux — doivent être exclus de l'archive.
  fs.writeFileSync(path.join(profile, 'Cache', 'gros'), crypto.randomBytes(200000));
  fs.writeFileSync(path.join(profile, 'Service Worker/CacheStorage', 'gros'), crypto.randomBytes(200000));

  // Verrou Chromium — ne doit jamais être archivé.
  fs.writeFileSync(path.join(dataPath, 'session', 'SingletonLock'), 'verrou');

  // Marqueur posé par le worker sur « ready » : ce profil est appairé.
  store.markPaired(dataPath);
}

/**
 * Profil né d'un QR jamais scanné : Chromium a tout écrit (IndexedDB, Local
 * Storage, des dizaines de Mo), mais la session n'est appairée à personne.
 * Constaté en production le 2026-08-08.
 */
function makeUnpairedProfile(dataPath) {
  const profile = path.join(dataPath, 'session', 'Default');

  for (const dir of ['IndexedDB', 'Local Storage']) {
    fs.mkdirSync(path.join(profile, dir), { recursive: true });
  }

  fs.writeFileSync(path.join(profile, 'IndexedDB', 'vide.ldb'), crypto.randomBytes(4096));
  fs.writeFileSync(path.join(profile, 'Local Storage', 'leveldb.log'), crypto.randomBytes(2048));
  // Aucun appel à markPaired : c'est toute la différence.
}

/** Point de montage de volume vide : le piège qui a coûté la session. */
function makeEmptyMount(dataPath) {
  fs.mkdirSync(path.join(dataPath, 'session'), { recursive: true });
}

const silent = { log() {}, warn() {}, error() {} };

/** Journal capturant tout ce qui est écrit, pour vérifier l'absence de fuite. */
function capturingLog() {
  const lines = [];
  const push = (...args) => lines.push(args.map(String).join(' '));
  return { lines, log: push, warn: push, error: push };
}

/** Coffre en mémoire imitant les routes /internal/whatsapp/session-archive. */
function fakeVault({ contents = null } = {}) {
  const vault = {
    contents,
    posts: 0,
    // Date de révocation connue du backend, et date de l'archive : c'est la
    // comparaison des deux qui décide si restaurer a encore un sens.
    revokedAt: null,
    storedAt: null,
    api: {
      async get(url) {
        assert.match(url, /session-archive/);
        if (url.endsWith('/meta')) {
          return { data: { data: { revoked_at: vault.revokedAt, stored_at: vault.storedAt } } };
        }
        if (vault.contents === null) {
          const err = new Error('Request failed with status code 404');
          err.response = { status: 404 };
          throw err;
        }
        return { data: vault.contents };
      },
      async post(url, form) {
        assert.match(url, /session-archive/);
        vault.posts += 1;
        const blob = form.get('archive');
        vault.contents = Buffer.from(await blob.arrayBuffer());
        vault.sha256 = form.get('sha256');
        return { data: { data: { bytes: vault.contents.length } } };
      },
    },
  };
  return vault;
}

// ── Détection d'une session exploitable ──────────────────────────────────────

test('un point de montage vide n\'est PAS pris pour une session', () => {
  const dir = tmpRoot('vide');
  makeEmptyMount(dir);

  const state = store.localSessionState(dir);

  // Tout repose là-dessus : si un volume vide passait pour une session, le
  // worker déposerait ce vide par-dessus les credentials du coffre.
  assert.equal(state.exists, true);
  assert.equal(state.usable, false);
  assert.equal(store.shouldSnapshot(state), false);
  assert.equal(store.shouldRestore(state), true);
});

test('un profil né d\'un QR jamais scanné n\'est PAS pris pour une session', () => {
  const dir = tmpRoot('non-appaire');
  makeUnpairedProfile(dir);

  const state = store.localSessionState(dir);

  // Le piège de production : les magasins sont là, complets, et pourtant la
  // session n'existe pas. Sans le marqueur, ce profil masquerait la copie
  // saine du coffre à chaque démarrage.
  assert.deepEqual(state.stores, ['IndexedDB', 'Local Storage']);
  assert.equal(state.paired, false);
  assert.equal(state.usable, false);
  assert.equal(store.shouldSnapshot(state), false, 'un profil non appairé ne doit jamais partir au coffre');
  assert.equal(store.shouldRestore(state), true);
});

test('le marqueur d\'appairage ne contient aucun secret', () => {
  const dir = tmpRoot('marqueur');
  fs.mkdirSync(path.join(dir, 'session'), { recursive: true });
  store.markPaired(dir);

  const contenu = JSON.parse(fs.readFileSync(path.join(dir, 'session', store.PAIRED_MARKER), 'utf8'));

  assert.deepEqual(Object.keys(contenu), ['paired_at']);
  assert.ok(!Number.isNaN(Date.parse(contenu.paired_at)));
});

test('un profil non appairé est ÉCARTÉ, pas supprimé, avant restauration', async () => {
  const origine = tmpRoot('orphelin-origine');
  makePairedSession(origine);
  const attendu = fs.readFileSync(path.join(origine, 'session', 'Default', 'IndexedDB', 'creds.ldb'));

  const vault = fakeVault();
  await store.snapshot({ api: vault.api, dataPath: origine, log: silent });

  // Instance qui a affiché un QR : un profil complet mais orphelin traîne.
  const cible = tmpRoot('orphelin-cible');
  makeUnpairedProfile(cible);
  const orphelinAvant = fs.readFileSync(path.join(cible, 'session', 'Default', 'IndexedDB', 'vide.ldb'));

  const outcome = await store.restoreIfMissing({ api: vault.api, dataPath: cible, log: silent });

  assert.equal(outcome, 'restored');
  assert.equal(store.localSessionState(cible).usable, true);
  assert.deepEqual(
    fs.readFileSync(path.join(cible, 'session', 'Default', 'IndexedDB', 'creds.ldb')),
    attendu,
    'la session du coffre doit avoir remplacé le profil orphelin',
  );
  // Aucun fichier hybride : le profil orphelin n'a pas été mélangé au restauré.
  assert.ok(!fs.existsSync(path.join(cible, 'session', 'Default', 'IndexedDB', 'vide.ldb')));
  // Et il est conservé à côté, pas détruit.
  assert.deepEqual(
    fs.readFileSync(path.join(cible, 'session.orphan', 'Default', 'IndexedDB', 'vide.ldb')),
    orphelinAvant,
    'le profil écarté doit rester récupérable',
  );
});

test('une archive sans session appairée laisse le disque local INTACT', async () => {
  // Cas rencontré en production : le coffre contenait un profil fantôme,
  // déposé avant que le marqueur d'appairage n'existe.
  const fantome = tmpRoot('coffre-fantome');
  makeUnpairedProfile(fantome);

  // On fabrique une archive du fantôme en contournant le refus de dépôt.
  const state = store.localSessionState(fantome);
  const archive = await store.createArchive(fantome, { ...state, root: path.join(fantome, 'session') });
  const vault = fakeVault({ contents: fs.readFileSync(archive.path) });

  const local = tmpRoot('local-intact');
  makeUnpairedProfile(local);
  const avant = fs.readFileSync(path.join(local, 'session', 'Default', 'IndexedDB', 'vide.ldb'));

  const outcome = await store.restoreIfMissing({ api: vault.api, dataPath: local, log: silent });

  assert.equal(outcome, 'empty');
  // Rien n'a bougé : ni écarté, ni remplacé, ni mélangé.
  assert.deepEqual(fs.readFileSync(path.join(local, 'session', 'Default', 'IndexedDB', 'vide.ldb')), avant);
  assert.ok(!fs.existsSync(path.join(local, 'session.orphan')), 'aucun profil ne doit être écarté pour rien');
  assert.ok(!fs.existsSync(path.join(local, '.restore-staging')), 'la zone de transit doit être nettoyée');
});

test('un profil appairé est reconnu comme exploitable', () => {
  const dir = tmpRoot('appairee');
  makePairedSession(dir);

  const state = store.localSessionState(dir);

  assert.equal(state.usable, true);
  assert.deepEqual(state.stores, ['IndexedDB', 'Local Storage']);
  assert.equal(store.shouldSnapshot(state), true);
  assert.equal(store.shouldRestore(state), false);
});

// ── 1. Une session existante n'est pas réinitialisée au démarrage ────────────

test('au démarrage, une session existante n\'est ni effacée ni remplacée par le coffre', async () => {
  const dir = tmpRoot('demarrage');
  makePairedSession(dir);

  const creds = path.join(dir, 'session', 'Default', 'IndexedDB', 'creds.ldb');
  const before = fs.readFileSync(creds);

  // Le coffre contient une archive DIFFÉRENTE (plus ancienne) : elle ne doit
  // pas écraser la session locale.
  const vault = fakeVault({ contents: Buffer.from('archive-plus-ancienne') });

  const outcome = await store.restoreIfMissing({ api: vault.api, dataPath: dir, log: silent });

  assert.equal(outcome, 'local');
  assert.deepEqual(fs.readFileSync(creds), before, 'les credentials locaux ont été modifiés');
});

// ── 2. Un redémarrage / une recréation retrouve la session persistée ─────────

test('après perte totale du disque, la session est retrouvée depuis le coffre', async () => {
  const origine = tmpRoot('origine');
  makePairedSession(origine);

  const attendu = fs.readFileSync(path.join(origine, 'session', 'Default', 'IndexedDB', 'creds.ldb'));

  const vault = fakeVault();
  assert.equal(await store.snapshot({ api: vault.api, dataPath: origine, log: silent }), 'stored');

  // Instance recréée par Railway : nouveau conteneur, nouveau volume, rien.
  const neuf = tmpRoot('recree');

  const outcome = await store.restoreIfMissing({ api: vault.api, dataPath: neuf, log: silent });

  assert.equal(outcome, 'restored');
  assert.equal(store.localSessionState(neuf).usable, true);
  assert.deepEqual(
    fs.readFileSync(path.join(neuf, 'session', 'Default', 'IndexedDB', 'creds.ldb')),
    attendu,
    'les credentials restaurés diffèrent de ceux archivés',
  );
});

test('l\'archive exclut les caches et les verrous, pas les magasins de credentials', async () => {
  const origine = tmpRoot('exclusions');
  makePairedSession(origine);

  const vault = fakeVault();
  await store.snapshot({ api: vault.api, dataPath: origine, log: silent });

  const cible = tmpRoot('exclusions-cible');
  const archive = path.join(cible, 'a.tar.gz');
  fs.writeFileSync(archive, vault.contents);
  await store.extractArchive(archive, cible);

  const profile = path.join(cible, 'session', 'Default');

  assert.ok(fs.existsSync(path.join(profile, 'IndexedDB', 'creds.ldb')), 'IndexedDB doit être archivé');
  assert.ok(fs.existsSync(path.join(profile, 'Local Storage', 'leveldb.log')), 'Local Storage doit être archivé');
  assert.ok(!fs.existsSync(path.join(profile, 'Cache', 'gros')), 'le cache ne doit pas être archivé');
  assert.ok(!fs.existsSync(path.join(cible, 'session', 'SingletonLock')), 'un verrou archivé empêcherait Chromium de démarrer');
});

// ── 3. Les credentials ne sont jamais écrasés par une session vide ───────────

test('un worker démarré sans volume ne peut pas écraser le coffre', async () => {
  const vault = fakeVault({ contents: Buffer.from('credentials-valides-en-coffre') });

  const vide = tmpRoot('sans-volume');
  makeEmptyMount(vide);

  const outcome = await store.snapshot({ api: vault.api, dataPath: vide, log: silent });

  assert.equal(outcome, 'skipped');
  assert.equal(vault.posts, 0, 'aucun dépôt ne doit partir');
  assert.deepEqual(vault.contents, Buffer.from('credentials-valides-en-coffre'), 'le coffre a été écrasé');
});

test('un dossier de session totalement absent ne déclenche aucun dépôt', async () => {
  const vault = fakeVault({ contents: Buffer.from('credentials-valides-en-coffre') });

  const outcome = await store.snapshot({ api: vault.api, dataPath: tmpRoot('inexistant'), log: silent });

  assert.equal(outcome, 'skipped');
  assert.equal(vault.posts, 0);
});

// ── 4. Un démarrage normal ne déclenche pas de nouveau QR ────────────────────

test('un blocage au démarrage n\'efface plus la session', () => {
  // La régression d'origine : le watchdog vidait le dossier au bout de 120 s,
  // et un démarrage à froid derrière un volume dépasse régulièrement ce délai.
  assert.equal(store.shouldWipeOnStartupTimeout({}), false);
  assert.equal(store.shouldWipeOnStartupTimeout({ WHATSAPP_ALLOW_SESSION_WIPE: '0' }), false);
  assert.equal(store.shouldWipeOnStartupTimeout({ WHATSAPP_ALLOW_SESSION_WIPE: 'true' }), false);

  // Effacer reste possible, mais seulement sur décision humaine explicite.
  assert.equal(store.shouldWipeOnStartupTimeout({ WHATSAPP_ALLOW_SESSION_WIPE: '1' }), true);
});

test('un coffre injoignable ne détruit rien et laisse le worker démarrer', async () => {
  const dir = tmpRoot('coffre-mort');
  makePairedSession(dir);

  const api = { async get() { throw new Error('ECONNREFUSED'); } };

  const outcome = await store.restoreIfMissing({ api, dataPath: dir, log: silent });

  assert.equal(outcome, 'local', 'la session locale doit primer sans même appeler le coffre');
  assert.equal(store.localSessionState(dir).usable, true);
});

test('sans session locale ET sans coffre joignable, rien n\'est effacé', async () => {
  const dir = tmpRoot('rien');
  makeEmptyMount(dir);

  const api = { async get() { throw new Error('ECONNREFUSED'); } };

  const outcome = await store.restoreIfMissing({
    api, dataPath: dir, log: silent, attempts: 2, delayMs: 0, sleep: async () => {},
  });

  assert.equal(outcome, 'unavailable');
  assert.ok(fs.existsSync(path.join(dir, 'session')), 'le dossier ne doit pas avoir été supprimé');
});

// ── 5. Aucun secret dans les journaux ────────────────────────────────────────

test('les journaux ne contiennent jamais le contenu de la session', async () => {
  const dir = tmpRoot('journaux');

  // Marqueur reconnaissable joué au rôle de credential.
  const profile = path.join(dir, 'session', 'Default');
  fs.mkdirSync(path.join(profile, 'IndexedDB'), { recursive: true });
  fs.mkdirSync(path.join(profile, 'Local Storage'), { recursive: true });
  const secret = 'SECRET-DE-SESSION-NE-DOIT-JAMAIS-APPARAITRE';
  fs.writeFileSync(path.join(profile, 'IndexedDB', 'creds.ldb'), secret.repeat(200));
  fs.writeFileSync(path.join(profile, 'Local Storage', 'leveldb.log'), secret.repeat(200));

  const vault = fakeVault();
  const log = capturingLog();

  await store.snapshot({ api: vault.api, dataPath: dir, log });

  const neuf = tmpRoot('journaux-restaure');
  await store.restoreIfMissing({ api: vault.api, dataPath: neuf, log });

  const tout = log.lines.join('\n');

  assert.ok(log.lines.length > 0, 'le test ne prouve rien si rien n\'a été journalisé');
  assert.ok(!tout.includes(secret), 'un fragment de session est apparu dans les journaux');
  // L'empreinte sert au diagnostic ; seul son préfixe est tracé, et une
  // empreinte SHA-256 ne révèle rien du contenu.
  assert.ok(!tout.includes(vault.sha256), 'l\'empreinte complète n\'a pas à être journalisée');
});

test('un échec de dépôt est signalé sans divulguer la session', async () => {
  const dir = tmpRoot('echec');
  makePairedSession(dir);

  const api = { async post() { throw new Error('HTTP 413 Payload Too Large'); } };
  const log = capturingLog();

  const outcome = await store.snapshot({ api, dataPath: dir, log });

  assert.equal(outcome, 'failed');
  assert.ok(log.lines.join('\n').includes('dépôt impossible'));
});

// ── Révocation WhatsApp (incident du 2026-08-08) ─────────────────────────────
//
// Le marqueur d'appairage prouve qu'un profil a UN JOUR été accepté, pas qu'il
// l'est ENCORE. Après un LOGOUT, le profil restait donc « exploitable » : le
// worker le restaurait du coffre à chaque démarrage, WhatsApp le refusait, un
// QR s'affichait, et le système se déclarait « en cours d'initialisation »
// pendant que les fiches s'accumulaient — sans que rien ne signale qu'un
// ré-appairage humain était devenu indispensable.

test('un profil révoqué par WhatsApp cesse d\'être pris pour une session valide', () => {
  const dir = tmpRoot('revoque');
  makePairedSession(dir);

  assert.equal(store.localSessionState(dir).usable, true, 'départ : session valide');

  store.markRevoked(dir, { reason: 'LOGOUT' });
  const state = store.localSessionState(dir);

  assert.equal(state.revoked, true);
  assert.equal(state.paired, false, 'la révocation retire l\'appairage');
  assert.equal(state.usable, false);
});

test('un profil révoqué n\'écrase JAMAIS la dernière archive du coffre', async () => {
  const dir = tmpRoot('revoque-depot');
  makePairedSession(dir);

  const vault = fakeVault();
  await store.snapshot({ api: vault.api, dataPath: dir, log: silent, reason: 'bonne session' });
  const bonneArchive = vault.contents;

  assert.equal(vault.posts, 1);
  assert.ok(bonneArchive && bonneArchive.length > 0);

  // WhatsApp révoque l'appareil, puis un arrêt déclenche un dépôt.
  store.markRevoked(dir, { reason: 'LOGOUT' });
  const verdict = await store.snapshot({ api: vault.api, dataPath: dir, log: silent, reason: 'arrêt' });

  assert.equal(verdict, 'skipped');
  assert.equal(vault.posts, 1, 'aucun second dépôt');
  assert.equal(vault.contents, bonneArchive, 'l\'archive saine est intacte');
});

test('une archive antérieure à la révocation n\'est pas restaurée en boucle', async () => {
  const dir = tmpRoot('restore-revoque');
  makeEmptyMount(dir); // rien d'exploitable en local

  const vault = fakeVault({ contents: Buffer.from('archive-morte') });
  vault.storedAt = '2026-08-08T10:00:00+00:00';
  vault.revokedAt = '2026-08-08T12:30:00+00:00'; // révoquée APRÈS l'archive

  const log = capturingLog();
  const verdict = await store.restoreIfMissing({ api: vault.api, dataPath: dir, log, attempts: 1 });

  assert.equal(verdict, 'revoked');
  assert.match(log.lines.join('\n'), /ré-appairage par QR/);
  // Et surtout : le disque n'a pas été touché.
  assert.equal(store.localSessionState(dir).usable, false);
});

test('une archive POSTÉRIEURE à la révocation est bien restaurée', async () => {
  const source = tmpRoot('source-neuve');
  makePairedSession(source);

  const vault = fakeVault();
  await store.snapshot({ api: vault.api, dataPath: source, log: silent });

  const cible = tmpRoot('cible-neuve');
  makeEmptyMount(cible);
  vault.revokedAt = '2026-08-08T12:30:00+00:00';
  vault.storedAt = '2026-08-08T14:00:00+00:00'; // ré-appairage postérieur

  const verdict = await store.restoreIfMissing({ api: vault.api, dataPath: cible, log: silent, attempts: 1 });

  assert.equal(verdict, 'restored');
  assert.equal(store.localSessionState(cible).usable, true);
});

test('un backend injoignable ne bloque pas la restauration', async () => {
  const source = tmpRoot('source-repli');
  makePairedSession(source);

  const vault = fakeVault();
  await store.snapshot({ api: vault.api, dataPath: source, log: silent });

  // Le backend redéploie souvent au moment même où le worker démarre : ne pas
  // pouvoir lire l'état de révocation ne doit pas refuser une session peut-être
  // parfaitement valide.
  const cible = tmpRoot('cible-repli');
  makeEmptyMount(cible);
  const api = {
    get: async (url) => {
      if (url.endsWith('/meta')) throw new Error('backend indisponible');
      return vault.api.get(url);
    },
    post: vault.api.post,
  };

  const verdict = await store.restoreIfMissing({ api, dataPath: cible, log: silent, attempts: 1 });

  assert.equal(verdict, 'restored');
});

test('le marqueur de révocation ne contient ni secret ni identifiant', () => {
  const dir = tmpRoot('revoque-contenu');
  makePairedSession(dir);
  store.markRevoked(dir, { reason: 'LOGOUT' });

  const brut = fs.readFileSync(path.join(dir, 'session', store.REVOKED_MARKER), 'utf8');
  const contenu = JSON.parse(brut);

  assert.deepEqual(Object.keys(contenu).sort(), ['reason', 'revoked_at']);
  assert.equal(contenu.reason, 'LOGOUT');
  assert.doesNotMatch(brut, /token|secret|creds|\d{8,}/i);
});

/*
 * Ce test existait déjà mais retirait le marqueur de révocation À LA MAIN
 * avant d'appeler markPaired() : il validait l'état final souhaité sans jamais
 * exercer le chemin réel. Il est passé au vert pendant que la production
 * refusait d'archiver une session fraîchement réparée (2026-08-08, 15:54).
 * Il n'appelle plus que markPaired(), comme le worker sur « ready ».
 */
test('un nouvel appairage lève la révocation, sans intervention', () => {
  const dir = tmpRoot('reappairage');
  makePairedSession(dir);
  store.markRevoked(dir, { reason: 'LOGOUT' });

  assert.equal(store.localSessionState(dir).usable, false);

  // Le QR est scanné : c'est TOUT ce que fait le worker sur « ready ».
  store.markPaired(dir);

  const state = store.localSessionState(dir);
  assert.equal(state.revoked, false, 'le marqueur de révocation doit être levé par l\'appairage');
  assert.equal(state.paired, true);
  assert.equal(state.usable, true);
});

test('une session réparée est bien déposée au coffre', async () => {
  const dir = tmpRoot('reappairage-depot');
  makePairedSession(dir);
  store.markRevoked(dir, { reason: 'LOGOUT' });

  const vault = fakeVault();

  // Pendant la panne : aucun dépôt, l'archive éventuelle est protégée.
  assert.equal(await store.snapshot({ api: vault.api, dataPath: dir, log: silent }), 'skipped');

  // Après le scan du QR : le dépôt DOIT repartir. Sans cela, la session
  // réparée n'aurait jamais de copie durable et la panne suivante repartirait
  // d'une archive morte — exactement ce qui s'est produit en production.
  store.markPaired(dir);

  assert.equal(await store.snapshot({ api: vault.api, dataPath: dir, log: silent }), 'stored');
  assert.equal(vault.posts, 1);
});
