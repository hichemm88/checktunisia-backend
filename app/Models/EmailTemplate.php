<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = ['key', 'locale', 'subject', 'body_html', 'updated_by'];

    /** Langues de communication supportees. 'fr' est la langue de repli. */
    public const LOCALES = ['fr', 'en', 'ar'];
    public const DEFAULT_LOCALE = 'fr';

    /** Sensible built-in content so every template works before an admin ever customizes it. */
    public const DEFAULTS = [
        'welcome' => [
            'subject' => "Bienvenue sur Qayed — activez votre compte",
            'body_html' => <<<'HTML'
<p>Bonjour <strong>{{first_name}} {{last_name}}</strong>,</p>
<p>Un compte a été créé pour vous sur <strong>Qayed</strong> en tant que <strong>{{role_label}}</strong> de l'établissement <strong>{{hotel_name}}</strong>.</p>
<p>Pour activer votre compte, définissez votre mot de passe en cliquant sur le bouton ci-dessous :</p>
{{cta_button}}
<div class="warning">Ce lien est valable 48 heures et à usage unique.</div>
HTML,
        ],
        'password_reset' => [
            'subject' => "Réinitialisation de votre mot de passe Qayed",
            'body_html' => <<<'HTML'
<p>Bonjour <strong>{{first_name}}</strong>,</p>
<p>Vous avez demandé la réinitialisation de votre mot de passe sur <strong>Qayed</strong>.</p>
<p>Cliquez sur le bouton ci-dessous pour en choisir un nouveau :</p>
{{cta_button}}
<div class="warning">Ce lien est valable 48 heures et à usage unique. Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail — votre mot de passe reste inchangé.</div>
HTML,
        ],
        'account_suspended' => [
            'subject' => "Votre compte Qayed a été suspendu",
            'body_html' => <<<'HTML'
<p>Bonjour <strong>{{name}}</strong>,</p>
<p>Votre compte hébergeur sur <strong>Qayed</strong> a été suspendu.</p>
<div class="danger"><strong>Motif :</strong> {{reason}}</div>
<p style="margin-top:20px;">Pour toute question ou pour régulariser la situation, contactez <a href="mailto:support@qayed.tn" style="color:#5346A8;">support@qayed.tn</a>.</p>
HTML,
        ],
        'payment_received' => [
            'subject' => "Paiement reçu — Qayed",
            'body_html' => <<<'HTML'
<p>Bonjour <strong>{{name}}</strong>,</p>
<p>Nous confirmons la réception de votre paiement.</p>
{{credentials_box}}
<p>Votre abonnement <strong>{{plan_name}}</strong> est actif jusqu'au <strong>{{expires_at}}</strong>.</p>
HTML,
        ],
        'subscription_reminder' => [
            'subject' => "Votre abonnement Qayed expire bientôt",
            'body_html' => <<<'HTML'
<p>Bonjour <strong>{{name}}</strong>,</p>
<p>Votre abonnement <strong>{{plan_name}}</strong> expire le <strong>{{expires_at}}</strong> (dans {{days_remaining}} jour(s)).</p>
<p>Pensez à le renouveler pour éviter toute interruption de service.</p>
{{cta_button}}
HTML,
        ],
        'trial_ending' => [
            'subject' => "Votre essai Qayed touche à sa fin",
            'body_html' => <<<'HTML'
<p>Bonjour <strong>{{name}}</strong>,</p>
<p>{{trial_message}}</p>
<p>Passez à un abonnement payant dès maintenant pour continuer à enregistrer vos voyageurs sans interruption.</p>
{{cta_button}}
HTML,
        ],
        'invoice_overdue' => [
            'subject' => "Facture {{invoice_number}} en attente de règlement — Qayed",
            'body_html' => <<<'HTML'
<p>Bonjour <strong>{{name}}</strong>,</p>
<p>Sauf erreur de notre part, la facture <strong>{{invoice_number}}</strong> de votre abonnement <strong>{{plan_name}}</strong> est impayée depuis <strong>{{days_late}} jour(s)</strong>.</p>
{{credentials_box}}
<p>Pour éviter toute interruption de service, merci de la régler dès que possible. Si le paiement a déjà été effectué, vous pouvez ignorer ce message.</p>
{{cta_button}}
HTML,
        ],
        'invoice_available' => [
            'subject' => "Votre facture Qayed {{invoice_number}} est disponible",
            'body_html' => <<<'HTML'
<p>Bonjour <strong>{{name}}</strong>,</p>
<p>Une nouvelle facture est disponible pour votre abonnement <strong>{{plan_name}}</strong>.</p>
{{credentials_box}}
{{cta_button}}
HTML,
        ],
    ];

    /**
     * Traductions par defaut (en / ar) des memes cles. Le francais reste dans
     * DEFAULTS (langue de repli) : une cle absente ici retombe sur le francais.
     * Les placeholders {{...}} et les classes CSS (warning/danger) sont identiques.
     */
    public const TRANSLATIONS = [
        'en' => [
            'welcome' => [
                'subject' => 'Welcome to Qayed — activate your account',
                'body_html' => <<<'HTML'
<p>Hello <strong>{{first_name}} {{last_name}}</strong>,</p>
<p>An account has been created for you on <strong>Qayed</strong> as <strong>{{role_label}}</strong> of <strong>{{hotel_name}}</strong>.</p>
<p>To activate your account, set your password by clicking the button below:</p>
{{cta_button}}
<div class="warning">This link is valid for 48 hours and can be used once.</div>
HTML,
            ],
            'password_reset' => [
                'subject' => 'Reset your Qayed password',
                'body_html' => <<<'HTML'
<p>Hello <strong>{{first_name}}</strong>,</p>
<p>You requested a password reset on <strong>Qayed</strong>.</p>
<p>Click the button below to choose a new one:</p>
{{cta_button}}
<div class="warning">This link is valid for 48 hours and can be used once. If you did not request this, ignore this email — your password stays unchanged.</div>
HTML,
            ],
            'account_suspended' => [
                'subject' => 'Your Qayed account has been suspended',
                'body_html' => <<<'HTML'
<p>Hello <strong>{{name}}</strong>,</p>
<p>Your host account on <strong>Qayed</strong> has been suspended.</p>
<div class="danger"><strong>Reason:</strong> {{reason}}</div>
<p style="margin-top:20px;">For any question or to resolve the situation, contact <a href="mailto:support@qayed.tn" style="color:#5346A8;">support@qayed.tn</a>.</p>
HTML,
            ],
            'payment_received' => [
                'subject' => 'Payment received — Qayed',
                'body_html' => <<<'HTML'
<p>Hello <strong>{{name}}</strong>,</p>
<p>We confirm that your payment has been received.</p>
{{credentials_box}}
<p>Your <strong>{{plan_name}}</strong> subscription is active until <strong>{{expires_at}}</strong>.</p>
HTML,
            ],
            'subscription_reminder' => [
                'subject' => 'Your Qayed subscription expires soon',
                'body_html' => <<<'HTML'
<p>Hello <strong>{{name}}</strong>,</p>
<p>Your <strong>{{plan_name}}</strong> subscription expires on <strong>{{expires_at}}</strong> (in {{days_remaining}} day(s)).</p>
<p>Remember to renew it to avoid any service interruption.</p>
{{cta_button}}
HTML,
            ],
            'trial_ending' => [
                'subject' => 'Your Qayed trial is ending',
                'body_html' => <<<'HTML'
<p>Hello <strong>{{name}}</strong>,</p>
<p>{{trial_message}}</p>
<p>Upgrade to a paid plan now to keep registering your travelers without interruption.</p>
{{cta_button}}
HTML,
            ],
            'invoice_overdue' => [
                'subject' => 'Invoice {{invoice_number}} awaiting payment — Qayed',
                'body_html' => <<<'HTML'
<p>Hello <strong>{{name}}</strong>,</p>
<p>Unless we are mistaken, invoice <strong>{{invoice_number}}</strong> for your <strong>{{plan_name}}</strong> subscription has been unpaid for <strong>{{days_late}} day(s)</strong>.</p>
{{credentials_box}}
<p>To avoid any service interruption, please settle it as soon as possible. If payment has already been made, you can ignore this message.</p>
{{cta_button}}
HTML,
            ],
            'invoice_available' => [
                'subject' => 'Your Qayed invoice {{invoice_number}} is available',
                'body_html' => <<<'HTML'
<p>Hello <strong>{{name}}</strong>,</p>
<p>A new invoice is available for your <strong>{{plan_name}}</strong> subscription.</p>
{{credentials_box}}
{{cta_button}}
HTML,
            ],
        ],
        'ar' => [
            'welcome' => [
                'subject' => 'مرحبًا بك في قيد — فعّل حسابك',
                'body_html' => <<<'HTML'
<p>مرحبًا <strong>{{first_name}} {{last_name}}</strong>،</p>
<p>تم إنشاء حساب لك على <strong>قيد</strong> بصفتك <strong>{{role_label}}</strong> في <strong>{{hotel_name}}</strong>.</p>
<p>لتفعيل حسابك، عيّن كلمة المرور بالنقر على الزر أدناه:</p>
{{cta_button}}
<div class="warning">هذا الرابط صالح لمدة 48 ساعة ويُستخدم مرة واحدة.</div>
HTML,
            ],
            'password_reset' => [
                'subject' => 'إعادة تعيين كلمة مرور قيد',
                'body_html' => <<<'HTML'
<p>مرحبًا <strong>{{first_name}}</strong>،</p>
<p>لقد طلبت إعادة تعيين كلمة المرور على <strong>قيد</strong>.</p>
<p>انقر على الزر أدناه لاختيار كلمة مرور جديدة:</p>
{{cta_button}}
<div class="warning">هذا الرابط صالح لمدة 48 ساعة ويُستخدم مرة واحدة. إذا لم تكن أنت من قدّم هذا الطلب، فتجاهل هذه الرسالة — تبقى كلمة مرورك دون تغيير.</div>
HTML,
            ],
            'account_suspended' => [
                'subject' => 'تم تعليق حسابك في قيد',
                'body_html' => <<<'HTML'
<p>مرحبًا <strong>{{name}}</strong>،</p>
<p>تم تعليق حساب المضيف الخاص بك على <strong>قيد</strong>.</p>
<div class="danger"><strong>السبب:</strong> {{reason}}</div>
<p style="margin-top:20px;">لأي استفسار أو لتسوية الوضع، تواصل مع <a href="mailto:support@qayed.tn" style="color:#5346A8;">support@qayed.tn</a>.</p>
HTML,
            ],
            'payment_received' => [
                'subject' => 'تم استلام الدفعة — قيد',
                'body_html' => <<<'HTML'
<p>مرحبًا <strong>{{name}}</strong>،</p>
<p>نؤكد استلام دفعتك.</p>
{{credentials_box}}
<p>اشتراكك <strong>{{plan_name}}</strong> نشط حتى <strong>{{expires_at}}</strong>.</p>
HTML,
            ],
            'subscription_reminder' => [
                'subject' => 'اشتراكك في قيد على وشك الانتهاء',
                'body_html' => <<<'HTML'
<p>مرحبًا <strong>{{name}}</strong>،</p>
<p>ينتهي اشتراكك <strong>{{plan_name}}</strong> بتاريخ <strong>{{expires_at}}</strong> (خلال {{days_remaining}} يوم/أيام).</p>
<p>تذكّر تجديده لتفادي أي انقطاع في الخدمة.</p>
{{cta_button}}
HTML,
            ],
            'trial_ending' => [
                'subject' => 'تجربتك المجانية في قيد على وشك الانتهاء',
                'body_html' => <<<'HTML'
<p>مرحبًا <strong>{{name}}</strong>،</p>
<p>{{trial_message}}</p>
<p>انتقل إلى اشتراك مدفوع الآن لمواصلة تسجيل نزلائك دون انقطاع.</p>
{{cta_button}}
HTML,
            ],
            'invoice_overdue' => [
                'subject' => 'الفاتورة {{invoice_number}} في انتظار السداد — قيد',
                'body_html' => <<<'HTML'
<p>مرحبًا <strong>{{name}}</strong>،</p>
<p>ما لم يكن هناك خطأ من جانبنا، فإن الفاتورة <strong>{{invoice_number}}</strong> الخاصة باشتراكك <strong>{{plan_name}}</strong> لم تُسدَّد منذ <strong>{{days_late}} يوم/أيام</strong>.</p>
{{credentials_box}}
<p>لتفادي أي انقطاع في الخدمة، يُرجى سدادها في أقرب وقت. إذا تم الدفع مسبقًا، يمكنك تجاهل هذه الرسالة.</p>
{{cta_button}}
HTML,
            ],
            'invoice_available' => [
                'subject' => 'فاتورتك في قيد {{invoice_number}} متاحة',
                'body_html' => <<<'HTML'
<p>مرحبًا <strong>{{name}}</strong>،</p>
<p>تتوفر فاتورة جديدة لاشتراكك <strong>{{plan_name}}</strong>.</p>
{{credentials_box}}
{{cta_button}}
HTML,
            ],
        ],
    ];

    /** Ramene une langue quelconque a une langue supportee (repli 'fr'). */
    public static function normalizeLocale(?string $locale): string
    {
        return in_array($locale, self::LOCALES, true) ? $locale : self::DEFAULT_LOCALE;
    }

    /** Contenu par defaut d'une cle dans une langue, avec repli francais champ par champ. */
    public static function defaultFor(string $key, string $locale = self::DEFAULT_LOCALE): array
    {
        $fr = self::DEFAULTS[$key] ?? null;
        if (!$fr) {
            throw new \InvalidArgumentException("Unknown email template key: {$key}");
        }
        if ($locale === self::DEFAULT_LOCALE) {
            return ['subject' => $fr['subject'], 'body_html' => $fr['body_html']];
        }
        $tr = self::TRANSLATIONS[$locale][$key] ?? [];

        return [
            'subject'   => $tr['subject']   ?? $fr['subject'],
            'body_html' => $tr['body_html'] ?? $fr['body_html'],
        ];
    }

    /**
     * Modele resolu pour (cle, langue) : override admin de cette langue si
     * present, sinon defaut de cette langue, sinon defaut francais.
     */
    public static function getOrDefault(string $key, ?string $locale = null): array
    {
        $locale = self::normalizeLocale($locale);

        $custom = static::where('key', $key)->where('locale', $locale)->first();
        if ($custom) {
            return ['subject' => $custom->subject, 'body_html' => $custom->body_html, 'is_custom' => true, 'locale' => $locale];
        }

        return array_merge(self::defaultFor($key, $locale), ['is_custom' => false, 'locale' => $locale]);
    }
}
