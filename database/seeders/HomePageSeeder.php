<?php
namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Homepage CMS : reconstruction fidèle de la landing v3 en blocs Puck,
 * en FR (copie d'origine) + EN/AR (traductions rédigées, à relire côté
 * produit avant mise en avant). Idempotent : ne touche pas une page `home`
 * déjà personnalisée dans l'admin (updateOrCreate seulement si absente).
 *
 * Seed aussi les menus navbar/footer par défaut (ancres de la homepage).
 */
class HomePageSeeder extends Seeder
{
    public function run(): void
    {
        if (!Page::where('slug', 'home')->exists()) {
            Page::create([
                'slug'    => 'home',
                'status'  => 'published',
                'content' => ['fr' => $this->content('fr'), 'en' => $this->content('en'), 'ar' => $this->content('ar')],
                'meta'    => [
                    'fr' => ['title' => 'Qayed — Enregistrez vos voyageurs en 30 secondes', 'description' => 'Qayed remplace la fiche de police papier pour les hébergements tunisiens. Scan du passeport ou de la CIN, données extraites automatiquement, fiche prête en 30 secondes.'],
                    'en' => ['title' => 'Qayed — Register your guests in 30 seconds', 'description' => 'Qayed replaces the paper police form for Tunisian accommodations. Scan the passport or ID, data is extracted automatically, the form is ready in 30 seconds.'],
                    'ar' => ['title' => 'قيد — سجّل نزلاءك في 30 ثانية', 'description' => 'قيد يعوّض بطاقة الشرطة الورقية لمؤسسات الإقامة التونسية. امسح جواز السفر أو بطاقة التعريف، تُستخرج البيانات تلقائيًا وتجهز البطاقة في 30 ثانية.'],
                ],
            ]);
            $this->command?->info('Page home (CMS) créée et publiée.');
        } else {
            $this->command?->info('Page home déjà présente — non modifiée.');
        }

        if (MenuItem::count() === 0) {
            $navbar = [
                [['fr' => 'Comment ça marche', 'en' => 'How it works', 'ar' => 'كيف يعمل'], '/#comment'],
                [['fr' => 'Fonctionnalités', 'en' => 'Features', 'ar' => 'الميزات'], '/#fonctionnalites'],
                [['fr' => 'Sécurité', 'en' => 'Security', 'ar' => 'الأمان'], '/#securite'],
                [['fr' => 'Tarifs', 'en' => 'Pricing', 'ar' => 'الأسعار'], '/#tarifs'],
            ];
            foreach ($navbar as $i => [$label, $url]) {
                MenuItem::create(['location' => 'navbar', 'label' => $label, 'external_url' => $url, 'sort_order' => $i + 1]);
            }
            $footer = [
                [['fr' => 'Comment ça marche', 'en' => 'How it works', 'ar' => 'كيف يعمل'], '/#comment'],
                [['fr' => 'Tarifs', 'en' => 'Pricing', 'ar' => 'الأسعار'], '/#tarifs'],
                [['fr' => 'Contact', 'en' => 'Contact', 'ar' => 'اتصل بنا'], 'mailto:contact@qayed.tn'],
            ];
            foreach ($footer as $i => [$label, $url]) {
                MenuItem::create(['location' => 'footer', 'label' => $label, 'external_url' => $url, 'sort_order' => $i + 1]);
            }
            $this->command?->info('Menus navbar/footer seedés.');
        }
    }

