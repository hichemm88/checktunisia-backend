<?php

namespace App\Console\Commands;

use App\Services\Organization\RoleOrgMigrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Attribution des rôles intra-organisation (owner/admin) aux comptes hôtel.
 *
 * Idempotente : ne touche que les utilisateurs sans role_org. Le dry-run
 * fonctionne même avant la migration de schéma (il ne fait que lire) et
 * logge le plan complet pour validation avant application.
 */
class MigrateRoleOrg extends Command
{
    protected $signature = 'org:migrate-role-org {--dry-run : Affiche et logge le plan sans rien modifier}';

    protected $description = 'Attribue role_org (owner/admin) aux hotel_admin existants — un seul owner par organisation';

    public function handle(): int
    {
        $plan = RoleOrgMigrator::plan();

        if (empty($plan)) {
            $this->info('Rien à faire : tous les utilisateurs concernés ont déjà un role_org.');
            return self::SUCCESS;
        }

        $this->table(
            ['Action', 'Utilisateur', 'Email', 'Organisation', 'Détail'],
            array_map(fn ($a) => [$a['action'], $a['user_id'], $a['email'], $a['organization_id'], $a['detail']], $plan)
        );

        Log::info('org:migrate-role-org — plan', ['dry_run' => (bool) $this->option('dry-run'), 'actions' => $plan]);

        if ($this->option('dry-run')) {
            $this->info(count($plan) . ' action(s) planifiée(s) — aucune modification appliquée (dry-run).');
            return self::SUCCESS;
        }

        if (!Schema::hasColumn('users', 'role_org')) {
            $this->error('La colonne users.role_org n\'existe pas encore — lancer `php artisan migrate` d\'abord.');
            return self::FAILURE;
        }

        $changed = RoleOrgMigrator::apply();
        $this->info("{$changed} utilisateur(s) mis à jour.");
        Log::info('org:migrate-role-org — appliqué', ['changed' => $changed]);

        return self::SUCCESS;
    }
}
