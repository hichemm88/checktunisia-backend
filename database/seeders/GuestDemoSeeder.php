<?php

namespace Database\Seeders;

use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\TravelDocument;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GuestDemoSeeder extends Seeder
{
    public function run(): void
    {
        $hotel = Hotel::where('slug', 'hotel-sousse-azur')->first();
        if (!$hotel) {
            $this->command->error('Hotel not found. Run DemoDataSeeder first.');
            return;
        }

        $room101 = Room::where('hotel_id', $hotel->id)->where('number', '101')->first();
        $room102 = Room::where('hotel_id', $hotel->id)->where('number', '102')->first();
        $room201 = Room::where('hotel_id', $hotel->id)->where('number', '201')->first();
        $room202 = Room::where('hotel_id', $hotel->id)->where('number', '202')->first();
        $room203 = Room::where('hotel_id', $hotel->id)->where('number', '203')->first();

        $guests = [
            // Active guests (currently in hotel)
            [
                'guest' => ['first_name' => 'Ahmed',    'last_name' => 'Mansouri',  'date_of_birth' => '1985-03-12', 'sex' => 'M', 'nationality_code' => 'TUN'],
                'doc'   => ['type' => 'passport', 'document_number' => 'TN12345678', 'issuing_country_code' => 'TUN', 'expiry_date' => now()->addMonths(20)->toDateString(), 'issue_date' => '2019-01-01', 'is_verified' => true],
                'checkin' => ['room' => $room101, 'status' => 'active',    'check_in_date' => now()->subDays(2)->toDateString(), 'expected_check_out_date' => now()->addDays(3)->toDateString()],
            ],
            [
                'guest' => ['first_name' => 'Sophie',   'last_name' => 'Dupont',    'date_of_birth' => '1990-07-22', 'sex' => 'F', 'nationality_code' => 'FRA'],
                'doc'   => ['type' => 'passport', 'document_number' => 'FR98765432', 'issuing_country_code' => 'FRA', 'expiry_date' => now()->addMonths(8)->toDateString(), 'issue_date' => '2020-06-15', 'is_verified' => true],
                'checkin' => ['room' => $room102, 'status' => 'active',    'check_in_date' => now()->subDays(1)->toDateString(), 'expected_check_out_date' => now()->addDays(5)->toDateString()],
            ],
            [
                'guest' => ['first_name' => 'Khalid',   'last_name' => 'Al-Rashid', 'date_of_birth' => '1978-11-05', 'sex' => 'M', 'nationality_code' => 'SAU'],
                'doc'   => ['type' => 'passport', 'document_number' => 'SA55544433', 'issuing_country_code' => 'SAU', 'expiry_date' => now()->addDays(15)->toDateString(), 'issue_date' => '2018-03-20', 'is_verified' => true],
                'checkin' => ['room' => $room201, 'status' => 'active',    'check_in_date' => now()->toDateString(),             'expected_check_out_date' => now()->addDays(7)->toDateString()],
            ],
            [
                'guest' => ['first_name' => 'Fatima',   'last_name' => 'Zahra',     'date_of_birth' => '1995-02-28', 'sex' => 'F', 'nationality_code' => 'MAR'],
                'doc'   => ['type' => 'passport', 'document_number' => 'MA33322211', 'issuing_country_code' => 'MAR', 'expiry_date' => now()->addDays(6)->toDateString(),  'issue_date' => '2019-09-10', 'is_verified' => true],
                'checkin' => ['room' => $room202, 'status' => 'active',    'check_in_date' => now()->toDateString(),             'expected_check_out_date' => now()->addDays(4)->toDateString()],
            ],
            [
                'guest' => ['first_name' => 'Marco',    'last_name' => 'Bianchi',   'date_of_birth' => '1982-08-14', 'sex' => 'M', 'nationality_code' => 'ITA'],
                'doc'   => ['type' => 'passport', 'document_number' => 'IT77766655', 'issuing_country_code' => 'ITA', 'expiry_date' => now()->addMonths(18)->toDateString(),'issue_date' => '2021-04-05', 'is_verified' => true],
                'checkin' => ['room' => $room203, 'status' => 'active',    'check_in_date' => now()->subDays(3)->toDateString(), 'expected_check_out_date' => now()->addDays(1)->toDateString()],
            ],
            // Completed stays (historical)
            [
                'guest' => ['first_name' => 'Youssef',  'last_name' => 'Ben Salem',  'date_of_birth' => '1972-06-18', 'sex' => 'M', 'nationality_code' => 'TUN'],
                'doc'   => ['type' => 'national_id', 'document_number' => 'CIN09876543', 'issuing_country_code' => 'TUN', 'expiry_date' => now()->addYears(3)->toDateString(), 'issue_date' => '2018-01-01', 'is_verified' => true],
                'checkin' => ['room' => $room101, 'status' => 'completed', 'check_in_date' => now()->subDays(10)->toDateString(), 'expected_check_out_date' => now()->subDays(7)->toDateString(), 'actual_check_out_date' => now()->subDays(7)->toDateString()],
            ],
            [
                'guest' => ['first_name' => 'Elena',    'last_name' => 'Petrov',    'date_of_birth' => '1988-12-03', 'sex' => 'F', 'nationality_code' => 'RUS'],
                'doc'   => ['type' => 'passport', 'document_number' => 'RU44433322', 'issuing_country_code' => 'RUS', 'expiry_date' => now()->addDays(5)->toDateString(),  'issue_date' => '2020-07-12', 'is_verified' => false],
                'checkin' => ['room' => $room102, 'status' => 'completed', 'check_in_date' => now()->subDays(15)->toDateString(), 'expected_check_out_date' => now()->subDays(12)->toDateString(), 'actual_check_out_date' => now()->subDays(12)->toDateString()],
            ],
            [
                'guest' => ['first_name' => 'Omar',     'last_name' => 'Benali',    'date_of_birth' => '2000-04-25', 'sex' => 'M', 'nationality_code' => 'ALG'],
                'doc'   => ['type' => 'passport', 'document_number' => 'DZ11122233', 'issuing_country_code' => 'DZA', 'expiry_date' => now()->addMonths(30)->toDateString(),'issue_date' => '2022-02-20', 'is_verified' => true],
                'checkin' => ['room' => $room201, 'status' => 'completed', 'check_in_date' => now()->subDays(5)->toDateString(),  'expected_check_out_date' => now()->subDays(2)->toDateString(),  'actual_check_out_date' => now()->subDays(2)->toDateString()],
            ],
        ];

        foreach ($guests as $data) {
            $guest = Guest::create([
                'id'               => Str::uuid(),
                'hotel_id'         => $hotel->id,
                'first_name'       => $data['guest']['first_name'],
                'last_name'        => $data['guest']['last_name'],
                'date_of_birth'    => $data['guest']['date_of_birth'],
                'sex'              => $data['guest']['sex'],
                'nationality_code' => $data['guest']['nationality_code'],
            ]);

            TravelDocument::create([
                'id'                   => Str::uuid(),
                'guest_id'             => $guest->id,
                'hotel_id'             => $hotel->id,
                'type'                 => $data['doc']['type'],
                'document_number'      => $data['doc']['document_number'],
                'issuing_country_code' => $data['doc']['issuing_country_code'],
                'expiry_date'          => $data['doc']['expiry_date'],
                'issue_date'           => $data['doc']['issue_date'],
                'is_verified'          => $data['doc']['is_verified'],
            ]);

            $checkIn = CheckIn::create([
                'id'                      => Str::uuid(),
                'hotel_id'                => $hotel->id,
                'room_id'                 => $data['checkin']['room']?->id,
                'reference'               => 'CHK-' . strtoupper(Str::random(6)),
                'status'                  => $data['checkin']['status'],
                'check_in_date'           => $data['checkin']['check_in_date'],
                'expected_check_out_date' => $data['checkin']['expected_check_out_date'],
                'actual_check_out_date'   => $data['checkin']['actual_check_out_date'] ?? null,
                'adults_count'            => 1,
                'children_count'          => 0,
            ]);

            // Link guest to check-in
            $checkIn->guests()->attach($guest->id, ['is_primary' => true]);

            // Update room status
            if ($data['checkin']['status'] === 'active' && $data['checkin']['room']) {
                $data['checkin']['room']->update(['status' => 'occupied']);
            }
        }

        $this->command->info('Guest demo data seeded: 8 guests, 5 active + 3 completed stays.');
        $this->command->table(
            ['Prénom', 'Nom', 'Nationalité', 'Statut', 'Doc expire'],
            collect($guests)->map(fn($g) => [
                $g['guest']['first_name'],
                $g['guest']['last_name'],
                $g['guest']['nationality_code'],
                $g['checkin']['status'],
                $g['doc']['expiry_date'],
            ])->toArray()
        );
    }
}
