<?php

namespace App\Services\Auth;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Émission des sessions applicatives, quel que soit le facteur présenté.
 *
 * Trois chemins mènent à une session complète — mot de passe seul, mot de passe
 * + TOTP (ou code de récupération), et passkey. Ils produisaient jusqu'ici trois
 * payloads légèrement différents, écrits à la main dans autant de contrôleurs.
 * Tout passe désormais par ici : ajouter un facteur ne peut plus faire diverger
 * ce que le frontend reçoit.
 *
 * Le rôle n'entre nulle part dans cette décision : la sécurité est portée par
 * le COMPTE. Un établissement, un administrateur et un compte autorité suivent
 * exactement les mêmes règles.
 */
class SessionIssuer
{
    /**
     * Capacité marquant une session ouverte par passkey.
     *
     * Elle ne donne aucun droit supplémentaire : elle sert aux middlewares à
     * reconnaître qu'une vérification forte (possession de l'appareil +
     * biométrie/code) a déjà eu lieu, et qu'exiger un TOTP en plus n'aurait
     * pas de sens. On ne peut pas la tester avec `can()` : Sanctum considère
     * qu'un token `['*']` peut tout — d'où la lecture directe des capacités.
     */
    public const PASSKEY_ABILITY = 'passkey-session';

    public const METHOD_PASSWORD      = 'password';
    public const METHOD_TOTP          = 'totp';
    public const METHOD_RECOVERY_CODE = 'recovery_code';
    public const METHOD_PASSKEY       = 'passkey';

    /**
     * Ouvre une session complète et renvoie le payload d'API.
     */
    public static function issue(User $user, string $method): array
    {
        $abilities = $method === self::METHOD_PASSKEY
            ? ['*', self::PASSKEY_ABILITY]
            : ['*'];

        $token = $user->createToken('api-token', $abilities, now()->addHours(8));

        return [
            'token'      => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at,
            'user'       => self::userPayload($user, $method),
        ];
    }

    /**
     * Profil renvoyé au frontend : identité, rôle, périmètre, et l'état de
     * sécurité du compte dont dépend l'affichage (proposer une passkey,
     * masquer la 2FA, alerter sur des codes de récupération épuisés).
     */
    public static function userPayload(User $user, ?string $authMethod = null): array
    {
        $hotel = $user->isHotelStaff() ? $user->hotel() : null;

        return [
            'id'                => $user->id,
            'email'             => $user->email,
            'first_name'        => $user->first_name,
            'last_name'         => $user->last_name,
            'phone'             => $user->phone,
            'role'              => $user->primary_role,
            'role_org'          => $user->role_org,
            'hotel'             => $hotel ? [
                'id'                      => $hotel->id,
                'name'                    => $hotel->name,
                'slug'                    => $hotel->slug,
                'type'                    => $hotel->type,
                'subscription_status'     => $hotel->activeSubscription?->status ?? 'none',
                'subscription_expires_at' => $hotel->activeSubscription?->expires_at,
            ] : null,
            'authority_profile' => self::authorityProfile($user),
            'permissions'       => $user->getAllPermissions()->pluck('name'),
            'security'          => self::securityState($user, $authMethod),
        ];
    }

    public static function securityState(User $user, ?string $authMethod = null): array
    {
        return [
            'two_factor_enabled'       => (bool) $user->two_factor_confirmed_at,
            'passkeys_count'           => $user->webauthnCredentials()->count(),
            'recovery_codes_remaining' => $user->recoveryCodes()->whereNull('used_at')->count(),
            'auth_method'              => $authMethod,
        ];
    }

    /**
     * Une session ouverte par passkey ? Lit les capacités brutes du token :
     * `can()` renverrait vrai pour n'importe quoi sur un token `['*']`.
     */
    public static function isPasskeySession(mixed $token): bool
    {
        if (! $token instanceof PersonalAccessToken) {
            return false;
        }

        return in_array(self::PASSKEY_ABILITY, (array) ($token->abilities ?? []), true);
    }

    public static function authMethodOf(mixed $token): ?string
    {
        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        return self::isPasskeySession($token) ? self::METHOD_PASSKEY : null;
    }

    private static function authorityProfile(User $user): ?array
    {
        if (! $user->isAuthorityUser()) {
            return null;
        }

        $profile = $user->authorityProfile()->with('organization')->first();
        if (! $profile) {
            return null;
        }

        $org = $profile->organization;

        return [
            'org_id'       => $org?->id,
            'org_name'     => $org?->name,
            'org_type'     => $org?->type,
            'governorate'  => $org?->governorate,
            'badge_number' => $profile->badge_number,
            'rank'         => $profile->rank,
            'expires_at'   => $profile->expires_at?->toDateString(),
        ];
    }
}
