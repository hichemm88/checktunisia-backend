<?php
namespace Database\Seeders;
use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\Hotel;
use App\Models\HotelAddress;
use App\Models\HotelContact;
use App\Models\Room;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder {
    public function run(): void {

        // Skip entirely once the demo hotel exists in any state (including soft-deleted).
        // This seeder runs on every boot (see Dockerfile CMD); withTrashed() here matters
        // because a plain updateOrCreate() ignores soft-deleted rows and would otherwise try
        // to re-insert the same unique slug on every restart after someone deletes the demo
        // hotel from the admin panel — which is exactly what happened in production.
        if (Hotel::withTrashed()->where('slug', 'hotel-sousse-azur')->exists()) {
            $this->command->info('Demo data already present (or deliberately removed) — skipping.');
            return;
        }

        // Demo hotel
        $hotel = Hotel::updateOrCreate(['slug'=>'hotel-sousse-azur'],['name'=>'Hôtel Sousse Azur','type'=>'hotel','room_count'=>45,'registration_number'=>'TN-HOT-2023-0041','stars'=>4,'status'=>'active']);
        HotelAddress::updateOrCreate(['hotel_id'=>$hotel->id,'is_primary'=>true],['line1'=>'Avenue Bourguiba 12','city'=>'Sousse','governorate'=>'Gouvernorat de Sousse','postal_code'=>'4000','country_code'=>'TN']);
        HotelContact::updateOrCreate(['hotel_id'=>$hotel->id,'type'=>'phone'],['value'=>'+21673123456','is_primary'=>true]);

        // Active subscription
        $plan = SubscriptionPlan::where('slug','medium')->first();
        $sub = Subscription::updateOrCreate(['hotel_id'=>$hotel->id,'plan_id'=>$plan->id],['status'=>'active','billing_cycle'=>'monthly','started_at'=>now()->startOfMonth(),'expires_at'=>now()->endOfYear(),'auto_renew'=>true]);
        SubscriptionEvent::firstOrCreate(['subscription_id'=>$sub->id,'event_type'=>'activated'],['new_status'=>'active','created_at'=>now()]);

        // Rooms
        foreach(range(101,115) as $i) Room::updateOrCreate(['hotel_id'=>$hotel->id,'number'=>(string)$i],['floor'=>1,'type'=>'standard','capacity'=>2,'status'=>'available']);
        foreach(range(201,215) as $i) Room::updateOrCreate(['hotel_id'=>$hotel->id,'number'=>(string)$i],['floor'=>2,'type'=>'standard','capacity'=>2,'status'=>'available']);

        // Hotel admin user
        $adminUser = User::updateOrCreate(['email'=>'hotelier@hotel-azur.tn'],['first_name'=>'Hichem','last_name'=>'Mathlouthi','password'=>Hash::make('Hotel@123!'),'status'=>'active','email_verified_at'=>now()]);
        $adminUser->syncRoles(['hotel_admin']);
        $hotel->users()->syncWithoutDetaching([$adminUser->id => ['granted_at'=>now()]]);

        // Receptionist user
        $recept = User::updateOrCreate(['email'=>'reception@hotel-azur.tn'],['first_name'=>'Sonia','last_name'=>'Ben Ali','password'=>Hash::make('Recept@123!'),'status'=>'active','email_verified_at'=>now()]);
        $recept->syncRoles(['receptionist']);
        $hotel->users()->syncWithoutDetaching([$recept->id => ['granted_at'=>now()]]);

        // Authority organization + user
        $org = AuthorityOrganization::updateOrCreate(['code'=>'DGSN'],['name'=>'Direction Générale de la Sûreté Nationale','type'=>'police','is_active'=>true]);
        $authUser = User::updateOrCreate(['email'=>'agent@police.tn'],['first_name'=>'Karim','last_name'=>'Mansouri','password'=>Hash::make('Agent@123!'),'status'=>'active','email_verified_at'=>now()]);
        $authUser->syncRoles(['authority_user']);
        AuthorityUserProfile::updateOrCreate(['user_id'=>$authUser->id],['organization_id'=>$org->id,'badge_number'=>'PN-7842','rank'=>'Lieutenant','authorized_at'=>now()]);

        $this->command->info('Demo data seeded.');
        $this->command->table(['Role','Email','Password'],[
            ['Hotel Admin','hotelier@hotel-azur.tn','Hotel@123!'],
            ['Receptionist','reception@hotel-azur.tn','Recept@123!'],
            ['Authority','agent@police.tn','Agent@123!'],
            ['Platform Admin','admin@qayed.tn','Admin@123!'],
        ]);
    }
}
