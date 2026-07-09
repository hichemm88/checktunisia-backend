<?php
namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Pages légales obligatoires (module de paiement en ligne) : mentions
 * légales + CGV, adaptées à l'activité réelle (abonnements SaaS Qayed,
 * essai 7 jours, paiement CB/virement) avec l'identité UW AGENCY SUARL.
 * FR = référence ; EN/AR = traductions. Idempotent : une page existante
 * n'est jamais écrasée (modifiable dans l'admin).
 */
class LegalPagesSeeder extends Seeder
{
    private const IDENTITY = [
        'fr' => "Raison sociale : UW AGENCY SUARL\n\nForme juridique : SUARL (société unipersonnelle à responsabilité limitée)\n\nSiège social : 2 rue Abdallah el Mehdi – Carthage Byrsa 2016 – Tunisie\n\nMatricule fiscal : 1715656S\n\nReprésentant légal : Hichem Mathlouthi\n\nE-mail : hichemmathlouthi@gmail.com\n\nTéléphone : +216 93 116 000\n\nSite web : www.qayed.tn",
        'en' => "Company name: UW AGENCY SUARL\n\nLegal form: SUARL (single-member limited liability company)\n\nRegistered office: 2 rue Abdallah el Mehdi – Carthage Byrsa 2016 – Tunisia\n\nTax ID: 1715656S\n\nLegal representative: Hichem Mathlouthi\n\nE-mail: hichemmathlouthi@gmail.com\n\nPhone: +216 93 116 000\n\nWebsite: www.qayed.tn",
        'ar' => "الاسم الاجتماعي: UW AGENCY SUARL\n\nالشكل القانوني: شركة الشخص الواحد ذات المسؤولية المحدودة (SUARL)\n\nالمقر الاجتماعي: 2 نهج عبد الله المهدي – قرطاج بيرصا 2016 – تونس\n\nالمعرف الجبائي: 1715656S\n\nالممثل القانوني: هشام المثلوثي\n\nالبريد الإلكتروني: hichemmathlouthi@gmail.com\n\nالهاتف: 000 116 93 216+\n\nالموقع: www.qayed.tn",
    ];

    public function run(): void
    {
        $this->createPage('mentions-legales', $this->mentionsMeta(), $this->mentionsArticles());
        $this->createPage('cgv', $this->cgvMeta(), $this->cgvArticles());
        $this->createPage('politique-confidentialite', $this->privacyMeta(), $this->privacyArticles());
    }

    private function createPage(string $slug, array $meta, array $articles): void
    {
        if (Page::where('slug', $slug)->exists()) {
            $this->command?->info("Page {$slug} déjà présente — non modifiée.");
            return;
        }

        $content = [];
        foreach (['fr', 'en', 'ar'] as $l) {
            $blocks = [[
                'type'  => 'SectionHeading',
                'props' => [
                    'id' => "SectionHeading-{$slug}-title",
                    'anchor' => '', 'eyebrow' => '', 'lead' => '',
                    'title' => $meta[$l]['title'], 'centered' => false, 'background' => 'default',
                ],
            ]];
            foreach ($articles as $i => $article) {
                $blocks[] = [
                    'type'  => 'Prose',
                    'props' => [
                        'id'         => "Prose-{$slug}-{$i}",
                        'title'      => $article['title'][$l] ?? '',
                        'text'       => $article['text'][$l],
                        'background' => 'default',
                    ],
                ];
            }
            $content[$l] = ['root' => ['props' => ['title' => $meta[$l]['title']]], 'content' => $blocks];
        }

        Page::create([
            'slug'    => $slug,
            'status'  => 'published',
            'content' => $content,
            'meta'    => [
                'fr' => ['title' => $meta['fr']['title'] . ' — Qayed', 'description' => $meta['fr']['description']],
                'en' => ['title' => $meta['en']['title'] . ' — Qayed', 'description' => $meta['en']['description']],
                'ar' => ['title' => $meta['ar']['title'] . ' — قيد', 'description' => $meta['ar']['description']],
            ],
        ]);
        $this->command?->info("Page {$slug} créée et publiée (FR/EN/AR).");
    }

    // ── Mentions légales ─────────────────────────────────────────────────────

    private function mentionsMeta(): array
    {
        return [
            'fr' => ['title' => 'Mentions légales', 'description' => 'Mentions légales de la plateforme Qayed, éditée par UW AGENCY SUARL.'],
            'en' => ['title' => 'Legal notice', 'description' => 'Legal notice of the Qayed platform, published by UW AGENCY SUARL.'],
            'ar' => ['title' => 'إشعار قانوني', 'description' => 'الإشعار القانوني لمنصة قيد، الصادرة عن UW AGENCY SUARL.'],
        ];
    }

