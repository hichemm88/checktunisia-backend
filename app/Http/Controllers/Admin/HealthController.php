<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Métriques d'exploitation minimales.
 *
 * `/up` répond seulement « le processus est vivant ». Cet endpoint répond à la
 * question utile : « est-ce que le travail avance ? » — une file qui gonfle ou
 * un planificateur muet ne font pas tomber le serveur web, mais arrêtent la
 * transmission des fiches aux autorités, ce qui est un incident de conformité.
 *
 * Aucune donnée personnelle : uniquement des compteurs et des horodatages.
 * Réservé aux administrateurs plateforme.
 *
 * QUI OBSERVE QUI. Le battement du planificateur est écrit PAR le
 * planificateur : aucune tâche planifiée ne peut donc servir d'alarme sur sa
 * propre mort. L'observateur est ici extérieur au système observé — c'est le
 * navigateur de l'administrateur qui interroge cet endpoint et voit
 * « dernier battement il y a 47 minutes ».
 *
 * Cela couvre l'exploitation quotidienne, pas la nuit ni le week-end. Pour
 * une alerte réellement indépendante, il faut une sonde EXTERNE au
 * déploiement (voir docs/observabilite.md) : rien de ce qui tourne dans ce
 * conteneur ne peut garantir de crier quand ce conteneur se tait.
 */
