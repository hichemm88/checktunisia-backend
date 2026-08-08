<?php
namespace Database\Seeders;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PlatformAdminSeeder extends Seeder {
    public function run(): void {
        // Email admin réel (reçoit les alertes plateforme). Surchargable par env ;
        // défaut = boîte de l'exploitant. NE PAS réécraser le mot de passe s'il
        // existe déjà (le seeder tourne à chaque déploiement).
        $email = env('PLATFORM_ADMIN_EMAIL', 'hichemmathlouthi+admin@gmail.com');

        $admin = User::firstOrNew(['email' => $email]);
        if (! $admin->exists) {
            // Le mot de passe ne doit JAMAIS être un littéral du dépôt : ce
            // seeder tourne à chaque déploiement, et le compte platform_admin
            // n'a aucun scoping tenant (accès à tous les hôtels, tous les
            // voyageurs, les journaux d'audit et les paiements). Un mot de
            // passe publié dans le code = compromission totale de la plateforme.
            //
            // En production, on exige PLATFORM_ADMIN_PASSWORD et on échoue
            // bruyamment sinon. Ailleurs, on génère un mot de passe aléatoire
            // affiché une seule fois dans la sortie de la commande.
            $password = env('PLATFORM_ADMIN_PASSWORD');
            $generated = false;

            if (blank($password)) {
                if (app()->environment('production')) {
                    throw new \RuntimeException(
                        "PLATFORM_ADMIN_PASSWORD est requis pour créer le compte {$email} en production. "
                        . "Définissez-le dans l'environnement puis relancez le seeder."
                    );
                }
                $password  = Str::password(20);
                $generated = true;
            }

            $admin->fill([
                'first_name' => 'Admin', 'last_name' => 'Qayed',
                'password' => Hash::make($password), 'email_verified_at' => now(),
            ]);

            if ($generated) {
                $this->command->warn("Mot de passe généré pour {$email} : {$password}");
                $this->command->warn('Notez-le maintenant : il ne sera plus affiché.');
            }
        }
        $admin->status = 'active';
        $admin->save();
        $admin->assignRole('platform_admin');
        $this->command->info("Platform admin: {$email}");
    }
}
