<?php
namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Homepage CMS : arbre Puck de la landing en FR + EN/AR.
 *
 * v2 (août 2026) — refonte sobre et éditoriale :
 *  - plus aucun emoji (les blocs à icône ont disparu du catalogue Puck) ;
 *  - la section « Conformité & sécurité » et sa maquette de tableau de bord
 *    Ministère sont remplacées par une section « Conformité » factuelle en
 *    deux points (notification WhatsApp, export/impression) ;
 *  - témoignages, chiffres non sourcés et affirmations d'accès direct des
 *    services de l'État retirés ;
 *  - l'entité juridique ne figure plus dans l'habillage marketing (elle reste
 *    dans les pages légales, cf. LegalPagesSeeder).
 *
 * BASCULE DE VERSION — le seeder tourne à chaque démarrage du conteneur
 * (docker/qayed-start). Il ne réécrit la page `home` que si son contenu
 * correspond encore, au caractère près, à une version seedée : dans ce cas
 * personne ne l'a éditée dans l'admin et la refonte peut s'appliquer seule.
 * Dès qu'une main humaine est passée dessus, la page est laissée intacte et
 * le seeder l'annonce (utiliser `php artisan qayed:refresh-home --force`
 * pour forcer la réécriture en connaissance de cause).
 */
class HomePageSeeder extends Seeder
{
    /**
     * Empreintes des contenus déjà servis par ce seeder (versions successives).
     * Une page dont l'empreinte figure ici n'a jamais été modifiée à la main.
     * v1 — landing d'origine (juillet 2026), calculée sur le contenu du seeder
     * tel qu'il était avant la refonte.
     */
    private const SEEDED_FINGERPRINTS = [
        'a2ac50d1c0350a4f79cc81a6ee3615bfdf179b027e0de3a77c51ad7a5d847345', // v1
    ];

    public function run(): void
    {
        $this->syncHomePage();
        $this->syncMenus();
    }

    /** Contenu + méta trilingues de la page d'accueil. */
    public function homeAttributes(): array
    {
        return [
            'status'  => 'published',
            'content' => ['fr' => $this->content('fr'), 'en' => $this->content('en'), 'ar' => $this->content('ar')],
            'meta'    => [
                'fr' => ['title' => 'Qayed — Enregistrez vos voyageurs en 30 secondes', 'description' => "Qayed remplace la fiche de police papier pour les hébergements tunisiens. Scan du passeport ou de la CIN, fiche prête en 30 secondes, imprimable et envoyée par WhatsApp."],
                'en' => ['title' => 'Qayed — Register your guests in 30 seconds', 'description' => 'Qayed replaces the paper police form for Tunisian accommodations. Passport or ID scan, form ready in 30 seconds, printable and sent over WhatsApp.'],
                'ar' => ['title' => 'قيد — سجّل نزلاءك في 30 ثانية', 'description' => 'قيد يعوّض بطاقة الشرطة الورقية لمؤسسات الإقامة التونسية. امسح جواز السفر أو بطاقة التعريف، وتجهز البطاقة في 30 ثانية قابلة للطباعة وتُرسل عبر واتساب.'],
            ],
        ];
    }

