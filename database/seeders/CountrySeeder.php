<?php
namespace Database\Seeders;
use App\Models\Country;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder {
    public function run(): void {
        $countries = [
            ['code'=>'TN','alpha3'=>'TUN','name_en'=>'Tunisia','name_fr'=>'Tunisie','name_ar'=>'تونس','flag_emoji'=>'🇹🇳','sort_order'=>1],
            ['code'=>'FR','alpha3'=>'FRA','name_en'=>'France','name_fr'=>'France','name_ar'=>'فرنسا','flag_emoji'=>'🇫🇷','sort_order'=>2],
            ['code'=>'DE','alpha3'=>'DEU','name_en'=>'Germany','name_fr'=>'Allemagne','name_ar'=>'ألمانيا','flag_emoji'=>'🇩🇪','sort_order'=>3],
            ['code'=>'GB','alpha3'=>'GBR','name_en'=>'United Kingdom','name_fr'=>'Royaume-Uni','name_ar'=>'المملكة المتحدة','flag_emoji'=>'🇬🇧','sort_order'=>4],
            ['code'=>'IT','alpha3'=>'ITA','name_en'=>'Italy','name_fr'=>'Italie','name_ar'=>'إيطاليا','flag_emoji'=>'🇮🇹','sort_order'=>5],
            ['code'=>'ES','alpha3'=>'ESP','name_en'=>'Spain','name_fr'=>'Espagne','name_ar'=>'إسبانيا','flag_emoji'=>'🇪🇸','sort_order'=>6],
            ['code'=>'MA','alpha3'=>'MAR','name_en'=>'Morocco','name_fr'=>'Maroc','name_ar'=>'المغرب','flag_emoji'=>'🇲🇦','sort_order'=>7],
            ['code'=>'DZ','alpha3'=>'DZA','name_en'=>'Algeria','name_fr'=>'Algérie','name_ar'=>'الجزائر','flag_emoji'=>'🇩🇿','sort_order'=>8],
            ['code'=>'LY','alpha3'=>'LBY','name_en'=>'Libya','name_fr'=>'Libye','name_ar'=>'ليبيا','flag_emoji'=>'🇱🇾','sort_order'=>9],
            ['code'=>'SA','alpha3'=>'SAU','name_en'=>'Saudi Arabia','name_fr'=>'Arabie Saoudite','name_ar'=>'المملكة العربية السعودية','flag_emoji'=>'🇸🇦','sort_order'=>10],
            ['code'=>'US','alpha3'=>'USA','name_en'=>'United States','name_fr'=>'États-Unis','name_ar'=>'الولايات المتحدة','flag_emoji'=>'🇺🇸','sort_order'=>11],
            ['code'=>'CN','alpha3'=>'CHN','name_en'=>'China','name_fr'=>'Chine','name_ar'=>'الصين','flag_emoji'=>'🇨🇳','sort_order'=>12],
            ['code'=>'RU','alpha3'=>'RUS','name_en'=>'Russia','name_fr'=>'Russie','name_ar'=>'روسيا','flag_emoji'=>'🇷🇺','sort_order'=>13],
            ['code'=>'TR','alpha3'=>'TUR','name_en'=>'Turkey','name_fr'=>'Turquie','name_ar'=>'تركيا','flag_emoji'=>'🇹🇷','sort_order'=>14],
            ['code'=>'BE','alpha3'=>'BEL','name_en'=>'Belgium','name_fr'=>'Belgique','name_ar'=>'بلجيكا','flag_emoji'=>'🇧🇪','sort_order'=>15],
            ['code'=>'NL','alpha3'=>'NLD','name_en'=>'Netherlands','name_fr'=>'Pays-Bas','name_ar'=>'هولندا','flag_emoji'=>'🇳🇱','sort_order'=>16],
            ['code'=>'CH','alpha3'=>'CHE','name_en'=>'Switzerland','name_fr'=>'Suisse','name_ar'=>'سويسرا','flag_emoji'=>'🇨🇭','sort_order'=>17],
            ['code'=>'SE','alpha3'=>'SWE','name_en'=>'Sweden','name_fr'=>'Suède','name_ar'=>'السويد','flag_emoji'=>'🇸🇪','sort_order'=>18],
            ['code'=>'NO','alpha3'=>'NOR','name_en'=>'Norway','name_fr'=>'Norvège','name_ar'=>'النرويج','flag_emoji'=>'🇳🇴','sort_order'=>19],
            ['code'=>'EG','alpha3'=>'EGY','name_en'=>'Egypt','name_fr'=>'Égypte','name_ar'=>'مصر','flag_emoji'=>'🇪🇬','sort_order'=>20],
        ];
        foreach ($countries as $c) Country::updateOrCreate(['code'=>$c['code']], $c);
        $this->command->info(count($countries).' countries seeded.');
    }
}
