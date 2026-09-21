<?php

namespace Database\Seeders;

use App\Models\Prospection\ProspectionUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Les 2 comptes internes du CRM de prospection (§ Authentification : "2
 * comptes seedés (email + mot de passe, variables d'environnement) : Hichem
 * (admin) et un second compte (membre)"). D'autres comptes membres se créent
 * ensuite depuis l'app (UserController, réservé à l'admin) — ce seeder ne
 * sert qu'à amorcer les 2 premiers.
 *
 * firstOrCreate et NON updateOrCreate, même raison que AiPricingSeeder :
 * rejoué à chaque déploiement, il ne doit jamais réécrire un mot de passe
 * déjà changé par son titulaire depuis l'app.
 *
 * Variable absente => ce compte est silencieusement ignoré (pas d'échec de
 * déploiement pour un outil interne non encore configuré).
 */
class ProspectionUserSeeder extends Seeder
{
    public function run(): void
    {
        // env('X') ?: défaut, et non env('X', défaut) : une variable posée
        // mais VIDE (cas courant d'un .env qui liste toutes les clés
        // possibles) court-circuiterait sinon le nom par défaut.
        $this->seedOne(
            name: env('PROSPECTION_ADMIN_NAME') ?: 'Hichem',
            email: env('PROSPECTION_ADMIN_EMAIL'),
            password: env('PROSPECTION_ADMIN_PASSWORD'),
            role: 'admin',
        );

        $this->seedOne(
            name: env('PROSPECTION_MEMBER_NAME') ?: 'Équipe',
            email: env('PROSPECTION_MEMBER_EMAIL'),
            password: env('PROSPECTION_MEMBER_PASSWORD'),
            role: 'membre',
        );
    }

    private function seedOne(string $name, ?string $email, ?string $password, string $role): void
    {
        if (!$email || !$password) {
            Log::info("[prospection] compte {$role} non seedé : variables d'environnement absentes.");

            return;
        }

        ProspectionUser::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'role' => $role,
                'active' => true,
            ],
        );
    }
}
