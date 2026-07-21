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
            // DemoDataSeeder retire du cycle par defaut : ne jamais injecter de
            // donnees de demo en production (elles faussaient le tableau de bord).
            // A relancer manuellement en local si besoin : db:seed --class=DemoDataSeeder.
        ]);
    }
}
