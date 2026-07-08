<?php
namespace Database\Seeders;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder {

    /**
     * Trilingual marketing/display content per plan slug — the single source
     * used both here (fresh installs) and by the data migration that
     * backfills existing rows. Mirrors the public pricing cards.
     */
    public static function marketingDefaults(): array {
        $cta = ['fr' => 'Essayer 7 jours gratuit', 'en' => 'Try 7 days free', 'ar' => 'جرّب 7 أيام مجانًا'];
        $perProperty = ['fr' => 'par établissement / mois', 'en' => 'per property / month', 'ar' => 'لكل مؤسسة / شهريًا'];
        $perPropertyYearly = [
            'fr' => 'par établissement / an · 12 mois au prix de 11',
            'en' => 'per property / year · 12 months for the price of 11',
            'ar' => 'لكل مؤسسة / سنويًا · 12 شهرًا بسعر 11',
        ];

        return [
            'essentiel' => [
                'tier'         => ['fr' => 'Starter', 'en' => 'Starter', 'ar' => 'البداية'],
                'display_name' => ['fr' => 'Essentiel', 'en' => 'Essential', 'ar' => 'الأساسي'],
                'tagline'      => [
                    'fr' => "Pour démarrer — petits hébergements avec un volume modéré d'arrivées.",
                    'en' => 'To get started — small properties with a moderate flow of arrivals.',
                    'ar' => 'للانطلاق — مؤسسات إقامة صغيرة بعدد وافدين معتدل.',
                ],
                'price_note'        => $perProperty,
                'price_note_yearly' => $perPropertyYearly,
                'badge'        => null,
                'featured'     => false,
                'cta_label'    => $cta,
                'bullets'      => [
                    ['included' => true,  'text' => ['fr' => '1 établissement', 'en' => '1 property', 'ar' => 'مؤسسة واحدة']],
                    ['included' => true,  'text' => ['fr' => '100 check-ins / mois', 'en' => '100 check-ins / month', 'ar' => '100 تسجيل وصول / شهريًا']],
                    ['included' => true,  'text' => ['fr' => 'Scan MRZ passeport & CIN', 'en' => 'Passport & ID MRZ scan', 'ar' => 'مسح MRZ لجواز السفر وبطاقة التعريف']],
                    ['included' => true,  'text' => ['fr' => 'Fiche de police imprimable', 'en' => 'Printable police form', 'ar' => 'بطاقة شرطة قابلة للطباعة']],
                    ['included' => true,  'text' => ['fr' => '2 comptes utilisateurs', 'en' => '2 user accounts', 'ar' => 'حسابان للمستخدمين']],
                    ['included' => true,  'text' => ['fr' => 'Historique 12 mois', 'en' => '12-month history', 'ar' => 'سجل 12 شهرًا']],
                    ['included' => false, 'text' => ['fr' => 'Multi-établissements', 'en' => 'Multi-property', 'ar' => 'تعدد المؤسسات']],
                    ['included' => false, 'text' => ['fr' => 'Export CSV nuitées', 'en' => 'Overnight stays CSV export', 'ar' => 'تصدير CSV لليالي المبيت']],
                ],
            ],
            'pro' => [
                'tier'         => ['fr' => 'Pro', 'en' => 'Pro', 'ar' => 'احترافي'],
                'display_name' => ['fr' => 'Professionnel', 'en' => 'Professional', 'ar' => 'المحترف'],
                'tagline'      => [
                    'fr' => "Pour les hôtels et maisons d'hôtes avec un flux régulier d'arrivées.",
                    'en' => 'For hotels and guest houses with a steady flow of arrivals.',
                    'ar' => 'للفنادق ودور الضيافة ذات تدفق منتظم من الوافدين.',
                ],
                'price_note'        => $perProperty,
                'price_note_yearly' => $perPropertyYearly,
                'badge'        => ['fr' => 'Le plus choisi', 'en' => 'Most popular', 'ar' => 'الأكثر اختيارًا'],
                'featured'     => true,
                'cta_label'    => $cta,
                'bullets'      => [
                    ['included' => true,  'text' => ['fr' => '1 établissement', 'en' => '1 property', 'ar' => 'مؤسسة واحدة']],
                    ['included' => true,  'text' => ['fr' => 'Check-ins illimités', 'en' => 'Unlimited check-ins', 'ar' => 'تسجيلات وصول غير محدودة']],
                    ['included' => true,  'text' => ['fr' => 'Scan MRZ passeport & CIN', 'en' => 'Passport & ID MRZ scan', 'ar' => 'مسح MRZ لجواز السفر وبطاقة التعريف']],
                    ['included' => true,  'text' => ['fr' => 'Fiche de police imprimable', 'en' => 'Printable police form', 'ar' => 'بطاقة شرطة قابلة للطباعة']],
                    ['included' => true,  'text' => ['fr' => '5 comptes utilisateurs', 'en' => '5 user accounts', 'ar' => '5 حسابات للمستخدمين']],
                    ['included' => true,  'text' => ['fr' => 'Historique illimité', 'en' => 'Unlimited history', 'ar' => 'سجل غير محدود']],
                    ['included' => true,  'text' => ['fr' => 'Export CSV nuitées', 'en' => 'Overnight stays CSV export', 'ar' => 'تصدير CSV لليالي المبيت']],
                    ['included' => false, 'text' => ['fr' => 'Multi-établissements', 'en' => 'Multi-property', 'ar' => 'تعدد المؤسسات']],
                ],
            ],
            'multi-sites' => [
                'tier'         => ['fr' => 'Groupe', 'en' => 'Group', 'ar' => 'مجموعة'],
                'display_name' => ['fr' => 'Multi-sites', 'en' => 'Multi-property', 'ar' => 'متعدد المواقع'],
                'tagline'      => [
                    'fr' => 'Pour les groupes qui gèrent plusieurs établissements depuis un seul compte.',
                    'en' => 'For groups managing several properties from a single account.',
                    'ar' => 'للمجموعات التي تدير عدة مؤسسات من حساب واحد.',
                ],
                'price_note'   => [
                    'fr' => 'par société / mois · tous établissements inclus',
                    'en' => 'per company / month · all properties included',
                    'ar' => 'لكل شركة / شهريًا · جميع المؤسسات مشمولة',
                ],
                'price_note_yearly' => [
                    'fr' => 'par société / an · tous établissements · 12 mois au prix de 11',
                    'en' => 'per company / year · all properties · 12 months for the price of 11',
                    'ar' => 'لكل شركة / سنويًا · جميع المؤسسات · 12 شهرًا بسعر 11',
                ],
                'badge'        => null,
                'featured'     => false,
                'cta_label'    => $cta,
                'bullets'      => [
                    ['included' => true, 'text' => ['fr' => 'Établissements illimités', 'en' => 'Unlimited properties', 'ar' => 'مؤسسات غير محدودة']],
                    ['included' => true, 'text' => ['fr' => 'Check-ins illimités', 'en' => 'Unlimited check-ins', 'ar' => 'تسجيلات وصول غير محدودة']],
                    ['included' => true, 'text' => ['fr' => 'Comptes utilisateurs illimités', 'en' => 'Unlimited user accounts', 'ar' => 'حسابات مستخدمين غير محدودة']],
                    ['included' => true, 'text' => ['fr' => 'Tableau de bord multi-sites', 'en' => 'Multi-property dashboard', 'ar' => 'لوحة قيادة متعددة المواقع']],
                    ['included' => true, 'text' => ['fr' => "Journal d'activité consolidé", 'en' => 'Consolidated activity log', 'ar' => 'سجل نشاط موحّد']],
                    ['included' => true, 'text' => ['fr' => 'Export CSV multi-établissements', 'en' => 'Multi-property CSV export', 'ar' => 'تصدير CSV متعدد المؤسسات']],
                    ['included' => true, 'text' => ['fr' => 'Support prioritaire', 'en' => 'Priority support', 'ar' => 'دعم ذو أولوية']],
                ],
            ],
        ];
    }

