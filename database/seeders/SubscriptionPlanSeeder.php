<?php
namespace Database\Seeders;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder {
    public function run(): void {
        $plans = [
            ['name'=>'Small','slug'=>'small','min_rooms'=>1,'max_rooms'=>5,'price_monthly'=>25.000,'price_yearly'=>250.000,'currency'=>'TND','features'=>['max_users'=>3,'ocr_scans_per_month'=>200],'sort_order'=>1],
            ['name'=>'Medium','slug'=>'medium','min_rooms'=>6,'max_rooms'=>20,'price_monthly'=>85.000,'price_yearly'=>850.000,'currency'=>'TND','features'=>['max_users'=>10,'ocr_scans_per_month'=>1000],'sort_order'=>2],
            ['name'=>'Large','slug'=>'large','min_rooms'=>21,'max_rooms'=>null,'price_monthly'=>250.000,'price_yearly'=>null,'currency'=>'TND','features'=>['max_users'=>-1,'ocr_scans_per_month'=>-1],'sort_order'=>3],
        ];
        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(['slug'=>$plan['slug']], $plan);
        }
        $this->command->info('Subscription plans seeded.');
    }
}
