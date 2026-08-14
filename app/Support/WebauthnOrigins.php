<?php

namespace App\Support;

/**
 * Origines autorisées à présenter une réponse WebAuthn.
 *
 * C'est le rempart anti-hameçonnage : une page servie ailleurs verra sa réponse
 * refusée, même si l'utilisateur s'est fait piéger. La liste doit donc rester
 * étroite — et surtout exacte, car une origine légitime absente n'échoue pas
 * gentiment : l'appareil crée bien la passkey, puis le serveur la rejette, et
 * l'utilisateur reste devant « cette passkey n'a pas pu être vérifiée » sans
 * rien pouvoir y faire.
 *
 * POURQUOI LE JUMEAU « www ». Quand aucune origine n'est configurée, on déduit
 * l'origine de FRONTEND_URL — et l'on y ajoute son jumeau www/apex. Ce n'est
 * pas un élargissement de confiance : le RP ID (`qayed.tn`) couvre DÉJÀ tout le
 * domaine enregistrable par construction, App\Support\CorsOrigins traite depuis
 * toujours les deux formes comme équivalentes, et un navigateur masque souvent
 * le préfixe « www » dans sa barre d'adresse — l'utilisateur ne sait donc même
 * pas laquelle des deux il visite. Refuser l'une des deux ne protège de rien et
 * casse une connexion sur deux.
 *
 * Une valeur EXPLICITE de WEBAUTHN_ORIGINS reste prise telle quelle : une liste
 * écrite à la main est une décision, on n'y ajoute rien.
 */
final class WebauthnOrigins
{
    /**
     * @param  string|null  $explicit      Contenu brut de WEBAUTHN_ORIGINS.
     * @param  string|null  $frontendUrl   Repli : l'URL publique du frontend.
     * @return list<string>
     */
    public static function resolve(?string $explicit, ?string $frontendUrl): array
    {
        $listed = self::split($explicit);

        if ($listed !== []) {
            return $listed;
        }

        $origin = self::originOf($frontendUrl);

        if ($origin === null) {
            return [];
        }

        $sibling = self::wwwSibling($origin);

        return $sibling === null ? [$origin] : [$origin, $sibling];
    }

    /**
     * Domaine de la partie de confiance. Repli : l'hôte de FRONTEND_URL, privé
     * de son « www. » — une passkey créée pour `qayed.tn` vaut sur
     * `www.qayed.tn`, l'inverse est faux.
     */
    public static function resolveRpId(?string $explicit, ?string $frontendUrl): ?string
    {
        $explicit = trim((string) $explicit);

        if ($explicit !== '') {
            return $explicit;
        }

        $host = parse_url((string) $frontendUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** @return list<string> */
    private static function split(?string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $raw))));
    }

    /** Schéma + hôte + port éventuel, sans chemin ni barre finale. */
    private static function originOf(?string $url): ?string
    {
        $parts = parse_url((string) $url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        return isset($parts['port']) ? $origin.':'.$parts['port'] : $origin;
    }

    /**
     * `https://qayed.tn` ↔ `https://www.qayed.tn`.
     *
     * Rien n'est déduit pour `localhost`, une adresse IP ou un sous-domaine
     * déjà spécifique (`api.qayed.tn`) : y coller un « www. » ne
     * correspondrait à aucune adresse réelle.
     */
    private static function wwwSibling(string $origin): ?string
    {
        $host = parse_url($origin, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        if (str_starts_with($host, 'www.')) {
            return str_replace('://www.', '://', $origin);
        }

        // Deux étiquettes exactement = domaine enregistrable nu (qayed.tn).
        // filter_var écarte les adresses IP, dont le point n'est pas un label.
        if (substr_count($host, '.') !== 1 || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        return str_replace('://'.$host, '://www.'.$host, $origin);
    }
}
