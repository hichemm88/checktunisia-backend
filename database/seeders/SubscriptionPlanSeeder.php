<?php
namespace Database\Seeders;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder {

    /**
     * Trilingual marketing/display content per plan slug — the single source
     * used both here (fresh installs) and by the data migration that
     * backfills existing rows. Mirrors the public pricing cards.
     *
     * Grille V3 (2026-08-08) : quota inclus explicite sur les TROIS packs et
     * dépassement facturé AU CHECK-IN (plus par tranche) —
     * Essentiel 100 / 0,600 · Pro 300 / 0,400 · Grand Flux 1 000 / 0,250.
     * Multi-sites est legacy : conservé pour ses abonnés, plus souscriptible.
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
                    'fr' => "Pour les petites maisons d'hôtes avec un volume modéré d'arrivées.",
                    'en' => 'For small guest houses with a moderate volume of arrivals.',
                    'ar' => 'لدور الضيافة الصغيرة ذات عدد وافدين معتدل.',
                ],
                'price_note'        => $perProperty,
                'price_note_yearly' => $perPropertyYearly,
                'badge'        => null,
                'featured'     => false,
                'cta_label'    => $cta,
                'bullets'      => [
                    ['included' => true,  'text' => ['fr' => '1 établissement', 'en' => '1 property', 'ar' => 'مؤسسة واحدة']],
                    ['included' => true,  'text' => ['fr' => '100 check-ins inclus / mois', 'en' => '100 check-ins included / month', 'ar' => '100 تسجيل وصول مشمول / شهريًا']],
                    ['included' => true,  'text' => ['fr' => 'Scan MRZ passeport & CIN', 'en' => 'Passport & ID MRZ scan', 'ar' => 'مسح MRZ لجواز السفر وبطاقة التعريف']],
                    ['included' => true,  'text' => ['fr' => 'Fiche de police imprimable', 'en' => 'Printable police form', 'ar' => 'بطاقة شرطة قابلة للطباعة']],
                    ['included' => true,  'text' => ['fr' => '2 comptes utilisateurs', 'en' => '2 user accounts', 'ar' => 'حسابان للمستخدمين']],
                    ['included' => true,  'text' => ['fr' => 'Historique 12 mois', 'en' => '12-month history', 'ar' => 'سجل 12 شهرًا']],
                    ['included' => false, 'text' => ['fr' => 'Export CSV nuitées', 'en' => 'Overnight stays CSV export', 'ar' => 'تصدير CSV لليالي المبيت']],
                    ['included' => false, 'text' => ['fr' => 'Multi-établissements', 'en' => 'Multi-property', 'ar' => 'تعدد المؤسسات']],
                ],
            ],
            'pro' => [
                'tier'         => ['fr' => 'Pro', 'en' => 'Pro', 'ar' => 'احترافي'],
                'display_name' => ['fr' => 'Professionnel', 'en' => 'Professional', 'ar' => 'المحترف'],
                'tagline'      => [
                    'fr' => "Pour les maisons d'hôtes et petits hôtels avec un flux régulier d'arrivées.",
                    'en' => 'For guest houses and small hotels with a steady flow of arrivals.',
                    'ar' => 'لدور الضيافة والفنادق الصغيرة ذات تدفق منتظم من الوافدين.',
                ],
                'price_note'        => $perProperty,
                'price_note_yearly' => $perPropertyYearly,
                'badge'        => ['fr' => 'Le plus choisi', 'en' => 'Most popular', 'ar' => 'الأكثر اختيارًا'],
                'featured'     => true,
                'cta_label'    => $cta,
                'bullets'      => [
                    ['included' => true,  'text' => ['fr' => '1 établissement', 'en' => '1 property', 'ar' => 'مؤسسة واحدة']],
                    ['included' => true,  'text' => ['fr' => '300 check-ins inclus / mois', 'en' => '300 check-ins included / month', 'ar' => '300 تسجيل وصول مشمول / شهريًا']],
                    ['included' => true,  'text' => ['fr' => 'Scan MRZ passeport & CIN', 'en' => 'Passport & ID MRZ scan', 'ar' => 'مسح MRZ لجواز السفر وبطاقة التعريف']],
                    ['included' => true,  'text' => ['fr' => 'Fiche de police imprimable', 'en' => 'Printable police form', 'ar' => 'بطاقة شرطة قابلة للطباعة']],
                    ['included' => true,  'text' => ['fr' => '5 comptes utilisateurs', 'en' => '5 user accounts', 'ar' => '5 حسابات للمستخدمين']],
                    ['included' => true,  'text' => ['fr' => 'Historique illimité', 'en' => 'Unlimited history', 'ar' => 'سجل غير محدود']],
                    ['included' => true,  'text' => ['fr' => 'Export CSV nuitées', 'en' => 'Overnight stays CSV export', 'ar' => 'تصدير CSV لليالي المبيت']],
                    ['included' => false, 'text' => ['fr' => 'Multi-établissements', 'en' => 'Multi-property', 'ar' => 'تعدد المؤسسات']],
                ],
            ],
            'hotel' => [
                'tier'         => ['fr' => 'Hôtel', 'en' => 'Hotel', 'ar' => 'فندق'],
                'display_name' => ['fr' => 'Grand Flux', 'en' => 'High Volume', 'ar' => 'التدفق الكبير'],
                'tagline'      => [
                    'fr' => "Pour les hôtels à fort volume d'arrivées et les groupes.",
                    'en' => 'For high-volume hotels and groups.',
                    'ar' => 'للفنادق ذات حجم وصول مرتفع وللمجموعات.',
                ],
                'price_note'        => $perProperty,
                'price_note_yearly' => $perPropertyYearly,
                'badge'        => null,
                'featured'     => false,
                'cta_label'    => $cta,
                // L'option multi-établissements (+99 TND/mois par établissement)
                // est rendue automatiquement par la carte publique depuis
                // extra_property_price — pas de bullet dédié pour éviter le doublon.
                'bullets'      => [
                    ['included' => true,  'text' => ['fr' => '1 établissement', 'en' => '1 property', 'ar' => 'مؤسسة واحدة']],
                    ['included' => true,  'text' => ['fr' => '1 000 check-ins inclus / mois', 'en' => '1,000 check-ins included / month', 'ar' => '1000 تسجيل وصول مشمول / شهريًا']],
                    ['included' => true,  'text' => ['fr' => 'Scan MRZ passeport & CIN', 'en' => 'Passport & ID MRZ scan', 'ar' => 'مسح MRZ لجواز السفر وبطاقة التعريف']],
                    ['included' => true,  'text' => ['fr' => 'Fiche de police imprimable', 'en' => 'Printable police form', 'ar' => 'بطاقة شرطة قابلة للطباعة']],
                    ['included' => true,  'text' => ['fr' => 'Comptes utilisateurs illimités', 'en' => 'Unlimited user accounts', 'ar' => 'حسابات مستخدمين غير محدودة']],
                    ['included' => true,  'text' => ['fr' => 'Historique illimité', 'en' => 'Unlimited history', 'ar' => 'سجل غير محدود']],
                    ['included' => true,  'text' => ['fr' => 'Export CSV nuitées', 'en' => 'Overnight stays CSV export', 'ar' => 'تصدير CSV لليالي المبيت']],
                    ['included' => true,  'text' => ['fr' => "Journal d'activité", 'en' => 'Activity log', 'ar' => 'سجل النشاط']],
                    ['included' => true,  'text' => ['fr' => 'Support prioritaire', 'en' => 'Priority support', 'ar' => 'دعم ذو أولوية']],
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
                    'fr' => 'par société / mois',
                    'en' => 'per company / month',
                    'ar' => 'لكل شركة / شهريًا',
                ],
                'price_note_yearly' => [
                    'fr' => 'par société / an · 12 mois au prix de 11',
                    'en' => 'per company / year · 12 months for the price of 11',
                    'ar' => 'لكل شركة / سنويًا · 12 شهرًا بسعر 11',
                ],
                'badge'        => null,
                'featured'     => false,
                'cta_label'    => $cta,
                'bullets'      => [
                    ['included' => true, 'text' => ['fr' => 'Registre consolidé multi-établissements', 'en' => 'Consolidated multi-property register', 'ar' => 'سجل موحد متعدد المؤسسات']],
                    ['included' => true, 'text' => ['fr' => 'Check-ins illimités', 'en' => 'Unlimited check-ins', 'ar' => 'تسجيلات وصول غير محدودة']],
                    ['included' => true, 'text' => ['fr' => 'Équipe illimitée, accès par établissement', 'en' => 'Unlimited team, per-property access', 'ar' => 'فريق غير محدود، وصول لكل مؤسسة']],
                    ['included' => true, 'text' => ['fr' => 'Tableau de bord multi-sites', 'en' => 'Multi-property dashboard', 'ar' => 'لوحة قيادة متعددة المواقع']],
                    ['included' => true, 'text' => ['fr' => "Journal d'activité consolidé", 'en' => 'Consolidated activity log', 'ar' => 'سجل نشاط موحّد']],
                    ['included' => true, 'text' => ['fr' => 'Export CSV multi-établissements', 'en' => 'Multi-property CSV export', 'ar' => 'تصدير CSV متعدد المؤسسات']],
                    ['included' => true, 'text' => ['fr' => 'Support prioritaire', 'en' => 'Priority support', 'ar' => 'دعم ذو أولوية']],
                ],
            ],
        ];
    }

    /** Colonnes/paramètres par slug — même source pour les installs neuves et la migration de données V2. */
    public static function planDefaults(): array {
        return [
            // price_yearly null = règle "1 mois offert" (11 × mensuel) via effective_price_yearly.
            // checkins_per_month : quota mensuel de check-ins inclus (-1 = illimité).
            // Dépassement (grille V3) facturé AU CHECK-IN : overage_bundle_size = 1,
            // overage_price = prix du check-in supplémentaire. La formule de tranche
            // est conservée telle quelle (une tranche de 1 = un check-in), ce qui
            // permet de revenir à une facturation par lot sans changer de code.
            // JAMAIS bloquant : un voyageur doit toujours pouvoir être déclaré.
            // Multi-sites : legacy, non souscriptible (is_public=false), conservé
            // pour ses abonnés existants.
            ['name'=>'Essentiel','slug'=>'essentiel','scope'=>'hotel','min_rooms'=>1,'max_rooms'=>5,'price_monthly'=>59.000,'price_yearly'=>null,'currency'=>'TND','features'=>['max_users'=>2,'ocr_scans_per_month'=>100,'checkins_per_month'=>100],'included_properties'=>1,'extra_property_price'=>null,'overage_price'=>0.600,'overage_bundle_size'=>1,'is_public'=>true,'sort_order'=>1],
            ['name'=>'Pro','slug'=>'pro','scope'=>'hotel','min_rooms'=>6,'max_rooms'=>20,'price_monthly'=>119.000,'price_yearly'=>null,'currency'=>'TND','features'=>['max_users'=>5,'ocr_scans_per_month'=>-1,'checkins_per_month'=>300],'included_properties'=>1,'extra_property_price'=>null,'overage_price'=>0.400,'overage_bundle_size'=>1,'is_public'=>true,'sort_order'=>2],
            ['name'=>'Hôtel','slug'=>'hotel','scope'=>'organization','min_rooms'=>1,'max_rooms'=>null,'price_monthly'=>299.000,'price_yearly'=>null,'currency'=>'TND','features'=>['max_users'=>-1,'ocr_scans_per_month'=>-1,'checkins_per_month'=>1000],'included_properties'=>1,'extra_property_price'=>99.000,'overage_price'=>0.250,'overage_bundle_size'=>1,'is_public'=>true,'sort_order'=>3],
            ['name'=>'Multi-sites','slug'=>'multi-sites','scope'=>'organization','min_rooms'=>1,'max_rooms'=>null,'price_monthly'=>199.000,'price_yearly'=>null,'currency'=>'TND','features'=>['max_users'=>-1,'ocr_scans_per_month'=>-1,'checkins_per_month'=>-1],'included_properties'=>3,'extra_property_price'=>39.000,'overage_price'=>null,'overage_bundle_size'=>null,'is_public'=>false,'sort_order'=>4],
        ];
    }

    public function run(): void {
        $marketing = self::marketingDefaults();
        foreach (self::planDefaults() as $plan) {
            $plan['marketing'] = $marketing[$plan['slug']] ?? null;
            SubscriptionPlan::updateOrCreate(['slug'=>$plan['slug']], $plan);
        }
        $this->command?->info('Subscription plans seeded.');
    }
}
