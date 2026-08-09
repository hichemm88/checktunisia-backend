'use strict';

/*
 * Tests de la reprise de place sur le volume.
 *
 * LE CONSTAT — le 2026-08-09 au soir, le volume Railway du worker (500 Mo,
 * plafond du plan) était à 87 %, le soir même où les envois se sont mis à
 * échouer. Rien ne le vidait jamais :
 *
 *   • les caches Chromium DANS le profil vivant sont exclus de l'archive parce
 *     qu'ils sont reconstructibles — mais personne ne les effaçait du disque ;
 *   • les profils écartés (~100 Mo pièce) n'étaient purgés qu'au moment d'en
 *     créer un nouveau : après une révocation isolée, ils restaient à demeure ;
 *   • une restauration dont toutes les tentatives échouent laissait son dossier
 *     de travail derrière elle.
 *
 * Un volume plein, c'est Chromium qui n'écrit plus son IndexedDB — donc des
 * envois qui échouent sans que rien n'en dise la cause.
 *
 * CE QUE CES TESTS PROTÈGENT — que la reprise de place ne touche JAMAIS aux
 * credentials. Gagner des mégaoctets en perdant la session serait un très
 * mauvais échange : il faudrait aller re-scanner un QR sur le téléphone.
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

const silent = { log() {}, warn() {}, error() {} };

function tmpRoot() {
  return fs.mkdtempSync(path.join(os.tmpdir(), 'wa-volume-'));
}

/** Profil appairé, avec des caches volumineux comme en production. */
function makeProfile(dataPath, name = 'session') {
  const profile = path.join(dataPath, name, 'Default');

  for (const dir of ['IndexedDB', 'Local Storage', 'Cache', 'Code Cache', 'Service Worker/CacheStorage']) {
    fs.mkdirSync(path.join(profile, dir), { recursive: true });
  }

  fs.writeFileSync(path.join(profile, 'IndexedDB', 'creds.ldb'), crypto.randomBytes(4096));
  fs.writeFileSync(path.join(profile, 'Local Storage', 'leveldb.log'), crypto.randomBytes(2048));
  fs.writeFileSync(path.join(profile, 'Preferences'), '{"factice":true}');

  // Les caches : le terme dominant de l'occupation du volume.
  fs.writeFileSync(path.join(profile, 'Cache', 'gros'), crypto.randomBytes(300000));
  fs.writeFileSync(path.join(profile, 'Code Cache', 'gros'), crypto.randomBytes(200000));
  fs.writeFileSync(path.join(profile, 'Service Worker/CacheStorage', 'gros'), crypto.randomBytes(200000));

  if (name === 'session') store.markPaired(dataPath);
}

// ── La règle qui prime sur toutes les autres ─────────────────────────────────

test('la reprise de place ne touche jamais aux credentials', () => {
  const dataPath = tmpRoot();
  makeProfile(dataPath);

  const creds = path.join(dataPath, 'session', 'Default', 'IndexedDB', 'creds.ldb');
  const local = path.join(dataPath, 'session', 'Default', 'Local Storage', 'leveldb.log');
  const before = fs.readFileSync(creds);

  store.reclaimSpace({ dataPath, log: silent });

  assert.deepStrictEqual(fs.readFileSync(creds), before, 'IndexedDB doit être intact, octet pour octet');
  assert.ok(fs.existsSync(local), 'Local Storage doit rester');
  assert.ok(fs.existsSync(path.join(dataPath, 'session', 'Default', 'Preferences')));

  // Et la session reste exploitable : c'est la seule chose qui compte vraiment.
  assert.strictEqual(store.localSessionState(dataPath).usable, true);
});

test('les caches du profil vivant sont bien rendus', () => {
  const dataPath = tmpRoot();
  makeProfile(dataPath);

  const { freedBytes } = store.reclaimSpace({ dataPath, log: silent });

  assert.ok(freedBytes >= 700000, `au moins les 700 ko de caches, obtenu ${freedBytes}`);
  assert.ok(!fs.existsSync(path.join(dataPath, 'session', 'Default', 'Cache')));
  assert.ok(!fs.existsSync(path.join(dataPath, 'session', 'Default', 'Code Cache')));
  assert.ok(!fs.existsSync(path.join(dataPath, 'session', 'Default', 'Service Worker', 'CacheStorage')));
});

test('un profil sans cache ne libère rien et ne casse rien', () => {
  const dataPath = tmpRoot();
  fs.mkdirSync(path.join(dataPath, 'session', 'Default', 'IndexedDB'), { recursive: true });

  const { freedBytes, removed } = store.reclaimSpace({ dataPath, log: silent });

  assert.strictEqual(freedBytes, 0);
  assert.deepStrictEqual(removed, []);
});

