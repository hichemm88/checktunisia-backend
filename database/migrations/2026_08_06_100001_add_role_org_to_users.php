<?php

use App\Services\Organization\RoleOrgMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Rôles intra-organisation pour les comptes hôtel.
 *
 * - `users.role_org` ∈ {owner, admin} (null pour les rôles plateforme non hôtel).
 * - Contrainte DB : exactement un owner par organisation (index unique partiel
 *   Postgres, les comptes soft-deleted ne comptent pas).
 * - Backfill idempotent des données existantes via RoleOrgMigrator :
 *   organization_id manquant (invités liés uniquement par pivot), puis
 *   attribution owner/admin. Un dry-run est disponible avant déploiement :
 *   `php artisan org:migrate-role-org --dry-run`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role_org', 10)->nullable()->after('organization_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_org_check CHECK (role_org IN ('owner', 'admin'))");
            DB::statement("CREATE UNIQUE INDEX users_one_owner_per_org ON users (organization_id) WHERE role_org = 'owner' AND deleted_at IS NULL");
        }

        $changed = RoleOrgMigrator::apply();
        Log::info("Migration role_org appliquée : {$changed} utilisateur(s) mis à jour.");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS users_one_owner_per_org');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_org_check');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role_org');
        });
    }
};