    private function mentionsArticles(): array
    {
        return [
            [
                'title' => ['fr' => 'Éditeur du site', 'en' => 'Site publisher', 'ar' => 'ناشر الموقع'],
                'text'  => self::IDENTITY,
            ],
            [
                'title' => ['fr' => 'Hébergement', 'en' => 'Hosting', 'ar' => 'الاستضافة'],
                'text'  => [
                    'fr' => "Le site est hébergé par Vercel Inc., 440 N Barranca Ave #4133, Covina, CA 91723, États-Unis (vercel.com).\n\nLes services applicatifs et les données sont hébergés par Railway Corp. (railway.com).",
                    'en' => "The website is hosted by Vercel Inc., 440 N Barranca Ave #4133, Covina, CA 91723, USA (vercel.com).\n\nApplication services and data are hosted by Railway Corp. (railway.com).",
                    'ar' => "يُستضاف الموقع لدى Vercel Inc.، 440 N Barranca Ave #4133, Covina, CA 91723، الولايات المتحدة (vercel.com).\n\nتُستضاف الخدمات التطبيقية والبيانات لدى Railway Corp. (railway.com).",
                ],
            ],
            [
                'title' => ['fr' => 'Propriété intellectuelle', 'en' => 'Intellectual property', 'ar' => 'الملكية الفكرية'],
                'text'  => [
                    'fr' => "Tous les contenus du site (textes, vidéos, supports, marques, logos, design) sont protégés par le droit de la propriété intellectuelle.\n\nToute reproduction, représentation, modification, publication ou adaptation, totale ou partielle, est strictement interdite sans autorisation écrite préalable de UW AGENCY SUARL.",
                    'en' => "All content on this site (texts, videos, materials, trademarks, logos, design) is protected by intellectual property law.\n\nAny reproduction, representation, modification, publication or adaptation, in whole or in part, is strictly prohibited without the prior written authorisation of UW AGENCY SUARL.",
                    'ar' => "جميع محتويات الموقع (نصوص، فيديوهات، دعائم، علامات تجارية، شعارات، تصميم) محمية بقانون الملكية الفكرية.\n\nيُمنع منعًا باتًا أي نسخ أو عرض أو تعديل أو نشر أو اقتباس، كليًا أو جزئيًا، دون إذن كتابي مسبق من UW AGENCY SUARL.",
                ],
            ],
        ];
    }

    // ── Politique de confidentialité ─────────────────────────────────────────

    private function privacyMeta(): array
    {
        return [
            'fr' => ['title' => 'Politique de confidentialité', 'description' => 'Politique de confidentialité de la plateforme Qayed : données collectées, finalités, destinataires, durées de conservation et droits des personnes.'],
            'en' => ['title' => 'Privacy policy', 'description' => 'Privacy policy of the Qayed platform: data collected, purposes, recipients, retention periods and individual rights.'],
            'ar' => ['title' => 'سياسة الخصوصية', 'description' => 'سياسة الخصوصية لمنصة قيد: البيانات المجمعة، الأغراض، المتلقون، مدد الحفظ وحقوق الأشخاص.'],
        ];
    }

