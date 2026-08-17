<?php

use App\Services\Observability\SchedulerHeartbeat;
use App\Services\Webauthn\ChallengeStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// Notify hotel admins about expiring subscriptions — runs daily at 8:00 AM
Schedule::command('subscriptions:notify-expiring')->dailyAt('08:00');

// Sync watchlist with OpenSanctions (Interpol Red Notices + UN Sanctions) — runs daily at 02:00 AM
// OpenSanctions refreshes their data daily; running at 02:00 ensures we pick up overnight updates.
Schedule::command('watchlist:sync-opensanctions')->dailyAt('02:00');

// Auto-expire subscriptions past their expiry date, blocking check-ins until renewed
Schedule::command('subscriptions:expire-overdue')->dailyAt('03:00');

// Downgrades programmés : appliqués une fois la période payée écoulée, AVANT
// la génération des factures de renouvellement (07:00) pour que le
// renouvellement parte sur le nouveau plan, jamais sur l'ancien.
Schedule::command('subscriptions:apply-plan-changes')->dailyAt('03:30');

// Chantier A2 — facturation automatique : émission des factures de
// renouvellement à J-7 de l'échéance, puis relances impayé J+3/7/14 et
// suspension à J+21. La génération tourne avant les relances.
Schedule::command('invoices:generate-due')->dailyAt('07:00');
Schedule::command('invoices:dunning')->dailyAt('07:30');

// Grille V2 — clôture mensuelle des quotas de check-ins : calcul des tranches
// de dépassement du mois écoulé, facturation automatique (comptes non-legacy
// uniquement) et détection des candidats upsell (2 mois consécutifs).
// Idempotente — relançable manuellement : php artisan quota:close-month --month=YYYY-MM
Schedule::command('quota:close-month')->monthlyOn(1, '04:00');

// Notify managers of check-ins left unvalidated for >30 min (scan done, not finalised)
Schedule::command('checkins:notify-pending')->everyTenMinutes()->withoutOverlapping();

// Remind staff about active stays due to depart today with no check-out — 14:00 Tunis (§8)
Schedule::command('checkins:notify-departures-due')->dailyAt('14:00')->timezone('Africa/Tunis');

// Récapitulatif quotidien des fiches de police, tous établissements du
// destinataire, envoyé par email pendant que le relais WhatsApp est hors
// service (numéro émetteur restreint par Meta le 17/08/2026).
//
// Inerte sans POLICE_DIGEST_RECIPIENT, et la commande s'éteint d'elle-même
// après POLICE_DIGEST_UNTIL — un envoi quotidien de pièces d'identité ne doit
// pas survivre à la raison qui l'a motivé faute qu'on ait pensé à l'arrêter.
//
// L'heure est configurable : la retransmission manuelle aux autorités dépend de
// la disponibilité d'une personne, pas d'une contrainte technique.
Schedule::command('police:daily-digest')
    ->dailyAt((string) config('police_digest.hour', '17:00'))
    ->timezone('Africa/Tunis')
    ->withoutOverlapping(30);

// MODULE PROVISOIRE — relais WhatsApp : purge horaire des images de documents
// au-delà de la rétention (24 h). Minimisation des données.
Schedule::command('whatsapp:purge-images')->hourly()->withoutOverlapping();

// Transmission par le canal PUSH (Cloud API). Inerte tant que le canal actif
// est en pull : le worker Node réclame alors les fiches lui-même. Planifiée dès
// maintenant pour que la bascule ne tienne qu'à une variable d'environnement,
// sans qu'il faille aussi penser à armer un ordonnancement ce jour-là.
// Verrou borné à 15 min : une exécution cadencée peut durer plusieurs minutes
// (10 fiches à 45 s), mais un processus tué en cours ne doit pas bloquer la
// file jusqu'au lendemain — le défaut de Laravel est de 24 h.
Schedule::command('whatsapp:push')->everyMinute()->withoutOverlapping(15);

// Challenges WebAuthn périmés. Ils sont déjà inutilisables passé leur
// expiration (et purgés au fil de l'eau à chaque émission) : ce passage
// quotidien évite simplement que la table enfle sur une longue période creuse.
Schedule::call(fn () => app(ChallengeStore::class)->pruneExpired())
    ->name('webauthn-prune-challenges')
    ->dailyAt('04:30');