    /** Arbre Puck complet de la homepage pour une langue. */
    private function content(string $l): array
    {
        $b = fn(string $type, int $n, array $props) => ['type' => $type, 'props' => array_merge(['id' => "{$type}-home-{$n}"], $props)];
        $T = self::COPY;

        // NB : props non vide — un [] PHP s'encode en tableau JSON, Puck attend un objet.
        return ['root' => ['props' => ['title' => 'Qayed']], 'content' => [
            $b('Hero', 1, [
                'eyebrow'        => $T['hero_eyebrow'][$l],
                'titleLines'     => [
                    ['text' => $T['hero_l1'][$l], 'accent' => false],
                    ['text' => $T['hero_l2'][$l], 'accent' => false],
                    ['text' => $T['hero_l3'][$l], 'accent' => true],
                ],
                'arabicLine'     => 'سجّل نزلاءك رقمياً، بسرعة وبدون أوراق.',
                'description'    => $T['hero_desc'][$l],
                'primaryLabel'   => $T['hero_demo'][$l],
                'primaryHref'    => '#contact',
                'secondaryLabel' => $T['hero_how'][$l],
                'secondaryHref'  => '#comment',
                'mockup'         => 'pwa-checkin',
                'showWave'       => true,
            ]),
            $b('TrustBar', 2, ['items' => array_map(fn($t) => ['text' => $t[$l]], $T['trust'])]),
            $b('StatsBar', 3, ['background' => 'default', 'items' => [
                ['num' => '30', 'sup' => 's', 'label' => $T['stat_30'][$l]],
                ['num' => '0', 'sup' => '', 'label' => $T['stat_0'][$l]],
                ['num' => '3', 'sup' => '', 'label' => $T['stat_3'][$l]],
                ['num' => 'AR+FR', 'sup' => '', 'label' => $T['stat_arfr'][$l]],
            ]]),
            $b('SectionHeading', 4, [
                'anchor' => 'comment', 'background' => 'alt', 'centered' => false,
                'eyebrow' => $T['how_eyebrow'][$l], 'title' => $T['how_title'][$l], 'lead' => $T['how_lead'][$l],
            ]),
            $b('Steps', 5, ['background' => 'alt', 'showScreens' => true, 'items' => [
                ['title' => $T['step1_t'][$l], 'text' => $T['step1_d'][$l]],
                ['title' => $T['step2_t'][$l], 'text' => $T['step2_d'][$l]],
                ['title' => $T['step3_t'][$l], 'text' => $T['step3_d'][$l]],
            ]]),
            $b('SectionHeading', 6, [
                'anchor' => '', 'background' => 'default', 'centered' => false,
                'eyebrow' => $T['who_eyebrow'][$l], 'title' => $T['who_title'][$l], 'lead' => $T['who_lead'][$l],
            ]),
            $b('FeaturesGrid', 7, [
                'variant' => 'audience', 'background' => 'default', 'note' => $T['who_note'][$l],
                'items' => [
                    ['emoji' => '🏨', 'title' => $T['who1_t'][$l], 'text' => $T['who1_d'][$l]],
                    ['emoji' => '🏡', 'title' => $T['who2_t'][$l], 'text' => $T['who2_d'][$l]],
                    ['emoji' => '🏢', 'title' => $T['who3_t'][$l], 'text' => $T['who3_d'][$l]],
                ],
            ]),
            $b('SectionHeading', 8, [
                'anchor' => 'fonctionnalites', 'background' => 'alt', 'centered' => false,
                'eyebrow' => $T['feat_eyebrow'][$l], 'title' => $T['feat_title'][$l], 'lead' => $T['feat_lead'][$l],
            ]),
            $b('FeaturesGrid', 9, [
                'variant' => 'feature', 'background' => 'alt', 'note' => '',
                'items' => [
                    ['emoji' => '📷', 'title' => $T['f1_t'][$l], 'text' => $T['f1_d'][$l]],
                    ['emoji' => '👥', 'title' => $T['f2_t'][$l], 'text' => $T['f2_d'][$l]],
                    ['emoji' => '🏨', 'title' => $T['f3_t'][$l], 'text' => $T['f3_d'][$l]],
                    ['emoji' => '🖨️', 'title' => $T['f4_t'][$l], 'text' => $T['f4_d'][$l]],
                    ['emoji' => '↺', 'title' => $T['f5_t'][$l], 'text' => $T['f5_d'][$l]],
                    ['emoji' => '👤', 'title' => $T['f6_t'][$l], 'text' => $T['f6_d'][$l]],
                ],
            ]),
            $b('FicheShowcase', 10, [
                'background' => 'default',
                'eyebrow' => $T['fiche_eyebrow'][$l], 'title' => $T['fiche_title'][$l],
                'text' => $T['fiche_p1'][$l] . "\n\n" . $T['fiche_p2'][$l],
            ]),
            $b('Security', 11, [
                'anchor' => 'securite', 'showMockup' => true,
                'eyebrow' => $T['sec_eyebrow'][$l], 'title' => $T['sec_title'][$l], 'lead' => $T['sec_lead'][$l],
                'items' => [
                    ['emoji' => '🛡️', 'title' => $T['sec1_t'][$l], 'text' => $T['sec1_d'][$l]],
                    ['emoji' => '📡', 'title' => $T['sec2_t'][$l], 'text' => $T['sec2_d'][$l]],
                    ['emoji' => '📋', 'title' => $T['sec3_t'][$l], 'text' => $T['sec3_d'][$l]],
                ],
            ]),
            $b('Pricing', 12, [
                'eyebrow' => $T['price_eyebrow'][$l], 'title' => $T['price_title'][$l], 'lead' => $T['price_lead'][$l],
                'monthlyLabel' => $T['price_monthly'][$l], 'yearlyLabel' => $T['price_yearly'][$l],
                'yearlyBadge' => $T['price_badge'][$l], 'footnote' => $T['price_note'][$l],
            ]),
            $b('SectionHeading', 13, [
                'anchor' => '', 'background' => 'default', 'centered' => false,
                'eyebrow' => $T['testi_eyebrow'][$l], 'title' => $T['testi_title'][$l], 'lead' => '',
            ]),
            $b('Testimonials', 14, ['background' => 'default', 'items' => [
                ['quote' => $T['t1_q'][$l], 'name' => 'Mohamed Karray', 'role' => $T['t1_r'][$l], 'initials' => 'MK'],
                ['quote' => $T['t2_q'][$l], 'name' => 'Sarra Ben Amor', 'role' => $T['t2_r'][$l], 'initials' => 'SB'],
                ['quote' => $T['t3_q'][$l], 'name' => 'Riadh Ayari', 'role' => $T['t3_r'][$l], 'initials' => 'RA'],
            ]]),
            $b('CtaBand', 15, [
                'anchor' => 'contact',
                'title' => $T['cta_title'][$l], 'text' => $T['cta_sub'][$l],
                'buttonLabel' => $T['cta_btn'][$l], 'buttonHref' => 'mailto:contact@qayed.tn',
                'note' => 'QAYED.TN · KASBAHOST SARL · TUNIS',
            ]),
        ]];
    }