    /**
     * Empreinte stable d'un contenu Puck. Les clés sont triées récursivement :
     * `content` est stocké en jsonb, qui ne préserve pas l'ordre d'insertion,
     * donc comparer les JSON bruts donnerait des faux négatifs.
     */
    public static function fingerprint(?array $content): string
    {
        return hash('sha256', json_encode(self::sortDeep($content ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function sortDeep(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::sortDeep($v);
        }
        if (! array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }

    /** Crée la page, ou la met à jour si elle n'a jamais été éditée à la main. */
    private function syncHomePage(): void
    {
        $attributes = $this->homeAttributes();
        $page = Page::where('slug', 'home')->first();

        if (! $page) {
            Page::create(['slug' => 'home'] + $attributes);
            $this->command?->info('Page home (CMS) créée et publiée.');

            return;
        }

        $current = self::fingerprint($page->content);

        if ($current === self::fingerprint($attributes['content'])) {
            $this->command?->info('Page home déjà à jour.');

            return;
        }

        if (! in_array($current, self::SEEDED_FINGERPRINTS, true)) {
            $this->command?->warn('Page home personnalisée dans l\'admin — laissée intacte. Pour appliquer la version du seeder : php artisan qayed:refresh-home --force');

            return;
        }

        $page->update($attributes);
        $this->command?->info('Page home mise à jour (contenu encore identique au seed précédent).');
    }

    /**
     * Menus par défaut. Les crée si aucun n'existe ; sinon se contente de
     * renommer l'entrée « Sécurité » (ancre #securite, section supprimée) en
     * « Conformité », et seulement si elle porte encore sa valeur seedée.
     */
    private function syncMenus(): void
    {
        if (MenuItem::count() === 0) {
            $navbar = [
                [['fr' => 'Comment ça marche', 'en' => 'How it works', 'ar' => 'كيف يعمل'], '/#comment'],
                [['fr' => 'Fonctionnalités', 'en' => 'Features', 'ar' => 'الميزات'], '/#fonctionnalites'],
                [['fr' => 'Conformité', 'en' => 'Compliance', 'ar' => 'الامتثال'], '/#conformite'],
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

            return;
        }

        $legacy = MenuItem::where('external_url', '/#securite')->get()
            ->filter(fn (MenuItem $item) => ($item->label['fr'] ?? null) === 'Sécurité');

        foreach ($legacy as $item) {
            $item->update([
                'external_url' => '/#conformite',
                'label'        => ['fr' => 'Conformité', 'en' => 'Compliance', 'ar' => 'الامتثال'],
            ]);
            $this->command?->info('Menu « Sécurité » renommé en « Conformité » (#conformite).');
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
            ]),
            $b('TrustBar', 2, ['items' => array_map(fn($t) => ['text' => $t[$l]], $T['trust'])]),
            $b('SectionHeading', 3, [
                'anchor' => 'comment', 'background' => 'alt', 'centered' => false,
                'eyebrow' => $T['how_eyebrow'][$l], 'title' => $T['how_title'][$l], 'lead' => $T['how_lead'][$l],
            ]),
            $b('Steps', 4, ['background' => 'alt', 'showScreens' => true, 'items' => [
                ['title' => $T['step1_t'][$l], 'text' => $T['step1_d'][$l]],
                ['title' => $T['step2_t'][$l], 'text' => $T['step2_d'][$l]],
                ['title' => $T['step3_t'][$l], 'text' => $T['step3_d'][$l]],
            ]]),
            $b('SectionHeading', 5, [
                'anchor' => '', 'background' => 'default', 'centered' => false,
                'eyebrow' => $T['who_eyebrow'][$l], 'title' => $T['who_title'][$l], 'lead' => $T['who_lead'][$l],
            ]),
            $b('DefinitionList', 6, [
                'columns' => 'two', 'background' => 'default', 'note' => '',
                'items' => [
                    ['title' => $T['who1_t'][$l], 'text' => $T['who1_d'][$l]],
                    ['title' => $T['who2_t'][$l], 'text' => $T['who2_d'][$l]],
                    ['title' => $T['who3_t'][$l], 'text' => $T['who3_d'][$l]],
                ],
            ]),
            $b('SectionHeading', 7, [
                'anchor' => 'fonctionnalites', 'background' => 'alt', 'centered' => false,
                'eyebrow' => $T['feat_eyebrow'][$l], 'title' => $T['feat_title'][$l], 'lead' => $T['feat_lead'][$l],
            ]),
            $b('DefinitionList', 8, [
                'columns' => 'two', 'background' => 'alt', 'note' => '',
                'items' => [
                    ['title' => $T['f1_t'][$l], 'text' => $T['f1_d'][$l]],
                    ['title' => $T['f2_t'][$l], 'text' => $T['f2_d'][$l]],
                    ['title' => $T['f3_t'][$l], 'text' => $T['f3_d'][$l]],
                    ['title' => $T['f4_t'][$l], 'text' => $T['f4_d'][$l]],
                    ['title' => $T['f5_t'][$l], 'text' => $T['f5_d'][$l]],
                    ['title' => $T['f6_t'][$l], 'text' => $T['f6_d'][$l]],
                    ['title' => $T['f7_t'][$l], 'text' => $T['f7_d'][$l]],
                    ['title' => $T['f8_t'][$l], 'text' => $T['f8_d'][$l]],
                ],
            ]),
            $b('FicheShowcase', 9, [
                'background' => 'default',
                'eyebrow' => $T['fiche_eyebrow'][$l], 'title' => $T['fiche_title'][$l],
                'text' => $T['fiche_p1'][$l] . "\n\n" . $T['fiche_p2'][$l],
            ]),
            $b('Compliance', 10, [
                'anchor' => 'conformite',
                'eyebrow' => $T['conf_eyebrow'][$l], 'title' => $T['conf_title'][$l], 'lead' => $T['conf_lead'][$l],
                'note' => $T['conf_note'][$l],
                'items' => [
                    ['title' => $T['conf1_t'][$l], 'text' => $T['conf1_d'][$l]],
                    ['title' => $T['conf2_t'][$l], 'text' => $T['conf2_d'][$l]],
                ],
            ]),
            $b('Pricing', 11, [
                'eyebrow' => $T['price_eyebrow'][$l], 'title' => $T['price_title'][$l], 'lead' => $T['price_lead'][$l],
                'monthlyLabel' => $T['price_monthly'][$l], 'yearlyLabel' => $T['price_yearly'][$l],
                'yearlyBadge' => $T['price_badge'][$l], 'footnote' => $T['price_note'][$l],
            ]),
            $b('CtaBand', 12, [
                'anchor' => 'contact',
                'title' => $T['cta_title'][$l], 'text' => $T['cta_sub'][$l],
                'buttonLabel' => $T['cta_btn'][$l], 'buttonHref' => 'mailto:contact@qayed.tn',
                'note' => 'QAYED.TN · TUNIS',
            ]),
        ]];
    }

    /** Copie trilingue. FR = référence ; EN/AR traduits à partir du FR. */
    private const COPY = [
        'hero_eyebrow' => ['fr' => 'Hébergements tunisiens · Fiche de police digitale', 'en' => 'Tunisian accommodations · Digital police form', 'ar' => 'مؤسسات الإقامة التونسية · بطاقة شرطة رقمية'],
        'hero_l1' => ['fr' => 'Enregistrez', 'en' => 'Register', 'ar' => 'سجّل'],
        'hero_l2' => ['fr' => 'vos voyageurs', 'en' => 'your guests', 'ar' => 'نزلاءك'],
        'hero_l3' => ['fr' => 'en 30 secondes.', 'en' => 'in 30 seconds.', 'ar' => 'في 30 ثانية.'],
        'hero_desc' => [
            'fr' => "Votre équipe photographie le passeport ou la CIN. Les données sont extraites automatiquement, la fiche de police est prête, imprimable et envoyée par WhatsApp.",
            'en' => 'Your team photographs the passport or ID card. The data is extracted automatically; the police form is ready, printable and sent over WhatsApp.',
            'ar' => 'يلتقط فريقك صورة لجواز السفر أو بطاقة التعريف. تُستخرج البيانات تلقائيًا، وتصبح بطاقة الشرطة جاهزة للطباعة وتُرسَل عبر واتساب.',
        ],
        'hero_demo' => ['fr' => 'Demander une démo', 'en' => 'Request a demo', 'ar' => 'اطلب عرضًا تجريبيًا'],
        'hero_how' => ['fr' => 'Voir comment ça marche', 'en' => 'See how it works', 'ar' => 'شاهد كيف يعمل'],
        'trust' => [
            ['fr' => 'Scan MRZ passeport & CIN', 'en' => 'Passport & ID MRZ scan', 'ar' => 'مسح MRZ للجواز والبطاقة'],
            ['fr' => 'Multi-établissements', 'en' => 'Multi-property', 'ar' => 'تعدد المؤسسات'],
            ['fr' => 'Fiche imprimable', 'en' => 'Printable form', 'ar' => 'بطاقة قابلة للطباعة'],
            ['fr' => 'Notification WhatsApp', 'en' => 'WhatsApp notification', 'ar' => 'إشعار واتساب'],
            ['fr' => 'Arabe & français', 'en' => 'Arabic & French', 'ar' => 'العربية والفرنسية'],
        ],
        'how_eyebrow' => ['fr' => 'Comment ça marche', 'en' => 'How it works', 'ar' => 'كيف يعمل'],
        'how_title' => ['fr' => "Trois étapes.\nTrente secondes.", 'en' => "Three steps.\nThirty seconds.", 'ar' => "ثلاث خطوات.\nثلاثون ثانية."],
        'how_lead' => [
            'fr' => "Votre réceptionniste ouvre Qayed sur son mobile ou sa tablette. En trois étapes guidées, le check-in est enregistré et la fiche de police est prête.",
            'en' => 'Your receptionist opens Qayed on their phone or tablet. In three guided steps, the check-in is recorded and the police form is ready.',
            'ar' => 'يفتح موظف الاستقبال قيد على هاتفه أو جهازه اللوحي. في ثلاث خطوات موجَّهة، يُسجَّل الوصول وتصبح بطاقة الشرطة جاهزة.',
        ],
        'step1_t' => ['fr' => 'Informations de réservation', 'en' => 'Booking information', 'ar' => 'معلومات الحجز'],
        'step1_d' => [
            'fr' => "Chambre, dates d'arrivée et de départ, nombre de voyageurs. La référence de réservation (Booking, Airbnb, direct…) est optionnelle.",
            'en' => 'Room, arrival and departure dates, number of guests. The booking reference (Booking, Airbnb, direct…) is optional.',
            'ar' => 'الغرفة، تاريخا الوصول والمغادرة، عدد النزلاء. مرجع الحجز (Booking، Airbnb، مباشر…) اختياري.',
        ],
        'step2_t' => ['fr' => 'Scan des documents', 'en' => 'Document scan', 'ar' => 'مسح الوثائق'],
        'step2_d' => [
            'fr' => "Photographiez le passeport (zone MRZ) ou la CIN. Prénom, nom, nationalité, numéro et date d'expiration sont extraits automatiquement, pour chaque voyageur.",
            'en' => 'Photograph the passport (MRZ zone) or the ID card. First name, last name, nationality, number and expiry date are extracted automatically, for each guest.',
            'ar' => 'التقط صورة لجواز السفر (منطقة MRZ) أو بطاقة التعريف. تُستخرج تلقائيًا الاسم واللقب والجنسية والرقم وتاريخ الانتهاء، لكل نزيل.',
        ],
        'step3_t' => ['fr' => 'Validation & fiche de police', 'en' => 'Validation & police form', 'ar' => 'التأكيد وبطاقة الشرطة'],
        'step3_d' => [
            'fr' => "Vérifiez les données, confirmez. La fiche de police est générée, archivée, envoyée par WhatsApp au destinataire configuré et disponible à l'impression.",
            'en' => 'Check the data, confirm. The police form is generated, archived, sent over WhatsApp to the configured recipient and available for printing.',
            'ar' => 'راجع البيانات وأكّد. تُنشأ بطاقة الشرطة وتُؤرشف وتُرسَل عبر واتساب إلى المرسَل إليه المحدَّد، وتبقى متاحة للطباعة.',
        ],
        'who_eyebrow' => ['fr' => 'Pour qui ?', 'en' => 'Who is it for?', 'ar' => 'لمن؟'],
        'who_title' => ['fr' => 'Tout hébergement qui accueille des voyageurs.', 'en' => 'Any accommodation that hosts travellers.', 'ar' => 'كل مؤسسة إقامة تستقبل مسافرين.'],
        'who_lead' => [
            'fr' => "Hôtels, maisons d'hôtes, auberges, résidences touristiques et groupes multi-établissements — tous les hébergements soumis à l'obligation de la fiche de police en Tunisie.",
            'en' => 'Hotels, guest houses, hostels, tourist residences and multi-property groups — every accommodation subject to the police form requirement in Tunisia.',
            'ar' => 'فنادق ودور ضيافة ونُزل وإقامات سياحية ومجموعات متعددة المؤسسات — كل مؤسسات الإقامة الخاضعة لواجب بطاقة الشرطة في تونس.',
        ],
        'who1_t' => ['fr' => "Hôtels & maisons d'hôtes", 'en' => 'Hotels & guest houses', 'ar' => 'الفنادق ودور الضيافة'],
        'who1_d' => [
            'fr' => 'Le réceptionniste scanne le document, les données arrivent seules. Fini la saisie à la main et les fiches illisibles.',
            'en' => 'The receptionist scans the document, the data arrives on its own. No more manual typing and illegible forms.',
            'ar' => 'يمسح موظف الاستقبال الوثيقة فتصل البيانات وحدها. لا مزيد من الإدخال اليدوي والبطاقات غير المقروءة.',
        ],
        'who2_t' => ['fr' => 'Auberges & résidences', 'en' => 'Hostels & residences', 'ar' => 'النُّزل والإقامات'],
        'who2_d' => [
            'fr' => "Trois chambres ou trente : chaque membre de l'équipe a son propre accès et l'activité est tracée.",
            'en' => 'Three rooms or thirty: each team member has their own access and activity is tracked.',
            'ar' => 'ثلاث غرف أو ثلاثون: لكل عضو في الفريق وصوله الخاص، والنشاط مُتتبَّع.',
        ],
        'who3_t' => ['fr' => 'Groupes multi-établissements', 'en' => 'Multi-property groups', 'ar' => 'مجموعات متعددة المؤسسات'],
        'who3_d' => [
            'fr' => "Plusieurs propriétés, un seul compte. On bascule d'un établissement à l'autre sans se reconnecter.",
            'en' => 'Several properties, one account. Switch from one to another without signing in again.',
            'ar' => 'عدة عقارات، حساب واحد. تنتقل من مؤسسة إلى أخرى دون إعادة تسجيل الدخول.',
        ],
        'feat_eyebrow' => ['fr' => 'Fonctionnalités', 'en' => 'Features', 'ar' => 'الميزات'],
        'feat_title' => ['fr' => 'Tout ce dont votre équipe a besoin.', 'en' => 'Everything your team needs.', 'ar' => 'كل ما يحتاجه فريقك.'],
        'feat_lead' => [
            'fr' => 'Conçu pour la réalité opérationnelle des hébergements tunisiens — simple, rapide, fiable.',
            'en' => 'Built for the operational reality of Tunisian accommodations — simple, fast, reliable.',
            'ar' => 'مصمَّم للواقع التشغيلي لمؤسسات الإقامة التونسية — بسيط وسريع وموثوق.',
        ],
        'f1_t' => ['fr' => 'Scan MRZ passeport & CIN', 'en' => 'Passport & ID MRZ scan', 'ar' => 'مسح MRZ للجواز وبطاقة التعريف'],
        'f1_d' => [
            'fr' => "Photographiez le document : prénom, nom, nationalité, numéro et date d'expiration sont lus automatiquement.",
            'en' => 'Photograph the document: first name, last name, nationality, number and expiry date are read automatically.',
            'ar' => 'التقط صورة للوثيقة: يُقرأ تلقائيًا الاسم واللقب والجنسية والرقم وتاريخ الانتهاء.',
        ],
        'f2_t' => ['fr' => 'Voyageurs multiples', 'en' => 'Multiple guests', 'ar' => 'نزلاء متعددون'],
        'f2_d' => [
            'fr' => 'Autant de voyageurs que nécessaire pour un même séjour, chaque document confirmé individuellement.',
            'en' => 'As many guests as needed for a single stay, each document confirmed individually.',
            'ar' => 'ما تشاء من النزلاء في إقامة واحدة، مع تأكيد كل وثيقة على حدة.',
        ],
        'f3_t' => ['fr' => 'Multi-établissements', 'en' => 'Multi-property', 'ar' => 'تعدد المؤسسات'],
        'f3_d' => [
            'fr' => 'Tous vos hébergements sur un seul compte, bascule en un tap sans reconnexion.',
            'en' => 'All your properties on one account, switch in one tap without signing in again.',
            'ar' => 'كل مؤسساتك في حساب واحد، والتبديل بلمسة دون إعادة تسجيل الدخول.',
        ],
        'f4_t' => ['fr' => 'Impression de la fiche', 'en' => 'Form printing', 'ar' => 'طباعة البطاقة'],
        'f4_d' => [
            'fr' => "Fiche au format réglementaire, imprimable en un clic depuis n'importe quel appareil relié à une imprimante.",
            'en' => 'Form in the regulatory format, printable in one click from any device connected to a printer.',
            'ar' => 'بطاقة بالصيغة القانونية، تُطبع بنقرة واحدة من أي جهاز موصول بطابعة.',
        ],
        'f5_t' => ['fr' => 'Notification WhatsApp', 'en' => 'WhatsApp notification', 'ar' => 'إشعار واتساب'],
        'f5_d' => [
            'fr' => "À la validation du check-in, la fiche part automatiquement au destinataire configuré par l'établissement.",
            'en' => 'On check-in validation, the form is sent automatically to the recipient configured by the property.',
            'ar' => 'عند تأكيد تسجيل الوصول، تُرسَل البطاقة تلقائيًا إلى المرسَل إليه الذي حدَّدته المؤسسة.',
        ],
        'f6_t' => ['fr' => 'Historique & check-out', 'en' => 'History & check-out', 'ar' => 'السجل وتسجيل المغادرة'],
        'f6_d' => [
            'fr' => 'Séjours passés et en cours, départs enregistrés en un tap, filtres par statut.',
            'en' => 'Past and current stays, departures recorded in one tap, filters by status.',
            'ar' => 'الإقامات السابقة والجارية، وتسجيل المغادرة بلمسة، وتصفية حسب الحالة.',
        ],
        'f7_t' => ['fr' => 'Équipe & rôles', 'en' => 'Team & roles', 'ar' => 'الفريق والأدوار'],
        'f7_d' => [
            'fr' => 'Réceptionnistes et managers, chaque action horodatée et attribuée à son auteur.',
            'en' => 'Receptionists and managers, every action timestamped and attributed to its author.',
            'ar' => 'موظفو الاستقبال والمديرون، وكل إجراء مؤرَّخ ومنسوب إلى صاحبه.',
        ],
        'f8_t' => ['fr' => 'Interface arabe & français', 'en' => 'Arabic & French interface', 'ar' => 'واجهة بالعربية والفرنسية'],
        'f8_d' => [
            'fr' => "Toute l'application dans la langue de chaque membre de l'équipe, écriture de droite à gauche comprise.",
            'en' => 'The whole application in each team member’s language, right-to-left writing included.',
            'ar' => 'التطبيق بأكمله بلغة كل عضو في الفريق، بما في ذلك الكتابة من اليمين إلى اليسار.',
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
        'conf_eyebrow' => ['fr' => 'Conformité', 'en' => 'Compliance', 'ar' => 'الامتثال'],
        'conf_title' => ['fr' => 'La fiche part au moment du check-in.', 'en' => 'The form leaves at check-in time.', 'ar' => 'البطاقة تنطلق لحظة تسجيل الوصول.'],
        'conf_lead' => [
            'fr' => 'Deux mécanismes, aucun geste supplémentaire pour votre réception.',
            'en' => 'Two mechanisms, no extra step for your front desk.',
            'ar' => 'آليتان، دون أي خطوة إضافية على مكتب الاستقبال.',
        ],
        'conf1_t' => ['fr' => 'Notification WhatsApp automatique', 'en' => 'Automatic WhatsApp notification', 'ar' => 'إشعار واتساب تلقائي'],
        'conf1_d' => [
            'fr' => "Dès qu'un check-in est validé, la fiche de police est envoyée par WhatsApp au destinataire configuré par l'établissement. Plus d'oubli, plus de déplacement.",
            'en' => 'As soon as a check-in is validated, the police form is sent over WhatsApp to the recipient configured by the property. Nothing forgotten, no trip to make.',
            'ar' => 'بمجرد تأكيد تسجيل الوصول، تُرسَل بطاقة الشرطة عبر واتساب إلى المرسَل إليه الذي حدَّدته المؤسسة. لا نسيان ولا تنقّل.',
        ],
        'conf2_t' => ['fr' => 'Export & impression des fiches', 'en' => 'Form export & printing', 'ar' => 'تصدير البطاقات وطباعتها'],
        'conf2_d' => [
            'fr' => "Chaque fiche est générée au format réglementaire tunisien, imprimable en un clic et archivée. L'historique reste consultable et exportable à tout moment.",
            'en' => 'Every form is generated in the Tunisian regulatory format, printable in one click and archived. The history stays available and exportable at any time.',
            'ar' => 'تُنشأ كل بطاقة بالصيغة القانونية التونسية، قابلة للطباعة بنقرة واحدة ومؤرشفة. ويبقى السجل متاحًا للاطلاع والتصدير في أي وقت.',
        ],
        'conf_note' => [
            'fr' => "L'établissement reste responsable de la transmission de ses fiches de police. Qayed la rend instantanée et traçable.",
            'en' => 'The property remains responsible for transmitting its police forms. Qayed makes that transmission instant and traceable.',
            'ar' => 'تبقى المؤسسة مسؤولة عن إرسال بطاقات الشرطة الخاصة بها. ويجعل قيد هذا الإرسال فوريًا وقابلًا للتتبع.',
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
        'cta_title' => ['fr' => "Votre premier check-in\nen moins de 5 minutes.", 'en' => "Your first check-in\nin under 5 minutes.", 'ar' => "أول تسجيل وصول لك\nفي أقل من 5 دقائق."],
        'cta_sub' => [
            'fr' => 'Démo sur demande. Déploiement en une journée. Sans engagement.',
            'en' => 'Demo on request. Deployed in a day. No commitment.',
            'ar' => 'عرض تجريبي عند الطلب. نشر في يوم واحد. دون التزام.',
        ],
        'cta_btn' => ['fr' => 'Écrire à contact@qayed.tn', 'en' => 'Write to contact@qayed.tn', 'ar' => 'راسل contact@qayed.tn'],
    ];
}
