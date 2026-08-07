<?php

namespace App\Services\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * État opérationnel des sauvegardes.
 *
 * Sert deux besoins : décider si une sauvegarde est due (fenêtre de fraîcheur)
 * et alimenter l'endpoint de santé. Ne contient QUE des métadonnées
 * d'exploitation — jamais de données voyageurs, jamais d'identifiants, jamais
 * de clé.
 *
 * Stocké en cache (Redis, sur volume persistant). Si le cache est vidé, l'état
 * redevient « inconnu » et la sauvegarde est considérée comme due : le mode de
 * défaillance penche du côté sûr — on sauvegarde une fois de trop plutôt que
 * de croire à tort qu'une sauvegarde récente existe.
 */
class BackupState
{
    private const KEY = 'backup:state';

    /** Un an : l'état doit survivre à une longue interruption. */
    private const TTL_SECONDS = 31536000;

    /** @return array<string,mixed> */
    public function all(): array
    {
        return Cache::get(self::KEY, []);
    }

    public function markStarted(): void
    {
        $this->merge([
            'last_started_at' => now()->toIso8601String(),
            'running' => true,
        ]);
    }

    /** Sortie anticipée (sauvegarde non due) : on n'a rien commencé. */
    public function clearStarted(): void
    {
        $this->merge(['running' => false]);
    }

    /** @param array<string,mixed> $meta */
    public function markSucceeded(array $meta): void
    {
        $this->merge([
            'running' => false,
            'last_success_at' => now()->toIso8601String(),
            'last_result' => 'success',
            'last_error' => null,
            'last_file' => $meta['file'] ?? null,
            'last_key_id' => $meta['key_id'] ?? null,
            'last_size_bytes' => $meta['uploaded_bytes'] ?? null,
            'last_duration_seconds' => $meta['duration_seconds'] ?? null,
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->merge([
            'running' => false,
            'last_failure_at' => now()->toIso8601String(),
            'last_result' => 'failure',
            // Message déjà expurgé par l'appelant.
            'last_error' => mb_substr($error, 0, 500),
        ]);
    }

    /** @param array<string,mixed> $meta */
    public function markRetention(array $meta): void
    {
        $this->merge(['retention' => $meta + ['checked_at' => now()->toIso8601String()]]);
    }

    /**
     * Une sauvegarde est-elle due ?
     *
     * C'est ce test qui rend la planification robuste : la tâche tourne toutes
     * les heures et ne fait le travail que si la dernière réussite date de plus
     * de `interval_hours`. Une exécution manquée est rattrapée à l'heure
     * suivante au lieu de coûter une journée de registre.
     */
    public function isDue(): bool
    {
        $last = $this->all()['last_success_at'] ?? null;

        if ($last === null) {
            return true;
        }

        return Carbon::parse($last)->lte(now()->subHours((int) config('backup.interval_hours')));
    }

    public function hoursSinceLastSuccess(): ?float
    {
        $last = $this->all()['last_success_at'] ?? null;

        return $last === null ? null : round(Carbon::parse($last)->diffInMinutes(now()) / 60, 1);
    }

    public function isStale(): bool
    {
        $hours = $this->hoursSinceLastSuccess();

        return $hours === null || $hours > (float) config('backup.stale_after_hours');
    }

    public function forget(): void
    {
        Cache::forget(self::KEY);
    }

    /** @param array<string,mixed> $values */
    private function merge(array $values): void
    {
        Cache::put(self::KEY, array_merge($this->all(), $values), self::TTL_SECONDS);
    }
}
