<?php
namespace Database\Seeders;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PlatformAdminSeeder extends Seeder {
    public function run(): void {
        $admin = User::updateOrCreate(
            ['email' => 'admin@checktunisia.tn'],
            ['first_name'=>'Admin','last_name'=>'CheckTunisia','password'=>Hash::make('Admin@123!'),'status'=>'active','email_verified_at'=>now()]
        );
        $admin->assignRole('platform_admin');
        $this->command->info('Platform admin created: admin@checktunisia.tn / Admin@123!');
    }
}
