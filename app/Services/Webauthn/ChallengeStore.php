<?php

namespace App\Services\Webauthn;

use App\Models\User;
use App\Models\WebauthnChallenge;
use Illuminate\Support\Facades\Request;

/**
 * Émission et consommation des challenges WebAuthn.
 *
 * Un challenge est valable UNE fois et pendant quelques minutes. La
 * consommation passe par un UPDATE conditionnel unique : deux requêtes
 * concurrentes portant la même réponse ne peuvent pas réussir toutes les deux,
 * même sans transaction explicite. C'est la protection contre le rejeu — celle
 * de la signature ne suffirait pas, une réponse valide étant rejouable telle
 * quelle tant que son challenge reste ouvert.
 */
class ChallengeStore
{
    /**
     * @param  array  $options  Options complètes, telles qu'envoyées au navigateur.
     */
    public function issue(string $ceremony, array $options, ?User $user = null): WebauthnChallenge
    {
        $this->pruneExpired();

        return WebauthnChallenge::create([
            'user_id'    => $user?->id,
            'ceremony'   => $ceremony,
            'options'    => $options,
            'expires_at' => now()->addSeconds((int) config('webauthn.challenge_ttl', 300)),
            'ip_address' => Request::ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Consomme un challenge et le renvoie, ou null s'il est inconnu, expiré,
     * déjà utilisé, d'une autre cérémonie ou d'un autre utilisateur.
     */
    public function consume(string $id, string $ceremony, ?User $user = null): ?WebauthnChallenge
    {
        // Un identifiant mal formé ne doit pas faire lever la requête SQL
        // (colonne uuid côté Postgres) : on répond « introuvable ».
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            return null;
        }

        $query = WebauthnChallenge::where('id', $id)
            ->where('ceremony', $ceremony)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now());

        if ($user) {
            $query->where('user_id', $user->id);
        }

        // Une seule ligne affectée = ce processus est le seul à l'avoir prise.
        if ($query->update(['consumed_at' => now()]) !== 1) {
            return null;
        }

        return WebauthnChallenge::find($id);
    }

    /**
     * Purge des challenges périmés. Appelée à chaque émission (le volume est
     * faible) et par la tâche planifiée quotidienne.
     */
    public function pruneExpired(): int
    {
        return WebauthnChallenge::where('expires_at', '<', now()->subHour())->delete();
    }
}
