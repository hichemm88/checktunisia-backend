'use strict';

/*
 * Tests de la politique de reprise après échec d'envoi.
 *
 * Ce qui est protégé ici n'est pas un confort d'exploitation : c'est le canal
 * de transmission légal du produit. La version d'origine transformait
 * n'importe quel échec — y compris une photo introuvable — en redémarrage du
 * conteneur, jusqu'à épuisement du quota Railway (10 crashs), après quoi le
 * service ne repartait plus du tout et la page /qr elle-même devenait
 * injoignable. Chaque tour de boucle envoyait en prime aux administrateurs un
 * email « session temporairement déconnectée » qui ne décrivait rien de réel.
 *
 * Exécution : `npm test` (node --test, aucune dépendance).
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const { classifyFailure, createRecovery, readRestarts, recordRestart, RESTART_LEDGER_FILE } = require('./recovery');

function tmpRoot() {
  return fs.mkdtempSync(path.join(os.tmpdir(), 'wa-recovery-'));
}

// ── Classification ───────────────────────────────────────────────────────────

test('une page WhatsApp Web muette est reconnue comme telle', () => {
  const pageErrors = [
    new Error('envoi 42 sans réponse après 120s'),
    new Error('Protocol error (Runtime.callFunctionOn): Target closed'),
    new Error('Execution context was destroyed, most likely because of a navigation'),
    new Error('Session closed. Most likely the page has been closed.'),
    new Error('Page crashed!'),
    new Error('Navigation timeout of 60000 ms exceeded'),
  ];

  for (const err of pageErrors) {
    assert.strictEqual(classifyFailure(err), 'page', err.message);
  }
});

test('une panne du backend ou du réseau n\'est jamais imputée au navigateur', () => {
  // Cause directe de l'incident : la photo est récupérée auprès de Laravel au
  // début de l'envoi. Un 404 (scan purgé), un 500 ou un backend en plein
  // redéploiement faisait échouer l'envoi — et redémarrer Chromium, qui n'y
  // était pour rien. Trois fiches suffisaient à tuer le conteneur.
  const backendErrors = [
    new Error('Request failed with status code 404'),
    new Error('Request failed with status code 502'),
    new Error('timeout of 20000ms exceeded'),
    new Error('socket hang up'),
    Object.assign(new Error('connect ECONNREFUSED 10.0.0.1:8000'), { code: 'ECONNREFUSED' }),
    Object.assign(new Error('getaddrinfo EAI_AGAIN backend.internal'), { code: 'EAI_AGAIN' }),
  ];

  for (const err of backendErrors) {
    assert.strictEqual(classifyFailure(err), 'backend', err.message);
  }
});

test('une erreur inconnue reste au niveau de la fiche — le doute ne redémarre pas', () => {
  // Le défaut penche volontairement du côté qui ne casse rien : la sonde de
  // vivacité (index.js, toutes les 60 s) détecte un vrai Chromium mort de
  // toute façon. Se tromper ici ne coûte qu'un peu de latence ; se tromper
  // dans l'autre sens coûtait le service entier.
  assert.strictEqual(classifyFailure(new Error('wid error: invalid wid')), 'job');
  assert.strictEqual(classifyFailure(new Error('Evaluation failed: t is not a function')), 'job');
  assert.strictEqual(classifyFailure(undefined), 'job');
  assert.strictEqual(classifyFailure('erreur sans objet'), 'job');
});

test("l'injection perdue est une panne de page, pas une fiche fautive", () => {
  // Constaté en production après un ré-appairage avec un AUTRE numéro : la page
  // vivait, mais window.WWebJS avait disparu. Classée 'job', la panne ne
  // recyclait jamais le worker et les fiches en attente échouaient en boucle
  // jusqu'à l'abandon à 24 h.
  const lostInjection = [
    new Error("Evaluation failed: TypeError: Cannot read properties of undefined (reading 'getChat')"),
    new Error("Cannot read properties of undefined (reading 'createWid')"),
    new Error("Cannot read properties of undefined (reading 'getMessageModel')"),
    new Error('Evaluation failed: ReferenceError: WWebJS is not defined'),
    new Error("Cannot read properties of undefined (reading 'Store')"),
  ];
  for (const err of lostInjection) {
    assert.strictEqual(classifyFailure(err), 'page', err.message);
  }

  // …sans élargir le filet : un déréférencement quelconque reste une fiche.
  assert.strictEqual(
    classifyFailure(new Error("Cannot read properties of undefined (reading 'caption')")),
    'job',
  );
});

// ── Escalade ─────────────────────────────────────────────────────────────────

test('des fiches qui échouent une à une ne provoquent jamais de redémarrage', () => {
  const dataPath = tmpRoot();
  const recovery = createRecovery({ dataPath, maxPageFailures: 3 });

  // Vingt fiches refusées d'affilée : la file Laravel gère leur backoff, le
  // worker ne se sabote pas pour autant.
  for (let i = 0; i < 20; i += 1) {
    const outcome = recovery.failure(new Error('wid error: invalid wid'));
    assert.strictEqual(outcome.decision, 'continue');
  }
});

test('un backend indisponible fait temporiser, pas redémarrer', () => {
  const dataPath = tmpRoot();
  const recovery = createRecovery({ dataPath, maxPageFailures: 3 });

  for (let i = 0; i < 10; i += 1) {
    assert.strictEqual(recovery.failure(new Error('Request failed with status code 500')).decision, 'backoff');
  }
});

test('une page muette déclenche un rechargement avant tout redémarrage', () => {
  const dataPath = tmpRoot();
  const recovery = createRecovery({ dataPath, maxPageFailures: 3, maxReloads: 2 });
  const dead = () => new Error('envoi 7 sans réponse après 120s');

  assert.strictEqual(recovery.failure(dead()).decision, 'continue');
  assert.strictEqual(recovery.failure(dead()).decision, 'continue');
  // Seuil atteint : recharger la page, geste gratuit pour le quota Railway.
  assert.strictEqual(recovery.failure(dead()).decision, 'reload');

  // Deuxième salve → deuxième rechargement.
  recovery.failure(dead());
  recovery.failure(dead());
  assert.strictEqual(recovery.failure(dead()).decision, 'reload');

  // Les rechargements n'ont rien donné : là seulement, on recycle.
  recovery.failure(dead());
  recovery.failure(dead());
  assert.strictEqual(recovery.failure(dead()).decision, 'restart');
});

test('un envoi réussi efface les compteurs — l\'escalade repart de zéro', () => {
  const dataPath = tmpRoot();
  const recovery = createRecovery({ dataPath, maxPageFailures: 3, maxReloads: 2 });
  const dead = () => new Error('Protocol error: Target closed');

  recovery.failure(dead());
  recovery.failure(dead());
  recovery.success();

  assert.strictEqual(recovery.failure(dead()).decision, 'continue');
  assert.strictEqual(recovery.failure(dead()).decision, 'continue');
  assert.strictEqual(recovery.failure(dead()).decision, 'reload');
});

// ── Frein à la boucle de redémarrages ────────────────────────────────────────

test('le budget de redémarrages est plafonné sur une fenêtre glissante', () => {
  const dataPath = tmpRoot();
  const t0 = Date.parse('2026-08-09T20:00:00Z');
  let now = t0;
  const recovery = createRecovery({
    dataPath,
    maxRestarts: 4,
    restartWindowMs: 3600000,
    clock: () => now,
  });

  assert.strictEqual(recovery.restartBudgetExhausted(), false);

  for (let i = 0; i < 4; i += 1) {
    recovery.noteRestart(`tour ${i}`);
    now += 60000; // un redémarrage toutes les minutes : la boucle infernale
  }

  // Quatre recyclages en quatre minutes : s'obstiner conduirait tout droit au
  // dixième crash, celui après lequel Railway arrête le service pour de bon.
  assert.strictEqual(recovery.restartBudgetExhausted(), true);

  // Une heure plus tard, les redémarrages sortent de la fenêtre : le worker
  // retrouve le droit de se réparer pour une panne nouvelle.
  now = t0 + 3600000 + 300000;
  assert.strictEqual(recovery.restartBudgetExhausted(), false);
});

test('le frein serre vraiment contre une boucle de démarrages ratés', () => {
  /*
   * Le piège : si la fenêtre n'est pas nettement plus large que le temps qu'il
   * faut pour consommer le budget, le premier recyclage sort de la fenêtre
   * juste à temps pour autoriser le suivant — et le worker redémarre pour
   * l'éternité à raison d'un budget par fenêtre. C'est exactement la boucle
   * qu'on prétend arrêter.
   *
   * Cas réel : échéance de mise en service à 15 min, budget de 4.
   */
  const dataPath = tmpRoot();
  const deadlineMs = 15 * 60000;
  let now = Date.parse('2026-08-10T08:00:00Z');
  const recovery = createRecovery({
    dataPath,
    maxRestarts: 4,
    restartWindowMs: 4 * 3600000,
    clock: () => now,
  });

  let restarts = 0;
  for (let i = 0; i < 12; i += 1) {
    now += deadlineMs; // le worker retombe en panne à chaque échéance
    if (recovery.restartBudgetExhausted()) break;
    recovery.noteRestart('session jamais prête');
    restarts += 1;
  }

  assert.strictEqual(restarts, 4, 'le budget doit être consommé, puis le frein serrer');
  assert.strictEqual(recovery.restartBudgetExhausted(), true, 'après quoi le worker se met en veille au lieu de s\'acharner');
});