    /** Copie trilingue — FR = texte d'origine de la landing v3. */
    private const COPY = [
        'hero_eyebrow' => ['fr' => 'Hébergements tunisiens · Fiche de police digitale', 'en' => 'Tunisian accommodations · Digital police form', 'ar' => 'مؤسسات الإقامة التونسية · بطاقة شرطة رقمية'],
        'hero_l1' => ['fr' => 'Enregistrez', 'en' => 'Register', 'ar' => 'سجّل'],
        'hero_l2' => ['fr' => 'vos voyageurs', 'en' => 'your guests', 'ar' => 'نزلاءك'],
        'hero_l3' => ['fr' => 'en 30 secondes.', 'en' => 'in 30 seconds.', 'ar' => 'في 30 ثانية.'],
        'hero_desc' => [
            'fr' => 'Qayed remplace la fiche de police papier. Votre équipe photographie le passeport ou la CIN — les données sont extraites automatiquement, la fiche est prête.',
            'en' => 'Qayed replaces the paper police form. Your team photographs the passport or ID card — the data is extracted automatically, the form is ready.',
            'ar' => 'قيد يعوّض بطاقة الشرطة الورقية. يلتقط فريقك صورة لجواز السفر أو بطاقة التعريف — تُستخرج البيانات تلقائيًا وتجهز البطاقة.',
        ],
        'hero_demo' => ['fr' => 'Demander une démo', 'en' => 'Request a demo', 'ar' => 'اطلب عرضًا تجريبيًا'],
        'hero_how' => ['fr' => 'Voir comment ça marche', 'en' => 'See how it works', 'ar' => 'شاهد كيف يعمل'],
        'trust' => [
            ['fr' => 'Passeport & CIN — lecture MRZ automatique', 'en' => 'Passport & ID — automatic MRZ reading', 'ar' => 'جواز السفر وبطاقة التعريف — قراءة MRZ تلقائية'],
            ['fr' => 'Multi-établissements, un seul compte', 'en' => 'Multiple properties, one account', 'ar' => 'عدة مؤسسات، حساب واحد'],
            ['fr' => 'Fiche de police imprimable en 1 clic', 'en' => 'Police form printable in 1 click', 'ar' => 'بطاقة شرطة قابلة للطباعة بنقرة واحدة'],
            ['fr' => 'Interface arabe / français', 'en' => 'Arabic / French interface', 'ar' => 'واجهة بالعربية والفرنسية'],
            ['fr' => 'Aucune installation — fonctionne sur mobile', 'en' => 'No installation — works on mobile', 'ar' => 'دون تثبيت — يعمل على الهاتف'],
        ],
        'stat_30' => ['fr' => 'Du scan du document à la fiche prête', 'en' => 'From document scan to ready form', 'ar' => 'من مسح الوثيقة إلى بطاقة جاهزة'],
        'stat_0' => ['fr' => 'Installation requise — navigateur mobile suffit', 'en' => 'Installation required — a mobile browser is enough', 'ar' => 'تثبيت مطلوب — متصفح الهاتف يكفي'],
        'stat_3' => ['fr' => 'Étapes : Réservation · Documents · Validation', 'en' => 'Steps: Booking · Documents · Validation', 'ar' => 'خطوات: الحجز · الوثائق · التأكيد'],
        'stat_arfr' => ['fr' => 'Bilingue pour toutes vos équipes', 'en' => 'Bilingual for all your teams', 'ar' => 'ثنائي اللغة لجميع فرقك'],
        'how_eyebrow' => ['fr' => 'Comment ça marche', 'en' => 'How it works', 'ar' => 'كيف يعمل'],
        'how_title' => ['fr' => "Trois étapes. Trente secondes.\nZéro paperasse.", 'en' => "Three steps. Thirty seconds.\nZero paperwork.", 'ar' => "ثلاث خطوات. ثلاثون ثانية.\nبدون أوراق."],
        'how_lead' => [
            'fr' => 'Votre réceptionniste ouvre Qayed sur son mobile ou tablette. En trois étapes guidées, le check-in est enregistré et la fiche de police est prête à imprimer.',
            'en' => 'Your receptionist opens Qayed on their phone or tablet. In three guided steps, the check-in is recorded and the police form is ready to print.',
            'ar' => 'يفتح موظف الاستقبال قيد على هاتفه أو جهازه اللوحي. في ثلاث خطوات موجَّهة، يُسجَّل الوصول وتصبح بطاقة الشرطة جاهزة للطباعة.',
        ],
        'step1_t' => ['fr' => 'Informations de réservation', 'en' => 'Booking information', 'ar' => 'معلومات الحجز'],
        'step1_d' => [
            'fr' => "Chambre, dates d'arrivée et de départ, nombre de voyageurs. La référence de réservation (Booking, Airbnb, direct…) est optionnelle.",
            'en' => 'Room, arrival and departure dates, number of guests. The booking reference (Booking, Airbnb, direct…) is optional.',
            'ar' => 'الغرفة، تاريخا الوصول والمغادرة، عدد النزلاء. مرجع الحجز (Booking، Airbnb، مباشر…) اختياري.',
        ],
        'step2_t' => ['fr' => 'Scan des documents', 'en' => 'Document scan', 'ar' => 'مسح الوثائق'],
        'step2_d' => [
            'fr' => 'Photographiez le passeport (zone MRZ) ou la CIN. Les données sont extraites automatiquement — prénom, nom, nationalité, numéro, expiration. Répété pour chaque voyageur.',
            'en' => 'Photograph the passport (MRZ zone) or the ID card. Data is extracted automatically — first name, last name, nationality, number, expiry. Repeated for each guest.',
            'ar' => 'التقط صورة لجواز السفر (منطقة MRZ) أو بطاقة التعريف. تُستخرج البيانات تلقائيًا — الاسم واللقب والجنسية والرقم وتاريخ الانتهاء. تُكرَّر لكل نزيل.',
        ],
        'step3_t' => ['fr' => 'Validation & fiche de police', 'en' => 'Validation & police form', 'ar' => 'التأكيد وبطاقة الشرطة'],
        'step3_d' => [
            'fr' => "Vérifiez les données, confirmez. La fiche de police est générée, archivée, et disponible à l'impression ou en consultation à tout moment.",
            'en' => 'Check the data, confirm. The police form is generated, archived, and available for printing or review at any time.',
            'ar' => 'راجع البيانات وأكّد. تُنشأ بطاقة الشرطة وتُؤرشف وتبقى متاحة للطباعة أو الاطلاع في أي وقت.',
        ],
        'who_eyebrow' => ['fr' => 'Pour qui ?', 'en' => 'Who is it for?', 'ar' => 'لمن؟'],
        'who_title' => ['fr' => 'Tout hébergement qui accueille des voyageurs.', 'en' => 'Any accommodation that hosts travellers.', 'ar' => 'كل مؤسسة إقامة تستقبل مسافرين.'],
        'who_lead' => [
            'fr' => "Hôtels, maisons d'hôtes, auberges, résidences touristiques — Qayed s'adapte à tous les types d'hébergements soumis à l'obligation de la fiche de police en Tunisie.",
            'en' => 'Hotels, guest houses, hostels, tourist residences — Qayed adapts to every type of accommodation subject to the police form requirement in Tunisia.',
            'ar' => 'فنادق، دور ضيافة، نُزل، إقامات سياحية — قيد يتكيّف مع جميع أنواع مؤسسات الإقامة الخاضعة لواجب بطاقة الشرطة في تونس.',
        ],
        'who1_t' => ['fr' => "Hôtels & maisons d'hôtes", 'en' => 'Hotels & guest houses', 'ar' => 'الفنادق ودور الضيافة'],
        'who1_d' => [
            'fr' => 'Votre réceptionniste scanne le document, les données arrivent automatiquement. Fini la saisie à la main et les fiches illisibles.',
            'en' => 'Your receptionist scans the document, the data arrives automatically. No more manual typing and illegible forms.',
            'ar' => 'يمسح موظف الاستقبال الوثيقة فتصل البيانات تلقائيًا. لا مزيد من الإدخال اليدوي والبطاقات غير المقروءة.',
        ],
        'who2_t' => ['fr' => 'Auberges & résidences', 'en' => 'Hostels & residences', 'ar' => 'النُّزل والإقامات'],
        'who2_d' => [
            'fr' => "Que vous gériez 3 chambres ou 30, Qayed s'adapte. Chaque membre d'équipe a son propre accès, l'activité est tracée en temps réel.",
            'en' => 'Whether you manage 3 rooms or 30, Qayed adapts. Each team member has their own access, activity is tracked in real time.',
            'ar' => 'سواء كنت تدير 3 غرف أو 30، قيد يتكيّف. لكل عضو في الفريق وصوله الخاص، ويُتتبَّع النشاط في الوقت الفعلي.',
        ],
        'who3_t' => ['fr' => 'Groupes multi-établissements', 'en' => 'Multi-property groups', 'ar' => 'مجموعات متعددة المؤسسات'],
        'who3_d' => [
            'fr' => "Plusieurs propriétés, un seul compte. Basculez entre vos établissements en un tap, consultez tout l'historique depuis un tableau de bord central.",
            'en' => 'Several properties, one account. Switch between your properties in one tap, review all history from a central dashboard.',
            'ar' => 'عدة عقارات، حساب واحد. بدّل بين مؤسساتك بلمسة واحدة، واطّلع على كامل السجل من لوحة قيادة مركزية.',
        ],
        'who_note' => [
            'fr' => "Les fiches de police enregistrées dans Qayed sont directement accessibles par les services du Ministère de l'Intérieur, en conformité totale avec la réglementation tunisienne — sans aucune démarche supplémentaire de votre part.",
            'en' => 'Police forms recorded in Qayed are directly accessible to the Ministry of the Interior, in full compliance with Tunisian regulations — with no extra steps on your side.',
            'ar' => 'بطاقات الشرطة المسجلة في قيد متاحة مباشرة لمصالح وزارة الداخلية، في امتثال تام للتشريع التونسي — دون أي إجراء إضافي من جهتك.',
        ],
        'feat_eyebrow' => ['fr' => 'Fonctionnalités', 'en' => 'Features', 'ar' => 'الميزات'],
        'feat_title' => ['fr' => 'Tout ce dont votre équipe a besoin.', 'en' => 'Everything your team needs.', 'ar' => 'كل ما يحتاجه فريقك.'],
        'feat_lead' => [
            'fr' => 'Conçu pour la réalité opérationnelle des hébergements tunisiens — simple, rapide, fiable.',
            'en' => 'Built for the operational reality of Tunisian accommodations — simple, fast, reliable.',
            'ar' => 'مصمَّم للواقع التشغيلي لمؤسسات الإقامة التونسية — بسيط وسريع وموثوق.',
        ],
        'f1_t' => ['fr' => 'Scan MRZ automatique', 'en' => 'Automatic MRZ scan', 'ar' => 'مسح MRZ تلقائي'],
        'f1_d' => [
            'fr' => 'Photographiez le passeport ou la CIN. La zone MRZ est lue instantanément : prénom, nom, nationalité, numéro de document, date d\'expiration.',
            'en' => 'Photograph the passport or ID card. The MRZ zone is read instantly: first name, last name, nationality, document number, expiry date.',
            'ar' => 'التقط صورة لجواز السفر أو بطاقة التعريف. تُقرأ منطقة MRZ فورًا: الاسم واللقب والجنسية ورقم الوثيقة وتاريخ الانتهاء.',
        ],
        'f2_t' => ['fr' => 'Groupes & voyageurs multiples', 'en' => 'Groups & multiple guests', 'ar' => 'مجموعات ونزلاء متعددون'],
        'f2_d' => [
            'fr' => 'Enregistrez autant de voyageurs que nécessaire pour un même check-in. Chaque document est scanné et confirmé individuellement.',
            'en' => 'Register as many guests as needed for a single check-in. Each document is scanned and confirmed individually.',
            'ar' => 'سجّل ما تشاء من النزلاء في تسجيل وصول واحد. تُمسح كل وثيقة وتُؤكَّد على حدة.',
        ],
        'f3_t' => ['fr' => 'Multi-établissements', 'en' => 'Multi-property', 'ar' => 'تعدد المؤسسات'],
        'f3_d' => [
            'fr' => 'Gérez tous vos hébergements depuis un seul compte. Basculez entre établissements en un tap, sans vous reconnecter.',
            'en' => 'Manage all your properties from one account. Switch between properties in one tap, without signing in again.',
            'ar' => 'أدر جميع مؤسساتك من حساب واحد. بدّل بين المؤسسات بلمسة، دون إعادة تسجيل الدخول.',
        ],
        'f4_t' => ['fr' => 'Impression fiche de police', 'en' => 'Police form printing', 'ar' => 'طباعة بطاقة الشرطة'],
        'f4_d' => [
            'fr' => "La fiche de police est générée au format réglementaire et imprimable en 1 clic depuis n'importe quel appareil connecté à une imprimante.",
            'en' => 'The police form is generated in the regulatory format and printable in one click from any device connected to a printer.',
            'ar' => 'تُنشأ بطاقة الشرطة بالصيغة القانونية وتُطبع بنقرة واحدة من أي جهاز موصول بطابعة.',
        ],
        'f5_t' => ['fr' => 'Historique & check-out', 'en' => 'History & check-out', 'ar' => 'السجل وتسجيل المغادرة'],
        'f5_d' => [
            'fr' => 'Consultez tous les séjours passés et actifs. Enregistrez les départs en un tap. Filtrez par statut : Actif, Terminé, Brouillon.',
            'en' => 'Review all past and active stays. Record departures in one tap. Filter by status: Active, Completed, Draft.',
            'ar' => 'اطّلع على جميع الإقامات السابقة والجارية. سجّل المغادرات بلمسة. صفِّ حسب الحالة: نشط، منتهٍ، مسودة.',
        ],
        'f6_t' => ['fr' => 'Équipe & rôles', 'en' => 'Team & roles', 'ar' => 'الفريق والأدوار'],
        'f6_d' => [
            'fr' => 'Ajoutez vos réceptionnistes et managers. Chaque action est horodatée et attribuée — vous savez qui a enregistré quoi et quand.',
            'en' => 'Add your receptionists and managers. Every action is timestamped and attributed — you know who recorded what and when.',
            'ar' => 'أضف موظفي الاستقبال والمديرين. كل إجراء مؤرَّخ ومنسوب — تعرف من سجّل ماذا ومتى.',
        ],
        'fiche_eyebrow' => ['fr' => 'Ce que ça donne', 'en' => 'What it looks like', 'ar' => 'هكذا تبدو النتيجة'],
        'fiche_title' => ['fr' => 'Un check-in enregistré ressemble à ça.', 'en' => 'A recorded check-in looks like this.', 'ar' => 'هكذا يبدو تسجيل وصول مكتمل.'],
        'fiche_p1' => [
            'fr' => "Chaque séjour est archivé avec l'identité complète de chaque voyageur, la chambre, les dates, la source de réservation et la personne qui a effectué le check-in.",
            'en' => 'Every stay is archived with the full identity of each guest, the room, the dates, the booking source and the person who performed the check-in.',
            'ar' => 'تُؤرشف كل إقامة مع الهوية الكاملة لكل نزيل، والغرفة، والتواريخ، ومصدر الحجز، ومن قام بتسجيل الوصول.',
        ],
        'fiche_p2' => [
            'fr' => "À tout moment, vous pouvez consulter l'historique, imprimer la fiche ou enregistrer le départ.",
            'en' => 'At any time, you can review the history, print the form or record the departure.',
            'ar' => 'يمكنك في أي وقت الاطلاع على السجل أو طباعة البطاقة أو تسجيل المغادرة.',
        ],
        'sec_eyebrow' => ['fr' => 'Conformité & sécurité', 'en' => 'Compliance & security', 'ar' => 'الامتثال والأمان'],
        'sec_title' => ['fr' => "Les autorités informées.\nVous, tranquille.", 'en' => "Authorities informed.\nYou, at ease.", 'ar' => "السلطات على اطلاع.\nوأنت مطمئن."],
        'sec_lead' => [
            'fr' => "En enregistrant vos voyageurs sur Qayed, vous n'envoyez pas une fiche dans un tiroir. Les données arrivent en temps réel sur le tableau de bord national du Ministère de l'Intérieur — chaque check-in, chaque passeport, chaque départ.",
            'en' => "By registering your guests on Qayed, you are not filing a form in a drawer. The data reaches the Ministry of the Interior's national dashboard in real time — every check-in, every passport, every departure.",
            'ar' => 'بتسجيل نزلائك على قيد، لا تضع بطاقة في درج. تصل البيانات في الوقت الفعلي إلى لوحة القيادة الوطنية لوزارة الداخلية — كل تسجيل وصول، كل جواز سفر، كل مغادرة.',
        ],
        'sec1_t' => ['fr' => 'Vérification automatique watchlist', 'en' => 'Automatic watchlist check', 'ar' => 'فحص تلقائي لقائمة المراقبة'],
        'sec1_d' => [
            'fr' => "Chaque document scanné est automatiquement confronté à la base de surveillance nationale. En cas d'alerte, les autorités sont notifiées immédiatement.",
            'en' => 'Every scanned document is automatically checked against the national watchlist. In case of an alert, the authorities are notified immediately.',
            'ar' => 'تُقارن كل وثيقة ممسوحة تلقائيًا بقاعدة المراقبة الوطنية. عند وجود تنبيه، تُخطَر السلطات فورًا.',
        ],
        'sec2_t' => ['fr' => 'Remontée en temps réel', 'en' => 'Real-time reporting', 'ar' => 'إبلاغ في الوقت الفعلي'],
        'sec2_d' => [
            'fr' => 'Arrivées et départs apparaissent instantanément sur le tableau de bord du Ministère — voyageurs présents, nationalités, établissements actifs.',
            'en' => "Arrivals and departures appear instantly on the Ministry's dashboard — guests present, nationalities, active properties.",
            'ar' => 'تظهر عمليات الوصول والمغادرة فورًا على لوحة قيادة الوزارة — النزلاء الحاضرون والجنسيات والمؤسسات النشطة.',
        ],
        'sec3_t' => ['fr' => 'Zéro démarche supplémentaire', 'en' => 'Zero extra steps', 'ar' => 'صفر إجراءات إضافية'],
        'sec3_d' => [
            'fr' => 'Votre réceptionniste fait son check-in normalement. La conformité réglementaire est assurée automatiquement, en arrière-plan.',
            'en' => 'Your receptionist performs the check-in as usual. Regulatory compliance is handled automatically, in the background.',
            'ar' => 'يقوم موظف الاستقبال بتسجيل الوصول كالمعتاد. يُضمن الامتثال القانوني تلقائيًا في الخلفية.',
        ],
        'price_eyebrow' => ['fr' => 'Abonnement', 'en' => 'Subscription', 'ar' => 'الاشتراك'],
        'price_title' => ['fr' => 'Simple et transparent.', 'en' => 'Simple and transparent.', 'ar' => 'بسيط وشفاف.'],
        'price_lead' => [
            'fr' => 'Sans engagement. Sans frais cachés. Changez de plan à tout moment.',
            'en' => 'No commitment. No hidden fees. Change plans at any time.',
            'ar' => 'دون التزام. دون رسوم خفية. غيّر باقتك في أي وقت.',
        ],
        'price_monthly' => ['fr' => 'Mensuel', 'en' => 'Monthly', 'ar' => 'شهري'],
        'price_yearly' => ['fr' => 'Annuel', 'en' => 'Yearly', 'ar' => 'سنوي'],
        'price_badge' => ['fr' => '1 mois offert', 'en' => '1 month free', 'ar' => 'شهر مجاني'],
        'price_note' => [
            'fr' => "Aucune carte bancaire requise pour démarrer l'essai · Résiliable à tout moment",
            'en' => 'No credit card required to start the trial · Cancel anytime',
            'ar' => 'لا حاجة لبطاقة بنكية لبدء التجربة · يمكن الإلغاء في أي وقت',
        ],
        'testi_eyebrow' => ['fr' => 'Témoignages', 'en' => 'Testimonials', 'ar' => 'شهادات'],
        'testi_title' => ['fr' => 'Ce que disent les hôteliers.', 'en' => 'What hoteliers say.', 'ar' => 'ماذا يقول أصحاب الفنادق.'],
        't1_q' => [
            'fr' => 'En deux semaines, on a totalement éliminé les fiches papier. La réception gagne un temps fou à chaque check-in, et je retrouve n\'importe quelle fiche en 10 secondes.',
            'en' => 'In two weeks we completely eliminated paper forms. Reception saves a huge amount of time on every check-in, and I can find any form in 10 seconds.',
            'ar' => 'في أسبوعين تخلّصنا تمامًا من البطاقات الورقية. الاستقبال يوفّر وقتًا كبيرًا في كل تسجيل وصول، وأجد أي بطاقة في 10 ثوانٍ.',
        ],
        't1_r' => ['fr' => 'Directeur · Hôtel Médina, Tunis', 'en' => 'Director · Hôtel Médina, Tunis', 'ar' => 'مدير · فندق المدينة، تونس'],
        't2_q' => [
            'fr' => "Avec plusieurs maisons d'hôtes à gérer, Qayed m'a permis de tout centraliser. Je vois les arrivées du jour sur toutes mes propriétés depuis mon téléphone.",
            'en' => 'With several guest houses to manage, Qayed let me centralise everything. I see the day\'s arrivals across all my properties from my phone.',
            'ar' => 'مع عدة دور ضيافة أديرها، مكّنني قيد من مركزة كل شيء. أرى وافدي اليوم في جميع عقاراتي من هاتفي.',
        ],
        't2_r' => ['fr' => 'Gérante · Groupe Dars Médina, Tunis', 'en' => 'Manager · Groupe Dars Médina, Tunis', 'ar' => 'مسيّرة · مجموعة ديار المدينة، تونس'],
        't3_q' => [
            'fr' => 'Le scan du passeport est bluffant — 10 secondes et tous les champs sont remplis. Déploiement en une journée, mes réceptionnistes ont pris en main en 15 minutes.',
            'en' => 'The passport scan is stunning — 10 seconds and every field is filled. Deployed in a day, my receptionists got the hang of it in 15 minutes.',
            'ar' => 'مسح الجواز مذهل — 10 ثوانٍ وتمتلئ كل الخانات. تم النشر في يوم واحد، وأتقنه موظفو الاستقبال في 15 دقيقة.',
        ],
        't3_r' => ['fr' => 'Responsable ops · Résidence Jasmin, Hammamet', 'en' => 'Ops manager · Résidence Jasmin, Hammamet', 'ar' => 'مسؤول العمليات · إقامة الياسمين، الحمامات'],
        'cta_title' => ['fr' => "Votre premier check-in\nen moins de 5 minutes.", 'en' => "Your first check-in\nin under 5 minutes.", 'ar' => "أول تسجيل وصول لك\nفي أقل من 5 دقائق."],
        'cta_sub' => [
            'fr' => 'Démo sur demande. Déploiement en une journée. Sans engagement.',
            'en' => 'Demo on request. Deployed in a day. No commitment.',
            'ar' => 'عرض تجريبي عند الطلب. نشر في يوم واحد. دون التزام.',
        ],
        'cta_btn' => ['fr' => 'Écrire à contact@qayed.tn', 'en' => 'Write to contact@qayed.tn', 'ar' => 'راسل contact@qayed.tn'],
    ];
}
