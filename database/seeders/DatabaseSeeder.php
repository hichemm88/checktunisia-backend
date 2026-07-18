<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CountrySeeder::class,
            DocumentTypeSeeder::class,
            RolesAndPermissionsSeeder::class,
            SubscriptionPlanSeeder::class,
            PlatformAdminSeeder::class,
            AiPricingSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
