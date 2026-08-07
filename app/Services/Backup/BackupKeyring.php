<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Trousseau de clés de chiffrement des sauvegardes.
 *
 * Deux exigences contradictoires en apparence :
 *  - pouvoir changer de clé (rotation) ;
 *  - ne pas rendre illisibles les sauvegardes déjà prises.
 *
 * Résolues en écrivant l'IDENTIFIANT de la clé — jamais la clé — en clair dans
 * l'en-tête de chaque fichier. Le déchiffrement lit cet identifiant et va
 * chercher la clé correspondante dans le trousseau. Une rotation consiste donc
 * à déclarer une nouvelle clé courante et à déplacer l'ancienne dans
 * `previous_keys` : l'historique reste déchiffrable.
 *
 * Aucune méthode de cette classe ne journalise ni ne renvoie une clé en clair.
 */
class BackupKeyring
{
    private const KEY_BYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES;

    /** Identifiant de la clé à utiliser pour CHIFFRER. */
    public function currentKeyId(): string
    {
        $id = trim((string) config('backup.encryption.key_id'));

        if ($id === '') {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY_ID est vide.');
        }

        // L'identifiant part en clair dans l'en-tête : on le contraint pour
        // qu'il ne puisse pas servir à injecter quoi que ce soit.
        if (! preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id)) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY_ID invalide : alphanumérique, tiret ou souligné, 32 caractères max.');
        }

        return $id;
    }

    public function isConfigured(): bool
    {
        try {
            $this->currentKey();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** Clé binaire courante (chiffrement). */
    public function currentKey(): string
    {
        return $this->decodeKey(
            (string) config('backup.encryption.key'),
            'BACKUP_ENCRYPTION_KEY'
        );
    }

    /**
     * Clé binaire correspondant à un identifiant (déchiffrement).
     * Cherche d'abord la clé courante, puis les clés retirées du service.
     */
    public function keyFor(string $keyId): string
    {
        if ($keyId === $this->currentKeyIdSafe()) {
            return $this->currentKey();
        }

        foreach ($this->previousKeys() as $id => $encoded) {
            if ($id === $keyId) {
                return $this->decodeKey($encoded, "BACKUP_ENCRYPTION_PREVIOUS_KEYS[{$id}]");
            }
        }

        throw new RuntimeException(
            "Aucune clé déclarée pour l'identifiant « {$keyId} ». "
            .'Cette sauvegarde a été chiffrée avec une clé absente du trousseau : '
            .'ajoutez-la à BACKUP_ENCRYPTION_PREVIOUS_KEYS pour pouvoir la restaurer.'
        );
    }

    /**
     * Clés retirées du service, indexées par identifiant.
     * Format attendu : « k1:base64,k2:base64 ».
     *
     * @return array<string,string>
     */
    public function previousKeys(): array
    {
        $raw = trim((string) config('backup.encryption.previous_keys'));

        if ($raw === '') {
            return [];
        }

        $keys = [];

        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }

            $parts = explode(':', $pair, 2);
            if (count($parts) !== 2) {
                // On n'inclut PAS la valeur fautive dans le message : elle
                // pourrait contenir un fragment de clé.
                throw new RuntimeException('BACKUP_ENCRYPTION_PREVIOUS_KEYS malformé : attendu « id:cléBase64 » séparés par des virgules.');
            }

            $keys[trim($parts[0])] = trim($parts[1]);
        }

        return $keys;
    }

    /** Génère une clé neuve, encodée en base64. Sortie SECRÈTE. */
    public function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    private function currentKeyIdSafe(): string
    {
        try {
            return $this->currentKeyId();
        } catch (RuntimeException) {
            return '';
        }
    }

    /**
     * Décode et valide une clé base64.
     * Les messages d'erreur ne contiennent JAMAIS la valeur, seulement le nom
     * de la variable et la nature du problème.
     */
    private function decodeKey(string $encoded, string $name): string
    {
        $encoded = trim($encoded);

        if ($encoded === '') {
            throw new RuntimeException("{$name} est absent : le chiffrement des sauvegardes ne peut pas fonctionner.");
        }

        $binary = base64_decode($encoded, true);

        if ($binary === false) {
            throw new RuntimeException("{$name} n'est pas du base64 valide.");
        }

        if (strlen($binary) !== self::KEY_BYTES) {
            throw new RuntimeException(
                sprintf('%s fait %d octets une fois décodé, %d attendus.', $name, strlen($binary), self::KEY_BYTES)
            );
        }

        return $binary;
    }
}
