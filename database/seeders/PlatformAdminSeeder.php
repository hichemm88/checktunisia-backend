<?php
namespace Database\Seeders;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PlatformAdminSeeder extends Seeder {
    public function run(): void {
        // Email admin réel (reçoit les alertes plateforme). Surchargable par env ;
        // défaut = boîte de l'exploitant. NE PAS réécraser le mot de passe s'il
        // existe déjà (le seeder tourne à chaque déploiement).
        $email = env('PLATFORM_ADMIN_EMAIL', 'hichemmathlouthi+admin@gmail.com');

        $admin = User::firstOrNew(['email' => $email]);
        if (! $admin->exists) {
            $admin->fill([
                'first_name' => 'Admin', 'last_name' => 'Qayed',
                'password' => Hash::make('Admin@123!'), 'email_verified_at' => now(),
            ]);
        }
        $admin->status = 'active';
        $admin->save();
        $admin->assignRole('platform_admin');
        $this->command->info("Platform admin: {$email}");
    }
}