// Rapport « prochain check-in » à la demande : inerte tant que non armé
// (Cache 'checkin_report.watch'), s'auto-désarme après envoi.
Schedule::command('checkins:report-next')->everyMinute()->withoutOverlapping();

// Pas de worker de file dédié en prod (le conteneur ne lance que serve +
// scheduler) : on draine la file Redis chaque minute. --stop-when-empty pour
// sortir dès qu'il n'y a plus rien ; --max-time borne la durée sous la minute.
// Corrige aussi les push notifications (jobs qui s'accumulaient sans worker).
// Conditionné par QUEUE_DRAIN_VIA_SCHEDULER (défaut : true = comportement
// historique, rien ne change tant que la variable n'est pas posée).
// Passer à false dès qu'un service worker dédié tourne (docker/worker.sh) :
// les deux peuvent coexister sans corruption, mais c'est du CPU gaspillé.
if (config('features.queue_drain_via_scheduler')) {
    Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')
        ->everyMinute()
        ->withoutOverlapping();
}

// Sauvegarde chiffrée hors Railway. Railway ne fournit ni sauvegarde native ni
// PITR sur le plan actuel : c'est la SEULE protection du registre.
//
// HORAIRE plutôt que quotidienne à heure fixe, à dessein. Un `dailyAt('03:30')`
// n'a qu'une seule minute par jour pour se déclencher : une exécution manquée
// coûtait une journée entière de registre. Ici la commande tourne toutes les
// heures et décide elle-même si le travail est dû (dernière réussite datant de
// plus de backup.interval_hours). Un créneau raté est rattrapé au suivant.
//
// Pas de ->skip() sur la configuration : une sauvegarde non configurée DOIT
// échouer bruyamment, pas disparaître silencieusement du planning.
Schedule::command('qayed:db-backup')
    ->hourly()
    ->withoutOverlapping(120)
    ->onFailure(function () {
        // Filet en plus de la remontée interne de la commande : couvre aussi
        // le cas où le processus meurt avant d'avoir pu se signaler lui-même.
        Log::error('[backup] la tâche planifiée s\'est terminée en échec');

        if (config('sentry.dsn')) {
            \Sentry\captureMessage('Qayed : échec de la tâche planifiée de sauvegarde');
        }
    });

// Battement de cœur du planificateur. Sans lui, un planificateur mort est
// invisible : le serveur web répond normalement pendant que la file cesse
// d'être drainée et que les fiches ne partent plus. Lu par
// GET /admin/health, qui marque « stale » au-delà de 5 minutes.
Schedule::call(fn () => Cache::put(
    SchedulerHeartbeat::CACHE_KEY,
    now()->toIso8601String(),
    now()->addHour(),
))
    ->everyMinute()
    ->name('scheduler-heartbeat');

// Sonde EXTERNE (dead-man's switch) — le seul témoin qui couvre la nuit.
//
// Le battement ci-dessus n'est lu que par le navigateur d'un administrateur :
// il ne dit rien à 3 h du matin. Ici, c'est un service TIERS qui alerte quand
// les appels cessent d'arriver. Aucune tâche interne ne peut jouer ce rôle —
// elle se tairait en même temps que le planificateur qu'elle surveille.
//
// Toutes les 5 minutes plutôt que chaque minute : la détection reste
// largement sous le quart d'heure et l'on n'inflige pas 1 440 appels par jour
// à un service tiers pour rien.
//
// Inerte tant que SCHEDULER_PING_URL n'est pas posée (défaut du dépôt).
// `withoutOverlapping` : un tiers lent ne doit pas empiler des tournées.
// `name` AVANT `withoutOverlapping` : l'inverse lève une LogicException au
// chargement de ce fichier — donc à CHAQUE commande artisan, y compris
// `migrate` au démarrage du conteneur.
Schedule::call(fn () => app(SchedulerHeartbeat::class)->ping())
    ->name('scheduler-external-probe')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// MODULE PROVISOIRE — relais WhatsApp : alerte admin si le worker est
// silencieux (heartbeat périmé > 10 min) — chantier B3.
Schedule::command('whatsapp:check-health')->everyTenMinutes();
