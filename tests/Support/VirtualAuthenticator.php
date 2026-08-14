<?php

namespace Tests\Support;

use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;

/**
 * Authentificateur WebAuthn simulé — l'équivalent en PHP de ce que font Face ID,
 * Touch ID, Windows Hello ou une clé de sécurité.
 *
 * Il détient une VRAIE paire de clés ES256 et produit de VRAIES signatures : les
 * tests exercent donc le chemin cryptographique complet de la bibliothèque, et
 * pas une version simplifiée. C'est ce qui permet de prouver qu'une signature
 * falsifiée, un mauvais origin ou un challenge rejoué sont bien refusés.
 *
 * Chaque paramètre nommé des méthodes register()/assert() sert à fabriquer un
 * cas d'attaque : origin menteur, RP ID d'un autre domaine, bit de vérification
 * utilisateur absent, compteur qui recule.
 *
 * @see https://www.w3.org/TR/webauthn-3/#sctn-authenticator-data
 */
class VirtualAuthenticator
{
    // Fanions de authenticatorData (§6.1).
    public const FLAG_UP = 0x01; // présence de l'utilisateur (« touchez le capteur »)
    public const FLAG_UV = 0x04; // vérification de l'utilisateur (biométrie / code)
    public const FLAG_BE = 0x08; // credential sauvegardable (passkey synchronisable)
    public const FLAG_BS = 0x10; // credential effectivement sauvegardé
    public const FLAG_AT = 0x40; // données de credential attestées présentes

    public readonly string $credentialId;

    private readonly OpenSSLAsymmetricKey $privateKey;

    private readonly string $x;

    private readonly string $y;

    /** 16 octets nuls : un authentificateur qui refuse de s'identifier (cas des passkeys). */
    private string $aaguid = "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";

    public int $signCount = 0;

