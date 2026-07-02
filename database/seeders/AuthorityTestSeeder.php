<?php

namespace Database\Seeders;

use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AuthorityTestSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Ministère de l'Intérieur (national, pas de gouvernorat) ────────
        $ministry = AuthorityOrganization::updateOrCreate(
            ['code' => 'MIN_INT'],
            [
                'name'        => "Ministère de l'Intérieur",
                'type'        => 'ministry',
                'governorate' => null,   // national scope
                'is_active'   => true,
            ]
        );

        $ministryUser = User::updateOrCreate(
            ['email' => 'ministere@interieur.tn'],
            [
                'first_name'         => 'Tahar',
                'last_name'          => 'Belkhodja',
                'password'           => Hash::make('Ministry@123!'),
                'status'             => 'active',
                'email_verified_at'  => now(),
            ]
        );
        $ministryUser->syncRoles(['authority_user']);

        AuthorityUserProfile::updateOrCreate(
            ['user_id' => $ministryUser->id],
            [
                'organization_id' => $ministry->id,
                'badge_number'    => 'MIN-0001',
                'rank'            => 'Directeur Général',
                'authorized_at'   => now(),
            ]
        );

        // ── 2. Poste de police — Sousse (scoped to governorate) ────────────
        $police = AuthorityOrganization::updateOrCreate(
            ['code' => 'DGSN'],
            [
                'name'        => 'Poste de Police — Sousse Ville',
                'type'        => 'police',
                'governorate' => 'Gouvernorat de Sousse',
                'is_active'   => true,
            ]
        );

        $policeUser = User::updateOrCreate(
            ['email' => 'agent@police.tn'],
            [
                'first_name'         => 'Karim',
                'last_name'          => 'Mansouri',
                'password'           => Hash::make('Agent@123!'),
                'status'             => 'active',
                'email_verified_at'  => now(),
            ]
        );
        $policeUser->syncRoles(['authority_user']);

        AuthorityUserProfile::updateOrCreate(
            ['user_id' => $policeUser->id],
            [
                'organization_id' => $police->id,
                'badge_number'    => 'PN-7842',
                'rank'            => 'Lieutenant',
                'authorized_at'   => now(),
            ]
        );

        // ── 3. Poste de police — Tunis ─────────────────────────────────────
        $policeTunis = AuthorityOrganization::updateOrCreate(
            ['code' => 'DGSN_TUNIS'],
            [
                'name'        => 'Poste de Police — Tunis Centre',
                'type'        => 'police',
                'governorate' => 'Gouvernorat de Tunis',
                'is_active'   => true,
            ]
        );

        $policeUserTunis = User::updateOrCreate(
            ['email' => 'agent.tunis@police.tn'],
            [
                'first_name'         => 'Rania',
                'last_name'          => 'Chaabane',
                'password'           => Hash::make('Agent@123!'),
                'status'             => 'active',
                'email_verified_at'  => now(),
            ]
        );
        $policeUserTunis->syncRoles(['authority_user']);

        AuthorityUserProfile::updateOrCreate(
            ['user_id' => $policeUserTunis->id],
            [
                'organization_id' => $policeTunis->id,
                'badge_number'    => 'PN-3311',
                'rank'            => 'Brigadier',
                'authorized_at'   => now(),
            ]
        );

        $this->command->info('Authority test accounts seeded.');
        $this->command->table(
            ['Rôle', 'Email', 'Mot de passe', 'Scope'],
            [
                ['Ministère de l\'Intérieur', 'ministere@interieur.tn', 'Ministry@123!', 'National (toute la Tunisie)'],
                ['Police — Sousse',            'agent@police.tn',        'Agent@123!',    'Gouvernorat de Sousse'],
                ['Police — Tunis',             'agent.tunis@police.tn',  'Agent@123!',    'Gouvernorat de Tunis'],
            ]
        );
    }
}
