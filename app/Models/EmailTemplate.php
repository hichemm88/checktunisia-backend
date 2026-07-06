<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = ['key', 'subject', 'body_html', 'updated_by'];

    /** Sensible built-in content so every template works before an admin ever customizes it. */
    public const DEFAULTS = [
        'welcome' => [
            'subject' => "Bienvenue sur Qayed — vos identifiants de connexion",
            'body_html' => <<<'HTML'
<p>Bonjour <strong>{{first_name}} {{last_name}}</strong>,</p>
<p>Un compte a été créé pour vous sur <strong>Qayed</strong> en tant que <strong>{{role_label}}</strong> de l'établissement <strong>{{hotel_name}}</strong>.</p>
<p>Voici vos identifiants de connexion :</p>
{{credentials_box}}
{{cta_button}}
<div class="warning">⚠️&nbsp; Pour des raisons de sécurité, veuillez modifier votre mot de passe dès votre première connexion.</div>
HTML,
        ],
        'account_suspended' => [
            'subject' => "Votre compte Qayed a été suspendu",
            'body_html' => <<<'HTML'
<p>Bonjour <strong>{{name}}</strong>,</p>
<p>Votre compte hébergeur sur <strong>Qayed</strong> a été suspendu.</p>
<p><strong>Motif :</strong> {{reason}}</p>
<p>Pour toute question ou pour régulariser la situation, contactez <a href="mailto:support@qayed.tn">support@qayed.tn</a>.</p>
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
