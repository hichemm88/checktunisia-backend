<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = ['key', 'subject', 'body_html', 'updated_by'];

    /** Sensible built-in content so every template works before an admin ever customizes it. */
    public const DEFAULTS = [
        'welcome' => [
            'subject' => "Bienvenue sur Qayed — activez votre compte",
            'body_html' => <<<'HTML'
<p>Bonjour <strong>{{first_name}} {{last_name}}</strong>,</p>
<p>Un compte a été créé pour vous sur <strong>Qayed</strong> en tant que <strong>{{role_label}}</strong> de l'établissement <strong>{{hotel_name}}</strong>.</p>
<p>Pour activer votre compte, définissez votre mot de passe en cliquant sur le bouton ci-dessous :</p>
{{cta_button}}
<div class="warning">⚠️&nbsp; Ce lien est valable 48 heures et à usage unique.</div>
HTML,
        ],
        'password_reset' => [
            'subject' => "Réinitialisation de votre mot de passe Qayed",
            'body_html' => <<<'HTML'
<p>Bonjour <strong>{{first_name}}</strong>,</p>
<p>Vous avez demandé la réinitialisation de votre mot de passe sur <strong>Qayed</strong>.</p>
<p>Cliquez sur le bouton ci-dessous pour en choisir un nouveau :</p>
{{cta_button}}
<div class="warning">⚠️&nbsp; Ce lien est valable 48 heures et à usage unique. Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail — votre mot de passe reste inchangé.</div>
HTML,
        ],
        'account_suspended' => [
            'subject' => "Votre compte Qayed a été suspendu",
            'body_html' => <<<'HTML'
<p>Bonjour <strong>{{name}}</strong>,</p>
<p>Votre compte hébergeur sur <strong>Qayed</strong> a été suspendu.</p>
<div class="danger">🛑&nbsp; <strong>Motif :</strong> {{reason}}</div>
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

    public static function getOrDefault(string $key): array
    {
        $custom = static::where('key', $key)->first();
        if ($custom) {
            return ['subject' => $custom->subject, 'body_html' => $custom->body_html, 'is_custom' => true];
        }

        $default = self::DEFAULTS[$key] ?? null;
        if (!$default) {
            throw new \InvalidArgumentException("Unknown email template key: {$key}");
        }

        return array_merge($default, ['is_custom' => false]);
    }
}