    public function run(): void {
        $marketing = self::marketingDefaults();
        $plans = [
            // price_yearly null = règle "1 mois offert" (11 × mensuel) via effective_price_yearly
            ['name'=>'Essentiel','slug'=>'essentiel','scope'=>'hotel','min_rooms'=>1,'max_rooms'=>5,'price_monthly'=>59.000,'price_yearly'=>null,'currency'=>'TND','features'=>['max_users'=>2,'ocr_scans_per_month'=>100],'sort_order'=>1],
            ['name'=>'Pro','slug'=>'pro','scope'=>'hotel','min_rooms'=>6,'max_rooms'=>20,'price_monthly'=>119.000,'price_yearly'=>null,'currency'=>'TND','features'=>['max_users'=>5,'ocr_scans_per_month'=>-1],'sort_order'=>2],
            ['name'=>'Multi-sites','slug'=>'multi-sites','scope'=>'organization','min_rooms'=>1,'max_rooms'=>null,'price_monthly'=>199.000,'price_yearly'=>null,'currency'=>'TND','features'=>['max_users'=>-1,'ocr_scans_per_month'=>-1],'sort_order'=>3],
        ];
        foreach ($plans as $plan) {
            $plan['marketing'] = $marketing[$plan['slug']] ?? null;
            SubscriptionPlan::updateOrCreate(['slug'=>$plan['slug']], $plan);
        }
        $this->command->info('Subscription plans seeded.');
    }
}
