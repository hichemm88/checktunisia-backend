<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Chiffrement des sauvegardes — XChaCha20-Poly1305 « secretstream » (libsodium).
 *
 * Pourquoi cette primitive et pas Crypt::encryptString() de Laravel :
 *  - elle est AUTHENTIFIÉE (toute altération d'un octet est détectée au
 *    déchiffrement, ce qui est le vrai risque sur une archive de plusieurs
 *    mois : la corruption silencieuse) ;
 *  - elle travaille en FLUX par blocs, à mémoire constante — un dump de
 *    plusieurs giga-octets ne tiendrait pas en mémoire ;
 *  - elle est dans le cœur de PHP depuis 7.2. Aucune cryptographie maison.
 *
 * Format de fichier :
 *
 *   "QYDBKP01"          8 octets — nombre magique + version de format
 *   <longueur id>       1 octet
 *   <identifiant clé>   N octets, en CLAIR (jamais la clé elle-même)
 *   <en-tête sodium>    24 octets
 *   <blocs chiffrés>    …
 *
 * L'identifiant de clé en clair est ce qui permet la rotation sans invalider
 * l'historique : chaque fichier sait avec quelle clé il a été chiffré.
 */
class BackupEncryptor
{
    private const MAGIC = 'QYDBKP01';

    /** 1 Mio par bloc : compromis mémoire / surcoût d'authentification. */
    private const CHUNK_BYTES = 1048576;

    public function __construct(private BackupKeyring $keyring) {}

    /**
     * Chiffre $source vers $destination.
     *
     * @return string l'identifiant de clé utilisé (utile pour la journalisation)
     */
    public function encryptFile(string $source, string $destination): string
    {
        $keyId = $this->keyring->currentKeyId();
        $key = $this->keyring->currentKey();

        $in = @fopen($source, 'rb');
        if ($in === false) {
            throw new RuntimeException('Source illisible pour le chiffrement.');
        }

        $out = @fopen($destination, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Destination non inscriptible pour le chiffrement.');
        }

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

            fwrite($out, self::MAGIC);
            fwrite($out, chr(strlen($keyId)));
            fwrite($out, $keyId);
            fwrite($out, $header);

            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw new RuntimeException('Lecture interrompue pendant le chiffrement.');
                }

                if ($chunk === '' && feof($in)) {
                    break;
                }

                // Le dernier bloc porte le marqueur FINAL : sa présence est ce
                // qui prouve, au déchiffrement, que le fichier est COMPLET.
                // Une archive tronquée est donc détectée, pas restaurée à moitié.
                $tag = feof($in)
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

                $encrypted = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);

                if (fwrite($out, pack('N', strlen($encrypted)).$encrypted) === false) {
                    throw new RuntimeException('Écriture interrompue pendant le chiffrement.');
                }
            }
        } finally {
            fclose($in);
            fclose($out);
            // La clé ne doit pas traîner en mémoire plus que nécessaire.
            sodium_memzero($key);
        }

        return $keyId;
    }

    /**
     * Déchiffre $source vers $destination.
     *
     * Lève une exception si la clé est mauvaise, si le fichier a été altéré,
     * ou s'il est tronqué (marqueur final absent).
     */
    public function decryptFile(string $source, string $destination): void
    {
        $in = @fopen($source, 'rb');
        if ($in === false) {
            throw new RuntimeException('Archive illisible.');
        }

        try {
            $magic = (string) fread($in, strlen(self::MAGIC));

            if ($magic !== self::MAGIC) {
                throw new RuntimeException(
                    "Ce fichier n'est pas une sauvegarde Qayed chiffrée (nombre magique absent)."
                );
            }

            $idLength = ord((string) fread($in, 1));
            $keyId = (string) fread($in, $idLength);
            $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

            $key = $this->keyring->keyFor($keyId);

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            sodium_memzero($key);

            $out = @fopen($destination, 'wb');
            if ($out === false) {
                throw new RuntimeException('Destination non inscriptible pour le déchiffrement.');
            }

            $sawFinal = false;

            try {
                while (! feof($in)) {
                    $lengthBytes = fread($in, 4);

                    if ($lengthBytes === false || strlen($lengthBytes) < 4) {
                        break;
                    }

                    $length = unpack('N', $lengthBytes)[1];
                    $encrypted = (string) fread($in, $length);

                    if (strlen($encrypted) !== $length) {
                        throw new RuntimeException('Archive tronquée : bloc incomplet.');
                    }

                    $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $encrypted);

                    if ($result === false) {
                        // Cas confondus volontairement : mauvaise clé et
                        // altération produisent le même échec d'authentification.
                        throw new RuntimeException(
                            'Déchiffrement impossible : clé incorrecte ou archive altérée.'
                        );
                    }

                    [$plain, $tag] = $result;
                    fwrite($out, $plain);

                    if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                        $sawFinal = true;
                        break;
                    }
                }
            } finally {
                fclose($out);
            }

            if (! $sawFinal) {
                throw new RuntimeException(
                    'Archive incomplète : marqueur de fin absent. Le transfert a probablement été interrompu.'
                );
            }
        } finally {
            fclose($in);
        }
    }

    /** Le fichier porte-t-il l'en-tête d'une sauvegarde chiffrée Qayed ? */
    public function looksEncrypted(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $magic = (string) fread($handle, strlen(self::MAGIC));
        fclose($handle);

        return $magic === self::MAGIC;
    }

    /** Identifiant de clé d'une archive, sans la déchiffrer. */
    public function keyIdOf(string $path): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            if ((string) fread($handle, strlen(self::MAGIC)) !== self::MAGIC) {
                return null;
            }

            $idLength = ord((string) fread($handle, 1));

            return (string) fread($handle, $idLength);
        } finally {
            fclose($handle);
        }
    }
}
