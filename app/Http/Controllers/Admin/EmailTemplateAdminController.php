<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\Subscription;
use App\Services\Email\SystemMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailTemplateAdminController extends Controller
{
    private const KEYS = ['welcome', 'account_suspended', 'payment_received', 'subscription_reminder', 'trial_ending', 'invoice_available'];

    private const LABELS = [
        'welcome'                => 'Bienvenue',
        'account_suspended'      => 'Suspension de compte',
        'payment_received'       => 'Paiement reçu',
        'subscription_reminder'  => "Rappel d'expiration",
        'trial_ending'           => "Fin d'essai gratuit",
        'invoice_available'      => 'Facture disponible',
    ];

    public function index(): JsonResponse
    {
        $data = collect(self::KEYS)->map(function (string $key) {
            $t = EmailTemplate::getOrDefault($key);
            return [
                'key'       => $key,
                'label'     => self::LABELS[$key],
                'subject'   => $t['subject'],
                'body_html' => $t['body_html'],
                'is_custom' => $t['is_custom'],
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        if (!in_array($key, self::KEYS, true)) {
            return response()->json(['errors' => [['code' => 'NOT_FOUND', 'message' => 'Modèle inconnu.']]], 404);
        }

        $v = $request->validate([
            'subject'   => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string'],
        ]);

        $template = EmailTemplate::updateOrCreate(
            ['key' => $key],
            [...$v, 'updated_by' => $request->user()->id],
        );

        return response()->json(['data' => [
            'key' => $key, 'label' => self::LABELS[$key],
            'subject' => $template->subject, 'body_html' => $template->body_html, 'is_custom' => true,
        ]]);
    }

    public function preview(string $key): JsonResponse
    {
        if (!in_array($key, self::KEYS, true)) {
            return response()->json(['errors' => [['code' => 'NOT_FOUND', 'message' => 'Modèle inconnu.']]], 404);
        }

        return response()->json(['data' => SystemMailer::preview($key)]);
    }

    /** Manually trigger reminder emails for subscriptions expiring within 7 days (and trials within 2 days). */
    public function sendReminders(): JsonResponse
    {
        $paidSubs = Subscription::with(['plan', 'organization', 'hotel'])
            ->where('status', 'active')
            ->whereBetween('expires_at', [now(), now()->addDays(7)])
            ->get();

        $sent = 0;
        foreach ($paidSubs as $sub) {
            $to = $sub->organization?->contact_email ?? $sub->hotel?->contacts()->where('type', 'email')->where('is_primary', true)->first()?->value;
            $name = $sub->organization?->name ?? $sub->hotel?->name ?? 'Client Qayed';
            $ok = SystemMailer::send('subscription_reminder', $to, [
                'name'           => $name,
                'plan_name'      => $sub->plan?->name ?? '—',
                'expires_at'     => $sub->expires_at?->format('d/m/Y'),
                'days_remaining' => (string) now()->diffInDays($sub->expires_at, false),
            ]);
            if ($ok) $sent++;
        }

        $trialSubs = Subscription::with(['organization'])
            ->where('status', 'trial')
            ->whereBetween('expires_at', [now(), now()->addDays(2)])
            ->get();

        foreach ($trialSubs as $sub) {
            $to   = $sub->organization?->contact_email;
            $name = $sub->organization?->name ?? 'Client Qayed';
            $days = max(0, (int) now()->diffInDays($sub->expires_at, false));
            $ok = SystemMailer::send('trial_ending', $to, [
                'name'          => $name,
                'trial_message' => $days > 0
                    ? "Votre essai gratuit se termine dans {$days} jour(s), le {$sub->expires_at->format('d/m/Y')}."
                    : "Votre essai gratuit se termine aujourd'hui.",
                'cta_button' => SystemMailer::ctaButton(SystemMailer::frontendUrl('/hotel/settings'), 'Voir les abonnements'),
            ]);
            if ($ok) $sent++;
        }

        return response()->json(['data' => ['candidates' => $paidSubs->count() + $trialSubs->count(), 'sent' => $sent]]);
    }
}
