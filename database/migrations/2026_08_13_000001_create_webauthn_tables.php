<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authentification par passkey (WebAuthn / FIDO2).
 *
 * Trois tables, toutes rattachées au COMPTE (users.id) et jamais au rôle : un
 * établissement, un administrateur plateforme et un compte autorité suivent
 * exactement le même chemin.
 *
 * Ce qui est stocké est public par construction — clé publique COSE, identifiant
 * de credential, compteur de signature. Aucune donnée biométrique ne quitte
 * jamais l'appareil : Face ID, Touch ID, Windows Hello et le code de
 * verrouillage servent à déverrouiller la clé privée LOCALEMENT, et le serveur
 * n'en reçoit qu'un booléen (le bit UV de authenticatorData).
 *
 * `users.webauthn_user_handle` : identifiant opaque de 32 octets (base64url)
 * envoyé à l'authentificateur à la place de l'e-mail ou de l'UUID interne. Le
 * handle est ce que l'appareil renvoie en connexion sans identifiant : il ne
 * doit rien révéler du compte s'il fuit, d'où une valeur aléatoire dédiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('webauthn_user_handle', 43)->nullable()->unique()->after('two_factor_confirmed_at');
        });

        Schema::create('webauthn_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            // Identifiant du credential, tel que renvoyé par l'authentificateur
            // (base64url). Unique GLOBALEMENT : un même credential ne peut pas
            // être revendiqué par deux comptes.
            $table->text('credential_id');
            // Clé PUBLIQUE au format COSE (base64url). Jamais de clé privée :
            // elle ne quitte pas l'enclave sécurisée de l'appareil.
            $table->text('public_key');

            $table->string('attestation_type', 40)->default('none');
            $table->json('trust_path');
            $table->uuid('aaguid')->nullable();
            $table->char('user_handle', 43);

            // Transports annoncés par l'authentificateur : internal (biométrie
            // intégrée), hybrid (téléphone en relais), usb/nfc/ble (clé de
            // sécurité). Sert d'indice au navigateur lors des connexions.
            $table->json('transports');

            // Compteur anti-clonage. Beaucoup de passkeys synchronisées le
            // laissent à 0 : la vérification n'est faite QUE s'il progresse.
            $table->bigInteger('sign_count')->default(0);

            // État de sauvegarde (passkey synchronisée iCloud/Google ou non).
            $table->boolean('backup_eligible')->nullable();
            $table->boolean('backed_up')->nullable();
            // Vrai dès qu'une vérification d'utilisateur (biométrie/PIN) a eu lieu.
            $table->boolean('uv_initialized')->nullable();

            $table->string('device_name', 60);
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamps();

            $table->index('user_id', 'idx_webauthn_credentials_user');
        });

        // credential_id est un texte de longueur variable (jusqu'à 1023 octets
        // selon la spec) : un index unique b-tree classique déborde la taille
        // de page Postgres sur les gros identifiants. On indexe donc son
        // empreinte, ce qui suffit pour une recherche par égalité exacte.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX webauthn_credentials_credential_id_unique ON webauthn_credentials (md5(credential_id))');
        } else {
            Schema::table('webauthn_credentials', function (Blueprint $table) {
                $table->unique('credential_id', 'webauthn_credentials_credential_id_unique');
            });
        }

        // Challenges : générés par le serveur, à usage UNIQUE et expirants.
        // Les conserver en base (et non en session) est ce qui rend l'API
        // sans état côté client, tout en gardant le rejeu impossible :
        // `consumed_at` est posé dans la même transaction que la vérification.
        Schema::create('webauthn_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            // null = cérémonie de connexion (l'utilisateur n'est pas encore connu).
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('ceremony', 20); // registration | authentication
            // Options complètes envoyées au navigateur, sérialisées telles
            // quelles : la vérification rejoue EXACTEMENT ce qui a été demandé
            // (challenge, RP ID, exigence de vérification utilisateur).
            $table->json('options');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('expires_at', 'idx_webauthn_challenges_expiry');
        });

        // Codes de récupération : le filet de sécurité quand l'appareil portant
        // la passkey est perdu. Stockés HACHÉS (jamais en clair), à usage unique.
        Schema::create('user_recovery_codes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code_hash', 255);
            $table->timestamp('used_at')->nullable();
            $table->string('used_ip', 45)->nullable();
            $table->timestamps();

            $table->index('user_id', 'idx_user_recovery_codes_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_recovery_codes');
        Schema::dropIfExists('webauthn_challenges');
        Schema::dropIfExists('webauthn_credentials');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('webauthn_user_handle');
        });
    }
};
