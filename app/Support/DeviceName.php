<?php

namespace App\Support;

/**
 * Nom d'appareil proposé par défaut à l'enregistrement d'une passkey.
 *
 * Purement cosmétique : l'utilisateur peut le renommer (« iPhone de Hichem »,
 * « PC Bureau »). Déduit de l'en-tête User-Agent, qui n'est jamais une source
 * de vérité — d'où le repli générique.
 */
class DeviceName
{
    public static function fromUserAgent(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        return match (true) {
            str_contains($ua, 'iPhone')                        => 'iPhone',
            str_contains($ua, 'iPad')                          => 'iPad',
            str_contains($ua, 'Android')                       => 'Appareil Android',
            str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X') => 'Mac',
            str_contains($ua, 'Windows')                       => 'PC Windows',
            str_contains($ua, 'Linux')                         => 'Ordinateur Linux',
            default                                            => 'Passkey',
        };
    }
}
