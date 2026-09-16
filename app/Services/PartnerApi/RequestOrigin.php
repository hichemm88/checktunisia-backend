<?php

namespace App\Services\PartnerApi;

use Illuminate\Http\Request;

/** Origine (scheme://host[:port]) d'une requête, via Origin puis Referer en repli. */
class RequestOrigin
{
    public static function of(Request $request): ?string
    {
        return $request->header('Origin') ?? self::fromReferer($request->header('Referer'));
    }

    private static function fromReferer(?string $referer): ?string
    {
        if (! $referer) {
            return null;
        }

        $parts = parse_url($referer);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