test('un dossier de session absent n\'est pas une erreur', () => {
  const { freedBytes } = store.reclaimSpace({ dataPath: path.join(tmpRoot(), 'jamais-monté'), log: silent });

  assert.strictEqual(freedBytes, 0);
});

// ── Profils écartés ──────────────────────────────────────────────────────────

test('les profils écartés en trop sont purgés, le plus récent est gardé', () => {
  const dataPath = tmpRoot();
  makeProfile(dataPath);
  makeProfile(dataPath, 'session.revoked-1000000000000');
  makeProfile(dataPath, 'session.revoked-2000000000000');
  makeProfile(dataPath, 'session.revoked-3000000000000');

  store.reclaimSpace({ dataPath, keepParked: 1, log: silent });

  // Le plus récent survit — l'analyse d'incident garde de quoi travailler.
  assert.ok(fs.existsSync(path.join(dataPath, 'session.revoked-3000000000000')));
  assert.ok(!fs.existsSync(path.join(dataPath, 'session.revoked-2000000000000')));
  assert.ok(!fs.existsSync(path.join(dataPath, 'session.revoked-1000000000000')));
});

test('le profil écarté conservé perd quand même ses caches', () => {
  // ~100 Mo par profil sur un volume de 500 : garder un profil pour l'analyse
  // ne justifie pas de garder aussi ses caches, qui n'apprennent rien.
  const dataPath = tmpRoot();
  makeProfile(dataPath);
  makeProfile(dataPath, 'session.revoked-3000000000000');

  store.reclaimSpace({ dataPath, keepParked: 1, log: silent });

  const kept = path.join(dataPath, 'session.revoked-3000000000000', 'Default');
  assert.ok(!fs.existsSync(path.join(kept, 'Cache')), 'les caches du profil gardé partent');
  assert.ok(fs.existsSync(path.join(kept, 'IndexedDB', 'creds.ldb')), 'ses credentials restent lisibles');
});

test('un profil orphelin compte comme un profil écarté', () => {
  // `session.orphan` est posé par une restauration : rien ne le reprenait
  // jamais, il occupait donc ~100 Mo indéfiniment.
  const dataPath = tmpRoot();
  makeProfile(dataPath);
  makeProfile(dataPath, 'session.orphan');
  makeProfile(dataPath, 'session.revoked-3000000000000');

  store.reclaimSpace({ dataPath, keepParked: 1, log: silent });

  const survivants = store.parkedProfiles(dataPath);
  assert.strictEqual(survivants.length, 1, `un seul profil écarté doit rester, trouvé : ${survivants}`);
  assert.strictEqual(store.localSessionState(dataPath).usable, true, 'le profil vivant est indemne');
});

test('keepParked: 0 rend toute la place des profils écartés', () => {
  const dataPath = tmpRoot();
  makeProfile(dataPath);
  makeProfile(dataPath, 'session.revoked-3000000000000');

  store.reclaimSpace({ dataPath, keepParked: 0, log: silent });

  assert.deepStrictEqual(store.parkedProfiles(dataPath), []);
  assert.strictEqual(store.localSessionState(dataPath).usable, true);
});

// ── Dossier de travail d'une restauration ────────────────────────────────────

test('le dossier d\'une restauration interrompue est repris', () => {
  const dataPath = tmpRoot();
  makeProfile(dataPath);
  const staging = path.join(dataPath, '.restore-staging', 'session', 'Default');
  fs.mkdirSync(staging, { recursive: true });
  fs.writeFileSync(path.join(staging, 'partiel'), crypto.randomBytes(150000));

  const { freedBytes } = store.reclaimSpace({ dataPath, log: silent });

  assert.ok(!fs.existsSync(path.join(dataPath, '.restore-staging')));
  assert.ok(freedBytes >= 150000);
});

// ── Mesure de la place ───────────────────────────────────────────────────────

test('la place du volume est mesurée, ou honnêtement absente', () => {
  const space = store.volumeSpace(tmpRoot());

  // `fs.statfsSync` existe depuis Node 18.15 ; s'il manque, on renvoie null
  // plutôt qu'un chiffre inventé.
  if (space === null) return;

  assert.ok(space.totalBytes > 0);
  assert.ok(space.freeBytes >= 0);
  assert.ok(space.usedRatio >= 0 && space.usedRatio <= 1);
});

test('un chemin inexistant ne fait pas tomber la mesure', () => {
  assert.strictEqual(store.volumeSpace('/chemin/qui/n/existe/pas/du/tout'), null);
});