    private function privacyArticles(): array
    {
        return [
            [
                'title' => ['fr' => '1. Responsable du traitement', 'en' => '1. Data controller', 'ar' => '1. المسؤول عن المعالجة'],
                'text'  => [
                    'fr' => "Le responsable du traitement des données collectées via la plateforme Qayed (www.qayed.tn) est :\n\n" . self::IDENTITY['fr'],
                    'en' => "The controller of the data collected via the Qayed platform (www.qayed.tn) is:\n\n" . self::IDENTITY['en'],
                    'ar' => "المسؤول عن معالجة البيانات المجمعة عبر منصة قيد (www.qayed.tn) هو:\n\n" . self::IDENTITY['ar'],
                ],
            ],
            [
                'title' => ['fr' => '2. Données collectées', 'en' => '2. Data collected', 'ar' => '2. البيانات المجمعة'],
                'text'  => [
                    'fr' => "Dans le cadre de l'utilisation de la plateforme, les catégories de données suivantes peuvent être collectées :\n\n- Données de compte client : nom, prénom, adresse e-mail, téléphone, raison sociale et informations de l'établissement.\n- Données des voyageurs enregistrées par les hébergeurs lors du check-in : identité, nationalité, données du document de voyage (passeport ou CIN), dates de séjour. Ces données sont saisies par l'hébergeur dans le cadre de son obligation légale de fiche de police.\n- Données de facturation et de paiement : les paiements en ligne sont traités par nos partenaires de paiement ; Qayed ne stocke aucun numéro de carte bancaire.\n- Données techniques : journaux de connexion et d'activité, préférence de langue.",
                    'en' => "When using the platform, the following categories of data may be collected:\n\n- Client account data: first and last name, e-mail address, phone number, company name and property information.\n- Guest data recorded by accommodations at check-in: identity, nationality, travel document data (passport or ID card), stay dates. This data is entered by the accommodation as part of its legal police-form obligation.\n- Billing and payment data: online payments are processed by our payment partners; Qayed does not store any card numbers.\n- Technical data: connection and activity logs, language preference.",
                    'ar' => "في إطار استعمال المنصة، يمكن جمع فئات البيانات التالية:\n\n- بيانات حساب العميل: الاسم واللقب، البريد الإلكتروني، الهاتف، الاسم الاجتماعي ومعلومات المؤسسة.\n- بيانات النزلاء المسجلة من قبل مؤسسات الإقامة عند تسجيل الوصول: الهوية، الجنسية، بيانات وثيقة السفر (جواز أو بطاقة تعريف)، تواريخ الإقامة. تُدخل هذه البيانات من قبل المؤسسة في إطار واجبها القانوني المتعلق ببطاقة الشرطة.\n- بيانات الفوترة والدفع: تُعالج المدفوعات الإلكترونية من قبل شركائنا في الدفع؛ ولا تخزن قيد أي أرقام بطاقات بنكية.\n- بيانات تقنية: سجلات الاتصال والنشاط، تفضيل اللغة.",
                ],
            ],
            [
                'title' => ['fr' => '3. Finalités du traitement', 'en' => '3. Purposes of processing', 'ar' => '3. أغراض المعالجة'],
                'text'  => [
                    'fr' => "Les données sont traitées pour les finalités suivantes :\n\n- la fourniture du service (gestion des check-ins et des fiches de police digitales),\n- le respect des obligations réglementaires des hébergeurs, incluant la mise à disposition des fiches de police aux autorités tunisiennes habilitées,\n- la gestion des abonnements, de la facturation et des paiements,\n- le support client,\n- la sécurité de la plateforme (journalisation et traçabilité des actions),\n- l'amélioration du service.",
                    'en' => "Data is processed for the following purposes:\n\n- providing the service (management of check-ins and digital police forms),\n- meeting accommodations' regulatory obligations, including making police forms available to the authorised Tunisian authorities,\n- managing subscriptions, invoicing and payments,\n- customer support,\n- platform security (logging and traceability of actions),\n- service improvement.",
                    'ar' => "تُعالج البيانات للأغراض التالية:\n\n- تقديم الخدمة (إدارة تسجيلات الوصول وبطاقات الشرطة الرقمية)،\n- احترام الالتزامات القانونية لمؤسسات الإقامة، بما في ذلك إتاحة بطاقات الشرطة للسلطات التونسية المخوّلة،\n- إدارة الاشتراكات والفوترة والمدفوعات،\n- دعم الحرفاء،\n- أمان المنصة (تسجيل الأنشطة وتتبعها)،\n- تحسين الخدمة.",
                ],
            ],
            [
                'title' => ['fr' => '4. Cadre légal', 'en' => '4. Legal framework', 'ar' => '4. الإطار القانوني'],
                'text'  => [
                    'fr' => "Les données personnelles sont traitées conformément à la réglementation tunisienne applicable en matière de protection des données personnelles (loi organique n° 2004-63 du 27 juillet 2004).\n\nLe traitement des données des voyageurs repose sur l'obligation légale de fiche de police applicable aux hébergements touristiques en Tunisie.",
                    'en' => "Personal data is processed in accordance with the applicable Tunisian regulations on personal data protection (Organic Law No. 2004-63 of 27 July 2004).\n\nThe processing of guest data is based on the legal police-form obligation applicable to tourist accommodations in Tunisia.",
                    'ar' => "تُعالج المعطيات الشخصية وفقًا للتشريع التونسي الساري في مجال حماية المعطيات الشخصية (القانون الأساسي عدد 63 لسنة 2004 المؤرخ في 27 جويلية 2004).\n\nوتستند معالجة بيانات النزلاء إلى الواجب القانوني المتعلق ببطاقة الشرطة المنطبق على مؤسسات الإقامة السياحية في تونس.",
                ],
            ],
            [
                'title' => ['fr' => '5. Destinataires des données', 'en' => '5. Data recipients', 'ar' => '5. متلقو البيانات'],
                'text'  => [
                    'fr' => "Les données sont accessibles :\n\n- aux autorités tunisiennes habilitées, dans le cadre réglementaire de la fiche de police,\n- au personnel autorisé de l'hébergeur concerné,\n- à nos prestataires techniques strictement nécessaires au fonctionnement du service (hébergement : Vercel Inc. et Railway Corp. ; partenaires de paiement),\n- au personnel habilité de UW AGENCY SUARL.\n\nLes données ne sont ni vendues ni louées à des tiers.",
                    'en' => "Data is accessible to:\n\n- the authorised Tunisian authorities, within the regulatory framework of the police form,\n- the authorised staff of the accommodation concerned,\n- our technical providers strictly necessary for the operation of the service (hosting: Vercel Inc. and Railway Corp.; payment partners),\n- the authorised staff of UW AGENCY SUARL.\n\nData is neither sold nor rented to third parties.",
                    'ar' => "يمكن النفاذ إلى البيانات من قبل:\n\n- السلطات التونسية المخوّلة، في الإطار القانوني لبطاقة الشرطة،\n- الموظفين المرخّص لهم لدى مؤسسة الإقامة المعنية،\n- مزوّدينا التقنيين الضروريين حصرًا لتشغيل الخدمة (الاستضافة: Vercel Inc. وRailway Corp.؛ شركاء الدفع)،\n- الموظفين المخوّلين لدى UW AGENCY SUARL.\n\nلا تُباع البيانات ولا تُؤجَّر لأطراف ثالثة.",
                ],
            ],
            [
                'title' => ['fr' => '6. Durée de conservation', 'en' => '6. Retention period', 'ar' => '6. مدة الحفظ'],
                'text'  => [
                    'fr' => "Les données de compte sont conservées pendant la durée de la relation contractuelle, puis pendant les durées de prescription légales applicables.\n\nLes données des voyageurs sont conservées pendant les durées imposées par la réglementation applicable aux fiches de police, puis supprimées ou anonymisées.\n\nLes données de facturation sont conservées conformément aux obligations comptables et fiscales tunisiennes.",
                    'en' => "Account data is kept for the duration of the contractual relationship, then for the applicable legal limitation periods.\n\nGuest data is kept for the periods required by the regulations applicable to police forms, then deleted or anonymised.\n\nBilling data is kept in accordance with Tunisian accounting and tax obligations.",
                    'ar' => "تُحفظ بيانات الحساب طيلة مدة العلاقة التعاقدية، ثم طيلة آجال التقادم القانونية المنطبقة.\n\nوتُحفظ بيانات النزلاء طيلة المدد التي يفرضها التشريع المنطبق على بطاقات الشرطة، ثم تُحذف أو تُجهَّل.\n\nوتُحفظ بيانات الفوترة وفقًا للالتزامات المحاسبية والجبائية التونسية.",
                ],
            ],
            [
                'title' => ['fr' => '7. Sécurité', 'en' => '7. Security', 'ar' => '7. الأمان'],
                'text'  => [
                    'fr' => "UW AGENCY SUARL met en œuvre des mesures techniques et organisationnelles appropriées pour protéger les données, notamment :\n\n- chiffrement des échanges (HTTPS),\n- contrôle d'accès par rôles et authentification,\n- authentification à deux facteurs pour les accès des autorités,\n- journalisation et traçabilité des actions sensibles.",
                    'en' => "UW AGENCY SUARL implements appropriate technical and organisational measures to protect data, in particular:\n\n- encryption of exchanges (HTTPS),\n- role-based access control and authentication,\n- two-factor authentication for authority access,\n- logging and traceability of sensitive actions.",
                    'ar' => "تعتمد UW AGENCY SUARL تدابير تقنية وتنظيمية مناسبة لحماية البيانات، خاصة:\n\n- تشفير الاتصالات (HTTPS)،\n- التحكم في النفاذ حسب الأدوار والمصادقة،\n- المصادقة الثنائية لنفاذ السلطات،\n- تسجيل الأنشطة الحساسة وتتبعها.",
                ],
            ],
            [
                'title' => ['fr' => '8. Cookies et stockage local', 'en' => '8. Cookies and local storage', 'ar' => '8. ملفات تعريف الارتباط والتخزين المحلي'],
                'text'  => [
                    'fr' => "La plateforme utilise uniquement un stockage local strictement nécessaire à son fonctionnement : maintien de la session de l'utilisateur connecté et mémorisation de la préférence de langue.\n\nAucun cookie publicitaire ou de suivi tiers n'est utilisé.",
                    'en' => "The platform only uses local storage strictly necessary for its operation: keeping the logged-in user's session and remembering the language preference.\n\nNo advertising or third-party tracking cookies are used.",
                    'ar' => "تستعمل المنصة فقط تخزينًا محليًا ضروريًا حصرًا لتشغيلها: الحفاظ على جلسة المستخدم المتصل وتذكر تفضيل اللغة.\n\nولا تُستعمل أي ملفات تعريف ارتباط إشهارية أو للتتبع من أطراف ثالثة.",
                ],
            ],
            [
                'title' => ['fr' => '9. Vos droits', 'en' => '9. Your rights', 'ar' => '9. حقوقكم'],
                'text'  => [
                    'fr' => "Conformément à la loi organique n° 2004-63, toute personne dispose d'un droit d'accès, de rectification et de suppression de ses données personnelles.\n\nCes droits s'exercent par e-mail à l'adresse : hichemmathlouthi@gmail.com.\n\nToute personne peut également adresser une réclamation à l'Instance Nationale de Protection des Données Personnelles (INPDP).",
                    'en' => "In accordance with Organic Law No. 2004-63, any person has the right to access, rectify and delete their personal data.\n\nThese rights may be exercised by e-mail at: hichemmathlouthi@gmail.com.\n\nAny person may also lodge a complaint with the Tunisian National Authority for the Protection of Personal Data (INPDP).",
                    'ar' => "وفقًا للقانون الأساسي عدد 63 لسنة 2004، لكل شخص حق النفاذ إلى معطياته الشخصية وتصحيحها وحذفها.\n\nتُمارَس هذه الحقوق عبر البريد الإلكتروني: hichemmathlouthi@gmail.com.\n\nكما يمكن لكل شخص تقديم شكاية إلى الهيئة الوطنية لحماية المعطيات الشخصية (INPDP).",
                ],
            ],
            [
                'title' => ['fr' => '10. Modification de la présente politique', 'en' => '10. Changes to this policy', 'ar' => '10. تعديل هذه السياسة'],
                'text'  => [
                    'fr' => "La présente politique peut être mise à jour à tout moment, notamment en cas d'évolution du service ou de la réglementation. La version en vigueur est celle publiée sur le site.\n\nPour toute question relative à la présente politique : hichemmathlouthi@gmail.com.",
                    'en' => "This policy may be updated at any time, in particular in the event of changes to the service or regulations. The version in force is the one published on the site.\n\nFor any question regarding this policy: hichemmathlouthi@gmail.com.",
                    'ar' => "يمكن تحديث هذه السياسة في أي وقت، خاصة عند تطور الخدمة أو التشريع. والنسخة السارية هي المنشورة على الموقع.\n\nلكل سؤال متعلق بهذه السياسة: hichemmathlouthi@gmail.com.",
                ],
            ],
        ];
    }

