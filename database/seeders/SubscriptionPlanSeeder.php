<?php
namespace Database\Seeders;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder {
    public function run(): void {
        $plans = [
            ['name'=>'Essentiel','slug'=>'essentiel','scope'=>'hotel','min_rooms'=>1,'max_rooms'=>5,'price_monthly'=>59.000,'price_yearly'=>590.000,'currency'=>'TND','features'=>['max_users'=>2,'ocr_scans_per_month'=>100],'sort_order'=>1],
            ['name'=>'Pro','slug'=>'pro','scope'=>'hotel','min_rooms'=>6,'max_rooms'=>20,'price_monthly'=>119.000,'price_yearly'=>1190.000,'currency'=>'TND','features'=>['max_users'=>5,'ocr_scans_per_month'=>-1],'sort_order'=>2],
            ['name'=>'Multi-sites','slug'=>'multi-sites','scope'=>'organization','min_rooms'=>1,'max_rooms'=>null,'price_monthly'=>199.000,'price_yearly'=>1990.000,'currency'=>'TND','features'=>['max_users'=>-1,'ocr_scans_per_month'=>-1],'sort_order'=>3],
        ];
        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(['slug'=>$plan['slug']], $plan);
        }
        $this->command->info('Subscription plans seeded.');
    }
}
