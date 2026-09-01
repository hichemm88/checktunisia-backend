<?php

namespace App\Http\Middleware;

use App\Services\Auth\SessionIssuer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Une session ouverte par code WhatsApp reste sur l'appareil qui l'a ouverte.
 *
 * ── Pourquoi cette garde n'existe que pour l'OTP ────────────────────────
 *
 * Les autres sessions durent huit heures ; celle-ci dure trente jours, et elle
 * appartient à quelqu'un qui n'a ni mot de passe ni adresse e-mail réelle —
 * donc aucun autre moyen de constater qu'on la lui a volée. Un jeton recopié
 * ailleurs vaudrait un mois d'accès au registre des voyageurs. L'empreinte
 * d'appareil est ce qui rend la copie inutile.
 *
 * ── Pourquoi une empreinte GROSSIÈRE ────────────────────────────────────
 *
 * L'user-agent complet porte le numéro de version du navigateur, qui change
 * tout seul à chaque mise à jour — c'est-à-dire à peu près tous les mois sur
 * un téléphone. S'y accrocher déconnecterait les agents à intervalle
 * régulier, sans qu'aucun d'eux ne comprenne pourquoi.
 *
 * L'empreinte retenue efface donc les chiffres de version et ne garde que la
 * FAMILLE : « iPhone + Safari », « Android + Chrome ». Une mise à jour ne la
 * change pas ; rejouer le jeton depuis un autre téléphone ou depuis un poste
 * de travail, si.
 *
 * Ce n'est pas une preuve cryptographique — un user-agent se forge. Ce n'est
 * pas ce qu'on lui demande : elle rend inopérant le vol de jeton ORDINAIRE
 * (journal recopié, sauvegarde de navigateur, appareil prêté), qui est le
 * risque réel ici. Le facteur qui, lui, ne se forge pas reste la possession
 * du numéro WhatsApp au moment de la connexion.
 *
 * Aucun effet sur les autres sessions : un jeton sans capacité d'appareil
 * traverse ce middleware sans être regardé.
 */
class EnsureOtpDeviceMatches
{
    /** Préfixe de la capacité qui porte l'empreinte. */
    public const ABILITY_PREFIX = 'otp-device:';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! SessionIssuer::isWhatsappOtpSession($token)) {
            return $next($request);
        }

        $expected = self::fingerprintFrom($token);

        // Session OTP émise avant l'introduction de l'empreinte : on ne la
        // casse pas. Elle expirera d'elle-même, et les suivantes en porteront
        // une.
        if ($expected === null) {
            return $next($request);
        }

        if (! hash_equals($expected, self::fingerprint($request->userAgent()))) {
            // Le jeton est détruit, pas seulement refusé : si l'empreinte ne
            // correspond pas, soit il a été recopié, soit il a fuité. Dans les
            // deux cas le laisser vivre vingt-neuf jours de plus n'a aucun
            // intérêt, et l'agent légitime en redemande un en dix secondes.
            $token->delete();

            return response()->json([
                'data' => null,
                'errors' => [[
                    'code' => 'OTP_DEVICE_MISMATCH',
                    'message' => 'Session ouverte sur un autre appareil. Demandez un nouveau code.',
                    'field' => null,
                ]],
            ], 401);
        }

        return $next($request);
    }

    /**
     * Capacité à ajouter au jeton pour le lier à l'appareil courant.
     */
    public static function abilityFor(?string $userAgent): string
    {
        return self::ABILITY_PREFIX.self::fingerprint($userAgent);
    }

    /**
     * Empreinte portée par un jeton, ou null s'il n'en porte pas.
     */
    public static function fingerprintFrom(mixed $token): ?string
    {
        foreach ((array) ($token->abilities ?? []) as $ability) {
            if (is_string($ability) && str_starts_with($ability, self::ABILITY_PREFIX)) {
                return substr($ability, strlen(self::ABILITY_PREFIX));
            }
        }

        return null;
    }

    /**
     * Empreinte d'un user-agent : famille d'appareil et de navigateur, sans
     * numéro de version.
     *
     * Hachée, et non stockée en clair : la capacité d'un jeton se retrouve en
     * base et dans les exports d'administration, et un user-agent complet est
     * une donnée d'identification de plus dont personne n'a besoin là.
     */
    public static function fingerprint(?string $userAgent): string
    {
        $normalized = strtolower((string) $userAgent);

        // Les nombres partent : « chrome/131.0.6778.200 » et
        // « chrome/132.0.6834.83 » doivent donner la même empreinte.
        $normalized = preg_replace('/\d+/', '', $normalized) ?? '';

        // Puis tout ce qui n'est ni lettre ni espace — les séparateurs varient
        // d'une version à l'autre autant que les chiffres.
        $normalized = preg_replace('/[^a-z ]+/', ' ', $normalized) ?? '';
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? '');

        return substr(hash('sha256', $normalized), 0, 16);
    }
}