    // ── CGV ──────────────────────────────────────────────────────────────────

    private function cgvMeta(): array
    {
        return [
            'fr' => ['title' => 'Conditions générales de vente', 'description' => "Conditions générales de vente des abonnements à la plateforme Qayed."],
            'en' => ['title' => 'Terms of sale', 'description' => 'Terms of sale for subscriptions to the Qayed platform.'],
            'ar' => ['title' => 'الشروط العامة للبيع', 'description' => 'الشروط العامة لبيع اشتراكات منصة قيد.'],
        ];
    }

    private function cgvArticles(): array
    {
        return [
            [
                'title' => ['fr' => '1. Identification de la société', 'en' => '1. Company identification', 'ar' => '1. تعريف الشركة'],
                'text'  => [
                    'fr' => "La plateforme Qayed (www.qayed.tn) est exploitée par :\n\n" . self::IDENTITY['fr'] . "\n\nCi-après désignée « le Vendeur ».",
                    'en' => "The Qayed platform (www.qayed.tn) is operated by:\n\n" . self::IDENTITY['en'] . "\n\nHereinafter referred to as \"the Seller\".",
                    'ar' => "تُشغَّل منصة قيد (www.qayed.tn) من قبل:\n\n" . self::IDENTITY['ar'] . "\n\nويُشار إليها فيما يلي بـ« البائع ».",
                ],
            ],
            [
                'title' => ['fr' => '2. Objet', 'en' => '2. Purpose', 'ar' => '2. الموضوع'],
                'text'  => [
                    'fr' => "Les présentes Conditions Générales de Vente (CGV) ont pour objet de définir les droits et obligations des parties dans le cadre de la souscription en ligne des abonnements au service Qayed, plateforme de gestion digitale des fiches de police pour les hébergements touristiques en Tunisie.\n\nToute souscription effectuée sur le site implique l'acceptation sans réserve des présentes CGV par le client.",
                    'en' => "These Terms of Sale define the rights and obligations of the parties in connection with the online subscription to the Qayed service, a digital police-form management platform for tourist accommodations in Tunisia.\n\nAny subscription made on the site implies the client's unreserved acceptance of these Terms.",
                    'ar' => "تهدف هذه الشروط العامة للبيع إلى تحديد حقوق والتزامات الطرفين في إطار الاشتراك عبر الإنترنت في خدمة قيد، منصة الإدارة الرقمية لبطاقات الشرطة لمؤسسات الإقامة السياحية في تونس.\n\nكل اشتراك يتم عبر الموقع يعني قبول العميل لهذه الشروط دون تحفظ.",
                ],
            ],
            [
                'title' => ['fr' => '3. Services proposés', 'en' => '3. Services offered', 'ar' => '3. الخدمات المقترحة'],
                'text'  => [
                    'fr' => "Les services proposés sont les formules d'abonnement présentées sur le site au jour de sa consultation (notamment Essentiel, Professionnel et Multi-sites), chacune faisant l'objet d'une description de ses principales caractéristiques sur la page Tarifs.\n\nUn essai gratuit de 7 jours, sans carte bancaire, est proposé lors de l'inscription.\n\nLe Vendeur se réserve le droit de faire évoluer à tout moment le contenu et l'assortiment de ses formules ; les évolutions ne s'appliquent pas rétroactivement aux périodes déjà réglées.",
                    'en' => "The services offered are the subscription plans presented on the site at the time of consultation (in particular Essential, Professional and Multi-property), each with a description of its main features on the Pricing page.\n\nA free 7-day trial, with no credit card required, is offered upon registration.\n\nThe Seller reserves the right to change the content and range of its plans at any time; changes do not apply retroactively to periods already paid for.",
                    'ar' => "الخدمات المقترحة هي صيغ الاشتراك المعروضة على الموقع يوم الاطلاع عليه (خاصة الأساسي والمحترف ومتعدد المواقع)، ولكل منها وصف لخصائصها الرئيسية في صفحة الأسعار.\n\nتُقترح تجربة مجانية لمدة 7 أيام، دون بطاقة بنكية، عند التسجيل.\n\nيحتفظ البائع بحق تطوير محتوى وتشكيلة صيغه في أي وقت؛ ولا تسري التغييرات بأثر رجعي على الفترات المدفوعة.",
                ],
            ],
            [
                'title' => ['fr' => '4. Disponibilité du service', 'en' => '4. Service availability', 'ar' => '4. توفر الخدمة'],
                'text'  => [
                    'fr' => "Le service est accessible en ligne 24 h/24 et 7 j/7, sauf cas de force majeure ou opérations de maintenance.\n\nLe Vendeur met en œuvre les moyens raisonnables pour assurer la disponibilité du service, sans garantie de disponibilité absolue. En cas d'interruption prolongée qui lui serait imputable, le client sera informé dans les meilleurs délais.",
                    'en' => "The service is accessible online 24/7, except in cases of force majeure or maintenance operations.\n\nThe Seller uses reasonable means to ensure the availability of the service, without guaranteeing absolute availability. In the event of a prolonged interruption attributable to the Seller, the client will be informed as soon as possible.",
                    'ar' => "الخدمة متاحة عبر الإنترنت على مدار الساعة طيلة أيام الأسبوع، باستثناء حالات القوة القاهرة أو عمليات الصيانة.\n\nيبذل البائع الوسائل المعقولة لضمان توفر الخدمة دون ضمان توفر مطلق. وفي حال انقطاع مطوّل يُعزى إليه، يُعلَم العميل في أقرب الآجال.",
                ],
            ],
            [
                'title' => ['fr' => '5. Prix', 'en' => '5. Prices', 'ar' => '5. الأسعار'],
                'text'  => [
                    'fr' => "Les prix des abonnements sont indiqués en Dinars Tunisiens (TND) sur la page Tarifs, par établissement ou par société selon la formule, en cycle mensuel ou annuel. Le détail des taxes applicables figure sur la facture.\n\nLe Vendeur se réserve le droit de modifier ses prix à tout moment. Toutefois, l'abonnement est facturé selon le tarif en vigueur au moment de la souscription ou du renouvellement.",
                    'en' => "Subscription prices are shown in Tunisian Dinars (TND) on the Pricing page, per property or per company depending on the plan, on a monthly or yearly cycle. Details of applicable taxes appear on the invoice.\n\nThe Seller reserves the right to change its prices at any time. However, the subscription is invoiced at the rate in force at the time of subscription or renewal.",
                    'ar' => "تُعرض أسعار الاشتراكات بالدينار التونسي على صفحة الأسعار، لكل مؤسسة أو لكل شركة حسب الصيغة، بدورة شهرية أو سنوية. وتُبيَّن تفاصيل الأداءات المستوجبة في الفاتورة.\n\nيحتفظ البائع بحق تغيير أسعاره في أي وقت، غير أن الاشتراك يُفوتر حسب التعريفة السارية عند الاشتراك أو التجديد.",
                ],
            ],
            [
                'title' => ['fr' => '6. Souscription', 'en' => '6. Subscription', 'ar' => '6. الاشتراك'],
                'text'  => [
                    'fr' => "Le client souscrit en ligne en créant un compte, en choisissant sa formule et son cycle de facturation. La validation de la souscription implique :\n\n- l'acceptation des présentes CGV,\n- la vérification des informations saisies,\n- la confirmation du paiement selon le mode choisi.\n\nLe Vendeur se réserve le droit de refuser ou de suspendre toute souscription en cas de :\n\n- litige existant avec le client,\n- suspicion de fraude,\n- défaut de paiement,\n- informations incomplètes ou erronées.",
                    'en' => "The client subscribes online by creating an account and choosing a plan and billing cycle. Validation of the subscription implies:\n\n- acceptance of these Terms,\n- verification of the information provided,\n- confirmation of payment by the chosen method.\n\nThe Seller reserves the right to refuse or suspend any subscription in the event of:\n\n- an existing dispute with the client,\n- suspected fraud,\n- payment default,\n- incomplete or incorrect information.",
                    'ar' => "يشترك العميل عبر الإنترنت بإنشاء حساب واختيار صيغته ودورة الفوترة. ويعني تأكيد الاشتراك:\n\n- قبول هذه الشروط،\n- التحقق من المعلومات المدخلة،\n- تأكيد الدفع حسب الطريقة المختارة.\n\nيحتفظ البائع بحق رفض أو تعليق أي اشتراك في حال:\n\n- نزاع قائم مع العميل،\n- شبهة احتيال،\n- عدم الدفع،\n- معلومات منقوصة أو خاطئة.",
                ],
            ],
            [
                'title' => ['fr' => '7. Modalités de paiement', 'en' => '7. Payment methods', 'ar' => '7. طرق الدفع'],
                'text'  => [
                    'fr' => "Le paiement peut être effectué via les moyens proposés sur le site, notamment :\n\n- carte bancaire (paiement en ligne sécurisé via nos partenaires de paiement),\n- virement bancaire.\n\nLes transactions en ligne sont sécurisées via les solutions de paiement partenaires du site. Le Vendeur ne saurait être tenu responsable d'un dysfonctionnement lié aux plateformes de paiement externes.",
                    'en' => "Payment may be made using the methods offered on the site, in particular:\n\n- credit/debit card (secure online payment via our payment partners),\n- bank transfer.\n\nOnline transactions are secured via the site's partner payment solutions. The Seller cannot be held liable for malfunctions related to external payment platforms.",
                    'ar' => "يمكن الدفع بالوسائل المقترحة على الموقع، خاصة:\n\n- البطاقة البنكية (دفع إلكتروني آمن عبر شركائنا في الدفع)،\n- التحويل البنكي.\n\nالمعاملات الإلكترونية مؤمَّنة عبر حلول الدفع الشريكة للموقع. ولا يتحمل البائع مسؤولية أي خلل متصل بمنصات الدفع الخارجية.",
                ],
            ],
            [
                'title' => ['fr' => "8. Accès au service", 'en' => '8. Access to the service', 'ar' => '8. النفاذ إلى الخدمة'],
                'text'  => [
                    'fr' => "L'accès au service est activé dès la validation du paiement (ou immédiatement pour l'essai gratuit).\n\nLe client accède à son espace depuis tout navigateur récent, sans installation. Il est responsable de la confidentialité de ses identifiants et de l'usage fait de son compte par les membres de son équipe.",
                    'en' => "Access to the service is activated as soon as payment is validated (or immediately for the free trial).\n\nThe client accesses their workspace from any modern browser, with no installation required. The client is responsible for the confidentiality of their credentials and for the use of their account by their team members.",
                    'ar' => "يُفعَّل النفاذ إلى الخدمة فور تأكيد الدفع (أو فورًا بالنسبة للتجربة المجانية).\n\nيدخل العميل إلى فضائه من أي متصفح حديث دون تثبيت. وهو مسؤول عن سرية معرّفاته وعن استعمال حسابه من قبل أعضاء فريقه.",
                ],
            ],
            [
                'title' => ['fr' => "9. Durée et résiliation", 'en' => '9. Term and cancellation', 'ar' => '9. المدة والفسخ'],
                'text'  => [
                    'fr' => "L'abonnement est sans engagement. Le client peut demander la résiliation à tout moment ; le service reste alors accessible jusqu'à la fin de la période déjà réglée, qui ne donne pas lieu à remboursement au prorata.\n\nLe Vendeur se réserve le droit de suspendre ou résilier un compte en cas de manquement grave aux présentes CGV, d'usage frauduleux ou de défaut de paiement, après notification au client.",
                    'en' => "The subscription has no minimum commitment. The client may request cancellation at any time; the service then remains accessible until the end of the period already paid for, which is not refunded pro rata.\n\nThe Seller reserves the right to suspend or terminate an account in the event of a serious breach of these Terms, fraudulent use or payment default, after notifying the client.",
                    'ar' => "الاشتراك دون التزام. يمكن للعميل طلب الفسخ في أي وقت؛ وتبقى الخدمة متاحة حينها إلى نهاية الفترة المدفوعة، والتي لا تُسترجع بالتناسب.\n\nيحتفظ البائع بحق تعليق أو إنهاء حساب في حال إخلال جسيم بهذه الشروط أو استعمال احتيالي أو عدم دفع، بعد إشعار العميل.",
                ],
            ],
            [
                'title' => ['fr' => '10. Remboursement', 'en' => '10. Refunds', 'ar' => '10. الاسترجاع'],
                'text'  => [
                    'fr' => "L'essai gratuit de 7 jours permet au client de tester l'intégralité du service avant tout paiement.\n\nLes sommes versées au titre d'une période d'abonnement entamée ne sont pas remboursables, sauf dysfonctionnement majeur et prolongé du service imputable au Vendeur. Dans ce cas, le remboursement de la période concernée est effectué via le moyen de paiement initial dans un délai maximum de 14 jours après validation de la demande.\n\nToute demande s'effectue par e-mail à l'adresse : hichemmathlouthi@gmail.com.",
                    'en' => "The 7-day free trial allows the client to test the entire service before any payment.\n\nAmounts paid for a subscription period that has begun are non-refundable, except in the event of a major and prolonged malfunction of the service attributable to the Seller. In that case, the refund for the period concerned is made via the original payment method within a maximum of 14 days after the request is validated.\n\nAll requests must be made by e-mail to: hichemmathlouthi@gmail.com.",
                    'ar' => "تتيح التجربة المجانية لمدة 7 أيام للعميل اختبار كامل الخدمة قبل أي دفع.\n\nالمبالغ المدفوعة عن فترة اشتراك بدأت غير قابلة للاسترجاع، إلا في حال خلل جسيم ومطوّل في الخدمة يُعزى إلى البائع. وفي هذه الحالة يُسترجع مبلغ الفترة المعنية عبر وسيلة الدفع الأصلية في أجل أقصاه 14 يومًا بعد قبول الطلب.\n\nيُقدَّم كل طلب عبر البريد الإلكتروني: hichemmathlouthi@gmail.com.",
                ],
            ],
            [
                'title' => ['fr' => '11. Garantie et responsabilité', 'en' => '11. Warranty and liability', 'ar' => '11. الضمان والمسؤولية'],
                'text'  => [
                    'fr' => "Le Vendeur s'engage à fournir un service conforme à la description affichée sur le site.\n\nLa responsabilité du Vendeur ne pourra être engagée en cas :\n\n- de mauvaise utilisation du service,\n- de défaillance des équipements ou de la connexion du client,\n- de dommage causé par le client ou un tiers,\n- de force majeure.",
                    'en' => "The Seller undertakes to provide a service that complies with the description displayed on the site.\n\nThe Seller cannot be held liable in the event of:\n\n- misuse of the service,\n- failure of the client's equipment or connection,\n- damage caused by the client or a third party,\n- force majeure.",
                    'ar' => "يلتزم البائع بتقديم خدمة مطابقة للوصف المعروض على الموقع.\n\nلا يمكن تحميل البائع المسؤولية في حال:\n\n- سوء استعمال الخدمة،\n- عطل في معدات العميل أو اتصاله،\n- ضرر تسبب فيه العميل أو طرف ثالث،\n- قوة قاهرة.",
                ],
            ],
            [
                'title' => ['fr' => '12. Protection des données personnelles', 'en' => '12. Personal data protection', 'ar' => '12. حماية المعطيات الشخصية'],
                'text'  => [
                    'fr' => "Les informations collectées sur le site sont nécessaires au traitement des souscriptions, à la fourniture du service et à la gestion de la relation client.\n\nLes données personnelles sont traitées conformément à la réglementation tunisienne applicable en matière de protection des données personnelles (loi organique n° 2004-63).\n\nLe client dispose d'un droit d'accès, de rectification et de suppression de ses données personnelles en contactant : hichemmathlouthi@gmail.com.",
                    'en' => "The information collected on the site is necessary for processing subscriptions, providing the service and managing the client relationship.\n\nPersonal data is processed in accordance with the applicable Tunisian regulations on personal data protection (Organic Law No. 2004-63).\n\nThe client has the right to access, rectify and delete their personal data by contacting: hichemmathlouthi@gmail.com.",
                    'ar' => "المعلومات المجمعة على الموقع ضرورية لمعالجة الاشتراكات وتقديم الخدمة وإدارة العلاقة مع العميل.\n\nتُعالج المعطيات الشخصية وفقًا للتشريع التونسي الساري في مجال حماية المعطيات الشخصية (القانون الأساسي عدد 63 لسنة 2004).\n\nللعميل حق النفاذ إلى معطياته الشخصية وتصحيحها وحذفها بالاتصال بـ: hichemmathlouthi@gmail.com.",
                ],
            ],
            [
                'title' => ['fr' => '13. Propriété intellectuelle', 'en' => '13. Intellectual property', 'ar' => '13. الملكية الفكرية'],
                'text'  => [
                    'fr' => "Tous les éléments présents sur le site et la plateforme (textes, images, logos, visuels, design, contenus, code) sont protégés par les droits de propriété intellectuelle.\n\nToute reproduction, exploitation ou utilisation sans autorisation préalable est interdite.",
                    'en' => "All elements present on the site and the platform (texts, images, logos, visuals, design, content, code) are protected by intellectual property rights.\n\nAny reproduction, exploitation or use without prior authorisation is prohibited.",
                    'ar' => "جميع العناصر الموجودة على الموقع والمنصة (نصوص، صور، شعارات، مرئيات، تصميم، محتويات، برمجيات) محمية بحقوق الملكية الفكرية.\n\nيُمنع أي نسخ أو استغلال أو استعمال دون إذن مسبق.",
                ],
            ],
            [
                'title' => ['fr' => '14. Résolution des litiges', 'en' => '14. Dispute resolution', 'ar' => '14. فض النزاعات'],
                'text'  => [
                    'fr' => "En cas de litige, le client est invité à contacter le service client afin de rechercher une solution amiable.\n\nRéclamation par e-mail : hichemmathlouthi@gmail.com\n\nÀ défaut d'accord amiable, les juridictions tunisiennes seront seules compétentes.",
                    'en' => "In the event of a dispute, the client is invited to contact customer service in order to seek an amicable solution.\n\nComplaints by e-mail: hichemmathlouthi@gmail.com\n\nFailing an amicable agreement, the Tunisian courts shall have exclusive jurisdiction.",
                    'ar' => "في حال نزاع، يُدعى العميل إلى الاتصال بخدمة الحرفاء سعيًا لحل ودي.\n\nالشكاوى عبر البريد الإلكتروني: hichemmathlouthi@gmail.com\n\nوفي غياب اتفاق ودي، تختص المحاكم التونسية دون سواها.",
                ],
            ],
            [
                'title' => ['fr' => '15. Droit applicable', 'en' => '15. Governing law', 'ar' => '15. القانون المنطبق'],
                'text'  => [
                    'fr' => "Les présentes Conditions Générales de Vente sont régies par le droit tunisien.\n\nTout différend relatif à leur interprétation ou leur exécution relève de la compétence exclusive des tribunaux tunisiens.",
                    'en' => "These Terms of Sale are governed by Tunisian law.\n\nAny dispute relating to their interpretation or performance falls under the exclusive jurisdiction of the Tunisian courts.",
                    'ar' => "تخضع هذه الشروط العامة للبيع للقانون التونسي.\n\nويعود كل خلاف متعلق بتأويلها أو تنفيذها إلى الاختصاص الحصري للمحاكم التونسية.",
                ],
            ],
            [
                'title' => ['fr' => '16. Accessibilité des CGV', 'en' => '16. Availability of the Terms', 'ar' => '16. إتاحة الشروط'],
                'text'  => [
                    'fr' => "Les présentes Conditions Générales de Vente sont accessibles à tout moment sur le site internet.\n\nLe client reconnaît avoir pris connaissance des présentes CGV avant la validation et le paiement de sa souscription.",
                    'en' => "These Terms of Sale are accessible at any time on the website.\n\nThe client acknowledges having read these Terms before validating and paying for their subscription.",
                    'ar' => "هذه الشروط العامة للبيع متاحة في أي وقت على الموقع.\n\nويقرّ العميل بأنه اطلع عليها قبل تأكيد اشتراكه ودفعه.",
                ],
            ],
        ];
    }
}
