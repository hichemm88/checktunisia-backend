<?php
namespace Database\Seeders;
use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder {
    public function run(): void {
        $types = [
            ['code'=>'passport','name_en'=>'Passport','name_fr'=>'Passeport','mrz_format'=>'TD3'],
            ['code'=>'national_id','name_en'=>'National ID Card','name_fr'=>'Carte Nationale d\'Identité','mrz_format'=>'TD1'],
            ['code'=>'residence_permit','name_en'=>'Residence Permit','name_fr'=>'Titre de séjour','mrz_format'=>'TD1'],
            ['code'=>'visa','name_en'=>'Visa','name_fr'=>'Visa','mrz_format'=>null],
        ];
        foreach ($types as $t) DocumentType::updateOrCreate(['code'=>$t['code']], $t);
        $this->command->info('Document types seeded.');
    }
}
