<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * Configuration du disque de sauvegarde — compatibilité Cloudflare R2.
 *
 * Ces réglages ne se voient pas à l'exécution des tests fonctionnels : ils ne
 * se manifestent qu'au premier envoi RÉEL vers R2, en production, sur la seule
 * protection existante du registre. D'où ces vérifications de configuration,
 * qui figent chaque contrainte R2 avec la raison qui l'impose.
 *
 * Aucun secret n'est lu ni affiché ici : on ne vérifie que la FORME de la
 * configuration, jamais les valeurs de clé ou d'identifiants.
 */
class BackupStorageConfigTest extends TestCase
{
    /** @return array<string,mixed> */
    private function disk(): array
    {
        return config('filesystems.disks.backups');
    }

    public function test_backups_disk_uses_the_s3_driver(): void
    {
        $this->assertSame('s3', $this->disk()['driver']);
    }

    public function test_region_defaults_to_auto_for_r2(): void
    {
        // R2 n'a pas de régions au sens AWS et exige littéralement « auto » :
        // toute autre valeur fait échouer la signature de la requête.
        $this->assertSame('auto', $this->disk()['region']);
    }

    public function test_path_style_endpoint_is_enabled(): void
    {
        // R2 sert le bucket dans le CHEMIN
        // (https://<compte>.r2.cloudflarestorage.com/<bucket>/<clé>).
        // Le style virtuel ne résoudrait pas.
        $this->assertTrue(
            filter_var($this->disk()['use_path_style_endpoint'], FILTER_VALIDATE_BOOLEAN),
            'use_path_style_endpoint doit être vrai pour Cloudflare R2.'
        );
    }

    public function test_checksum_mode_avoids_aws_chunked_streaming(): void
    {
        // Le SDK AWS ≥ 3.337 calcule un CRC32 par défaut
        // (DEFAULT_CALCULATION_MODE = 'when_supported'). Sur un envoi en FLUX
        // — ce que fait la commande de sauvegarde — il bascule alors en
        // encodage « aws-chunked » avec checksum en fin de trame, que R2 ne
        // prend pas en charge et rejette.
        $this->assertSame(
            'when_required',
            $this->disk()['request_checksum_calculation'],
            'Sans « when_required », les envois en flux vers R2 échouent.'
        );
    }

    public function test_no_acl_or_visibility_is_declared(): void
    {
        // R2 ne supporte pas les ACL. Déclarer une « visibility » ferait
        // envoyer un en-tête x-amz-acl par Flysystem, que R2 rejette.
        // Absence VOLONTAIRE — ce test empêche de « compléter » la config.
        $this->assertArrayNotHasKey('visibility', $this->disk());
        $this->assertArrayNotHasKey('acl', $this->disk());
    }

    public function test_endpoint_and_bucket_are_environment_driven(): void
    {
        // Les clés doivent exister dans la configuration pour que le disque
        // soit constructible ; leurs VALEURS viennent de l'environnement et ne
        // sont ni lues ni affichées ici.
        $this->assertArrayHasKey('endpoint', $this->disk());
        $this->assertArrayHasKey('bucket', $this->disk());
    }

    public function test_failures_are_not_swallowed(): void
    {
        // Sur la seule sauvegarde existante, un envoi raté doit lever, pas
        // renvoyer false silencieusement.
        $this->assertTrue($this->disk()['throw'], 'Le disque de sauvegarde doit lever ses erreurs.');
    }

    public function test_no_credential_is_hardcoded_in_the_configuration_file(): void
    {
        $source = file_get_contents(config_path('filesystems.php'));

        // Toute valeur d'identifiant doit passer par env(). Ce test détecte une
        // valeur en dur introduite par inadvertance lors d'un débogage.
        foreach (['key', 'secret'] as $field) {
            $this->assertMatchesRegularExpression(
                "/'{$field}' => env\(/",
                $source,
                "Le champ « {$field} » du disque doit être lu depuis l'environnement."
            );
        }

        // On retire les commentaires avant de chercher : la documentation du
        // fichier CITE le domaine R2 pour expliquer le style de chemin, ce qui
        // est légitime. Seul le CODE ne doit contenir aucune valeur en dur.
        $codeOnly = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codeOnly .= is_array($token) ? $token[1] : $token;
        }

        $this->assertStringNotContainsString(
            'cloudflarestorage.com',
            $codeOnly,
            'Aucun point de terminaison ne doit être codé en dur : il vient de BACKUP_S3_ENDPOINT.'
        );
        $this->assertStringNotContainsString('https://', $codeOnly, 'Aucune URL en dur dans la configuration des disques.');
    }

    public function test_laravel_forwards_extra_options_to_the_s3_client(): void
    {
        // Le correctif checksum repose entièrement sur ce comportement :
        // FilesystemManager::formatS3Config ne retire que « token », donc toute
        // autre clé atteint le constructeur du client S3. Si une future version
        // de Laravel filtrait les clés, le correctif deviendrait inopérant en
        // silence — d'où ce test.
        $reflection = new \ReflectionMethod(
            \Illuminate\Filesystem\FilesystemManager::class,
            'formatS3Config'
        );

        $manager = new \Illuminate\Filesystem\FilesystemManager(app());
        $reflection->setAccessible(true);

        $result = $reflection->invoke($manager, [
            'driver' => 's3',
            'request_checksum_calculation' => 'when_required',
            'use_path_style_endpoint' => true,
        ]);

        $this->assertSame('when_required', Arr::get($result, 'request_checksum_calculation'));
        $this->assertTrue(Arr::get($result, 'use_path_style_endpoint'));
    }
}