class HealthController extends Controller
{
    /** Au-delà, le planificateur est considéré muet (il bat chaque minute). */
    private const SCHEDULER_STALE_MINUTES = 5;

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'database'  => $this->database(),
                'queue'     => $this->queue(),
                'scheduler' => $this->scheduler(),
                'backup'    => $this->backup(),
                'whatsapp'  => $this->whatsappOutbox(),
                'checked_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Dead-letter : les jobs définitivement abandonnés.
     *
     * Sans cette vue, un export de fiches perdu ne laissait qu'une ligne dans
     * une table que personne ne consulte — le manager attend un email qui
     * n'arrivera jamais. On expose la classe, la date et l'erreur ; jamais la
     * charge utile sérialisée, qui contient des adresses email et des
     * identifiants d'établissement.
     */
    public function failedJobs(): JsonResponse
    {
        try {
            $rows = DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(50)
                ->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at']);
        } catch (\Throwable) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'FAILED_JOBS_UNAVAILABLE', 'message' => 'La table failed_jobs est absente.', 'field' => null]],
            ], 503);
        }

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'id'        => $row->id,
                'uuid'      => $row->uuid,
                'queue'     => $row->queue,
                // Nom de classe uniquement — la charge utile complète porte des
                // données personnelles.
                'job'       => json_decode($row->payload, true)['displayName'] ?? 'unknown',
                // Première ligne de la trace : suffisant pour trier, sans
                // déverser une pile de 200 lignes dans l'interface.
                'error'     => strtok((string) $row->exception, "\n"),
                'failed_at' => $row->failed_at,
            ])->all(),
        ]);
    }

    /**
     * Rejoue un job abandonné. Le geste de réparation manquait : constater
     * l'échec sans pouvoir le corriger n'avance à rien.
     */
    public function retryFailedJob(string $uuid): JsonResponse
    {
        $exists = DB::table('failed_jobs')->where('uuid', $uuid)->exists();

        if (! $exists) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'RESOURCE_NOT_FOUND', 'message' => 'Job introuvable.', 'field' => null]],
            ], 404);
        }

        \Illuminate\Support\Facades\Artisan::call('queue:retry', ['id' => [$uuid]]);

        return response()->json(['data' => ['retried' => true, 'uuid' => $uuid]]);
    }

    /**
     * État des sauvegardes.
     *
     * Railway ne fournissant ni sauvegarde native ni PITR, c'est le seul
     * indicateur qui dit si le registre est protégé. Il doit rendre évident,
     * d'un coup d'œil : « dernière sauvegarde réussie il y a 26 h » ou
     * « sauvegarde en ÉCHEC ».
     *
     * Métadonnées d'exploitation uniquement : aucune donnée voyageur, aucun
     * identifiant, aucune clé.
     */
    private function backup(): array
    {
        $state = app(\App\Services\Backup\BackupState::class);
        $data = $state->all();
        $hours = $state->hoursSinceLastSuccess();

        $configured = filled(config('filesystems.disks.backups.bucket'))
            && app(\App\Services\Backup\BackupKeyring::class)->isConfigured();

        return [
            // Faux = aucune sauvegarde ne peut avoir lieu, quel que soit le reste.
            'configured' => $configured,
            'encrypted' => true,
            'last_success_at' => $data['last_success_at'] ?? null,
            'hours_since_success' => $hours,
            // Vrai = il faut agir : soit rien n'a jamais tourné, soit la
            // dernière réussite est trop ancienne.
            'stale' => $state->isStale(),
            'last_result' => $data['last_result'] ?? null,
            'last_failure_at' => $data['last_failure_at'] ?? null,
            // Message déjà expurgé de tout secret à l'écriture.
            'last_error' => $data['last_error'] ?? null,
            'last_size_bytes' => $data['last_size_bytes'] ?? null,
            'last_duration_seconds' => $data['last_duration_seconds'] ?? null,
            // Identifiant de clé, jamais la clé.
            'last_key_id' => $data['last_key_id'] ?? null,
            'destination' => $configured ? 'stockage objet externe' : null,
            'retention_days' => (int) config('backup.retention_days'),
            'retention' => $data['retention'] ?? null,
            'running' => (bool) ($data['running'] ?? false),
        ];
    }

    private function database(): array
    {
        $start = microtime(true);

        try {
            DB::select('SELECT 1');
            $ok = true;
        } catch (\Throwable) {
            $ok = false;
        }

        return [
            'reachable'  => $ok,
            'latency_ms' => round((microtime(true) - $start) * 1000, 1),
        ];
    }

    /**
     * Profondeur de file et échecs. Une file qui grandit sans redescendre
     * signale un worker mort — le symptôme n'apparaît nulle part ailleurs.
     */
    private function queue(): array
    {
        $failed = 0;

        try {
            $failed = DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            // Table absente : les échecs ne sont pas persistés (voir docs).
            $failed = -1;
        }

        $pending = null;

        try {
            if (config('queue.default') === 'redis') {
                $pending = (int) Redis::connection(config('queue.connections.redis.connection', 'default'))
                    ->llen('queues:'.config('queue.connections.redis.queue', 'default'));
            }
        } catch (\Throwable) {
            $pending = null;
        }

        return [
            'driver'       => config('queue.default'),
            'pending'      => $pending,
            'failed_total' => $failed,
        ];
    }

    /**
     * Le planificateur porte le drainage de la file et les tâches de
     * facturation. S'il se tait, tout s'arrête silencieusement.
     */
    private function scheduler(): array
    {
        $last = cache()->get('scheduler:last_run_at');

        // Carbon 3 rend une différence SIGNÉE : `now()->diffInMinutes($passé)`
        // vaut -20, jamais 20, et la comparaison « > 5 » était donc toujours
        // fausse — un planificateur mort se déclarait sain. On mesure dans le
        // sens du temps (dernier battement → maintenant), comme partout
        // ailleurs dans le code.
        $minutesSince = $last === null
            ? null
            : \Illuminate\Support\Carbon::parse($last)->diffInMinutes(now());

        return [
            'last_run_at'    => $last,
            'minutes_since'  => $minutesSince === null ? null : round($minutesSince, 1),
            'stale'          => $minutesSince === null || $minutesSince > self::SCHEDULER_STALE_MINUTES,
        ];
    }

    /**
     * File d'attente du relais WhatsApp — canal de transmission légal
     * aujourd'hui. Compteurs uniquement, aucun contenu de fiche.
     */
    private function whatsappOutbox(): array
    {
        try {
            $rows = DB::table('whatsapp_send_log')
                ->selectRaw('status, COUNT(*) AS total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $counts = [
                'pending' => (int) ($rows['pending'] ?? 0),
                'failed'  => (int) ($rows['failed'] ?? 0),
                'sent'    => (int) ($rows['sent'] ?? 0),
            ];
        } catch (\Throwable) {
            $counts = ['pending' => null, 'failed' => null, 'sent' => null];
        }

        // État de la session elle-même : une file à zéro peut aussi bien dire
        // « tout est parti » que « rien ne peut partir ». Sans le statut de
        // session, les deux se ressemblent — et la seconde situation est un
        // incident de conformité (les fiches n'atteignent pas l'autorité).
        //
        // Lecture seule : aucune écriture, aucun appel au worker. Ce bloc ne
        // peut pas perturber le relais qu'il observe.
        try {
            $state = \App\Models\WhatsappSessionState::query()
                ->where('key', \App\Models\WhatsappSessionState::KEY)
                ->first();

            $counts['session'] = [
                'status'        => $state?->status,
                'paused'        => (bool) $state?->paused,
                'needs_pairing' => (bool) $state?->needsPairing(),
                'last_ready_at' => $state?->last_ready_at?->toIso8601String(),
                'heartbeat_at'  => $state?->heartbeat_at?->toIso8601String(),
                'revoked_at'    => $state?->revoked_at?->toIso8601String(),
            ];
        } catch (\Throwable) {
            $counts['session'] = null;
        }

        return $counts;
    }
}
