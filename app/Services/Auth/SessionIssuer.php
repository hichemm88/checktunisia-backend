<?php

namespace App\Services\Auth;

use App\Http\Middleware\EnsureOtpDeviceMatches;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Émission des sessions applicatives, quel que soit le facteur présenté.
 *
 * Quatre chemins mènent à une session complète — mot de passe seul, mot de passe
 * + TOTP (ou code de récupération), passkey, et code reçu sur WhatsApp. Ils
 * produisaient jusqu'ici autant de payloads légèrement différents, écrits à la
 * main dans autant de contrôleurs. Tout passe désormais par ici : ajouter un
 * facteur ne peut plus faire diverger ce que le frontend reçoit.
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

    /**
     * Capacité marquant une session ouverte par code WhatsApp.
     *
     * Même nature que la précédente : aucun droit supplémentaire, seulement la
     * trace du facteur présenté. Elle vaut vérification forte pour les
     * middlewares — la possession du numéro sur lequel les fiches arrivent est
     * précisément ce que la 2FA vérifierait, et les agents concernés n'ont pas
     * d'adresse e-mail réelle pour en configurer une.
     */
    public const WHATSAPP_OTP_ABILITY = 'whatsapp-otp-session';

    public const METHOD_PASSWORD      = 'password';
    public const METHOD_TOTP          = 'totp';
    public const METHOD_RECOVERY_CODE = 'recovery_code';
    public const METHOD_PASSKEY       = 'passkey';
    public const METHOD_WHATSAPP_OTP  = 'whatsapp_otp';

    /**
     * Ouvre une session complète et renvoie le payload d'API.
     *
     * `$userAgent` ne sert qu'à l'OTP : c'est lui qui lie la session longue à
     * l'appareil qui l'a ouverte (voir EnsureOtpDeviceMatches). Les autres
     * facteurs l'ignorent — leurs sessions durent huit heures, la question ne
     * se pose pas de la même façon.
     */
    public static function issue(User $user, string $method, ?string $userAgent = null): array
    {
        $abilities = match ($method) {
            self::METHOD_PASSKEY      => ['*', self::PASSKEY_ABILITY],
            self::METHOD_WHATSAPP_OTP => ['*', self::WHATSAPP_OTP_ABILITY, EnsureOtpDeviceMatches::abilityFor($userAgent)],
            default                   => ['*'],
        };

        /*
         * Huit heures partout, sauf pour l'OTP.
         *
         * Un agent ouvre la fiche depuis WhatsApp, sur un téléphone, souvent
         * en déplacement : le reconnecter toutes les huit heures reviendrait à
         * redemander un message à Meta plusieurs fois par semaine, pour une
         * personne qui n'a ni mot de passe ni adresse e-mail de secours. La
         * contrepartie est la révocation, immédiate et par compte, depuis la
         * fiche de l'agent dans l'administration.
         */
        $expiresAt = $method === self::METHOD_WHATSAPP_OTP
            ? now()->addDays((int) config('whatsapp.otp.session_days', 30))
            : now()->addHours(8);

        $token = $user->createToken('api-token', $abilities, $expiresAt);

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
        return self::hasAbility($token, self::PASSKEY_ABILITY);
    }

    /** Session ouverte par code WhatsApp ? Même lecture brute des capacités. */
    public static function isWhatsappOtpSession(mixed $token): bool
    {
        return self::hasAbility($token, self::WHATSAPP_OTP_ABILITY);
    }

    /**
     * Une vérification forte a-t-elle déjà eu lieu à l'ouverture de session ?
     *
     * Le seul point où l'on décide qu'un facteur « en tient lieu ». Les
     * middlewares posent la question ici plutôt que d'énumérer les capacités
     * chacun de leur côté : un quatrième facteur, demain, ne devra être ajouté
     * qu'à un seul endroit.
     */
    public static function isStrongSession(mixed $token): bool
    {
        return self::isPasskeySession($token) || self::isWhatsappOtpSession($token);
    }

    public static function authMethodOf(mixed $token): ?string
    {
        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        return match (true) {
            self::isPasskeySession($token)     => self::METHOD_PASSKEY,
            self::isWhatsappOtpSession($token) => self::METHOD_WHATSAPP_OTP,
            default                            => null,
        };
    }

    private static function hasAbility(mixed $token, string $ability): bool
    {
        if (! $token instanceof PersonalAccessToken) {
            return false;
        }

        return in_array($ability, (array) ($token->abilities ?? []), true);
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
