<?php

namespace App\Services\Prospection;

use App\Models\Prospection\ProspectionAccessToken;
use App\Models\Prospection\ProspectionUser;
use Illuminate\Support\Str;

/**
 * Émission des jetons du CRM de prospection. Voir la migration
 * create_prospection_access_tokens_table : seul le hash est stocké, le jeton
 * en clair n'est renvoyé qu'une fois, à l'émission.
 */
class ProspectionSessionIssuer
{
    /**
     * "Session longue durée" (§ Authentification) : outil interne consulté
     * plusieurs fois par jour, personne ne doit être délogé en pleine tournée.
     * Révocation possible à tout moment via logout() / suppression du compte.
     */
    public const TOKEN_LIFETIME_DAYS = 365;

    public static function issue(ProspectionUser $user, ?string $deviceLabel = null): array
    {
        $plainTextToken = Str::random(64);

        $accessToken = ProspectionAccessToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainTextToken),
            // Colonne limitée à 100 caractères (migration
            // create_prospection_access_tokens_table) ; un User-Agent Chrome
            // moderne en fait couramment 110-150 — sans troncature, Postgres
            // rejette l'insertion ("value too long for type character
            // varying(100)") et /auth/login répond 500 en production.
            'device_label' => $deviceLabel ? Str::limit($deviceLabel, 100, '') : null,
            'expires_at' => now()->addDays(self::TOKEN_LIFETIME_DAYS),
        ]);

        return [
            'token' => $plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $accessToken->expires_at,
            'user' => self::userPayload($user),
        ];
    }

    public static function userPayload(ProspectionUser $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'notifications' => [
                'digest_enabled' => $user->notif_digest_enabled,
                'digest_hour' => $user->notif_digest_hour,
                'demo_reminder_enabled' => $user->notif_demo_reminder_enabled,
                'activity_enabled' => $user->notif_activity_enabled,
            ],
        ];
    }
}