    public function __construct(?string $credentialId = null)
    {
        $this->credentialId = $credentialId ?? random_bytes(32);

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);

        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Impossible de générer une clé EC de test.');
        }

        $details = openssl_pkey_get_details($key);
        $this->privateKey = $key;
        $this->x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $this->y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    // ── Enregistrement (navigator.credentials.create) ────────────────────────

    /**
     * @param  array  $publicKey  Options renvoyées par le serveur (déjà en JSON).
     * @return array Réponse telle que la produirait le navigateur.
     */
    public function register(
        array $publicKey,
        string $origin,
        ?string $rpIdOverride = null,
        int $flags = self::FLAG_UP | self::FLAG_UV | self::FLAG_BE | self::FLAG_BS | self::FLAG_AT,
        ?string $challengeOverride = null,
        array $transports = ['internal', 'hybrid'],
    ): array {
        $clientDataJSON = $this->clientData(
            'webauthn.create',
            $challengeOverride ?? $publicKey['challenge'],
            $origin,
        );

        $authData = $this->authenticatorData(
            $rpIdOverride ?? $publicKey['rp']['id'],
            $flags,
            $this->signCount,
            $this->attestedCredentialData(),
        );

        $attestationObject = self::cborMap([
            ['fmt', self::cborText('none')],
            ['attStmt', self::cborMap([])],
            ['authData', self::cborBytes($authData)],
        ]);

        return [
            'id'       => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'rawId'    => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'type'     => 'public-key',
            'response' => [
                'clientDataJSON'    => Base64UrlSafe::encodeUnpadded($clientDataJSON),
                'attestationObject' => Base64UrlSafe::encodeUnpadded($attestationObject),
                'transports'        => $transports,
            ],
        ];
    }

    // ── Connexion (navigator.credentials.get) ────────────────────────────────

    /**
     * @param  array  $publicKey  Options de connexion renvoyées par le serveur.
     */
    public function assert(
        array $publicKey,
        string $origin,
        string $userHandle,
        ?string $rpIdOverride = null,
        int $flags = self::FLAG_UP | self::FLAG_UV | self::FLAG_BE | self::FLAG_BS,
        ?int $signCountOverride = null,
        bool $forgeSignature = false,
        ?string $challengeOverride = null,
    ): array {
        $this->signCount++;

        $clientDataJSON = $this->clientData(
            'webauthn.get',
            $challengeOverride ?? $publicKey['challenge'],
            $origin,
        );

        $authData = $this->authenticatorData(
            $rpIdOverride ?? $publicKey['rpId'],
            $flags,
            $signCountOverride ?? $this->signCount,
        );

        if ($forgeSignature) {
            // Signature aléatoire de longueur plausible : c'est exactement ce
            // dont dispose un attaquant qui n'a pas la clé privée.
            $signature = random_bytes(70);
        } else {
            openssl_sign(
                $authData . hash('sha256', $clientDataJSON, true),
                $signature,
                $this->privateKey,
                OPENSSL_ALGO_SHA256,
            );
        }

        return [
            'id'       => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'rawId'    => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'type'     => 'public-key',
            'response' => [
                'clientDataJSON'    => Base64UrlSafe::encodeUnpadded($clientDataJSON),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
                'signature'         => Base64UrlSafe::encodeUnpadded($signature),
                'userHandle'        => $userHandle,
            ],
        ];
    }

    // ── Fabrication des structures ───────────────────────────────────────────

    private function clientData(string $type, string $challenge, string $origin): string
    {
        return json_encode([
            'type'        => $type,
            'challenge'   => $challenge,
            'origin'      => $origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function authenticatorData(string $rpId, int $flags, int $signCount, string $attested = ''): string
    {
        return hash('sha256', $rpId, true)  // rpIdHash — 32 octets
            . chr($flags)                   // fanions  — 1 octet
            . pack('N', $signCount)         // compteur — 4 octets, gros-boutiste
            . $attested;
    }

    /** aaguid(16) || longueur de l'id(2) || id || clé publique COSE */
    private function attestedCredentialData(): string
    {
        return $this->aaguid
            . pack('n', strlen($this->credentialId))
            . $this->credentialId
            . $this->coseKey();
    }

    /** Clé publique EC2 / P-256 / ES256 au format COSE_Key (RFC 8152). */
    private function coseKey(): string
    {
        return self::cborMap([
            [1,  self::cborInt(2)],           // kty : EC2
            [3,  self::cborInt(-7)],          // alg : ES256
            [-1, self::cborInt(1)],           // crv : P-256
            [-2, self::cborBytes($this->x)],
            [-3, self::cborBytes($this->y)],
        ]);
    }

    // ── Encodage CBOR minimal ────────────────────────────────────────────────
    //
    // Seuls quatre types sont nécessaires (entier, chaîne d'octets, texte,
    // table) : de quoi produire un attestationObject et une clé COSE valides,
    // sans dépendre d'un encodeur complet côté tests.

    private static function cborHead(int $major, int $value): string
    {
        return match (true) {
            $value < 24          => chr(($major << 5) | $value),
            $value < 0x100       => chr(($major << 5) | 24) . chr($value),
            $value < 0x10000     => chr(($major << 5) | 25) . pack('n', $value),
            default              => chr(($major << 5) | 26) . pack('N', $value),
        };
    }

    private static function cborInt(int $value): string
    {
        return $value >= 0
            ? self::cborHead(0, $value)
            : self::cborHead(1, -1 - $value);
    }

    private static function cborBytes(string $value): string
    {
        return self::cborHead(2, strlen($value)) . $value;
    }

    private static function cborText(string $value): string
    {
        return self::cborHead(3, strlen($value)) . $value;
    }

    /** @param array<array{0: int|string, 1: string}> $pairs */
    private static function cborMap(array $pairs): string
    {
        $out = self::cborHead(5, count($pairs));

        foreach ($pairs as [$key, $encodedValue]) {
            $out .= is_int($key) ? self::cborInt($key) : self::cborText($key);
            $out .= $encodedValue;
        }

        return $out;
    }
}