test('le compteur de redémarrages survit au redémarrage qu\'il compte', () => {
  // Le point de tout l'exercice : un compteur en mémoire serait remis à zéro
  // par l'événement même qu'il doit borner. Il vit donc sur le volume.
  const dataPath = tmpRoot();
  const now = Date.parse('2026-08-09T22:03:00Z');

  recordRestart(dataPath, { windowMs: 3600000, now, reason: 'échecs consécutifs' });
  recordRestart(dataPath, { windowMs: 3600000, now: now + 1000, reason: 'échecs consécutifs' });

  // Nouveau processus : rien en mémoire, tout sur le disque.
  const relu = createRecovery({ dataPath, maxRestarts: 2, restartWindowMs: 3600000, clock: () => now + 2000 });
  assert.strictEqual(relu.restartsInWindow(), 2);
  assert.strictEqual(relu.restartBudgetExhausted(), true);
});

test('le journal des redémarrages se pose à côté du profil, jamais dedans', () => {
  // createArchive() n'archive que le sous-dossier « session » : un compteur
  // déposé à la racine ne part donc jamais dans le coffre, et ne peut pas
  // voyager d'une instance à l'autre au gré des restaurations.
  const dataPath = tmpRoot();
  recordRestart(dataPath, { windowMs: 3600000, now: Date.now() });

  assert.ok(fs.existsSync(path.join(dataPath, RESTART_LEDGER_FILE)));
  assert.ok(!fs.existsSync(path.join(dataPath, 'session', RESTART_LEDGER_FILE)));
});

test('un journal illisible rend le budget entier plutôt que de paralyser le worker', () => {
  const dataPath = tmpRoot();
  fs.writeFileSync(path.join(dataPath, RESTART_LEDGER_FILE), '{ ceci n\'est pas du JSON');

  assert.deepStrictEqual(readRestarts(dataPath, { windowMs: 3600000 }), []);
  assert.strictEqual(createRecovery({ dataPath, maxRestarts: 1 }).restartBudgetExhausted(), false);
});

test('les entrées hors fenêtre sont oubliées et le journal ne grossit pas sans fin', () => {
  const dataPath = tmpRoot();
  const now = Date.parse('2026-08-09T22:00:00Z');

  for (let i = 0; i < 80; i += 1) {
    recordRestart(dataPath, { windowMs: 3600000, now: now + i * 1000 });
  }

  const stored = JSON.parse(fs.readFileSync(path.join(dataPath, RESTART_LEDGER_FILE), 'utf8'));
  assert.ok(stored.restarts.length <= 50, 'le journal est borné');

  // Deux heures plus tard, plus rien ne compte.
  assert.deepStrictEqual(readRestarts(dataPath, { windowMs: 3600000, now: now + 7200000 }), []);
});
