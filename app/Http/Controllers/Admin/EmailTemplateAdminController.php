<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\SystemMail;
use App\Models\EmailTemplate;
use App\Models\Subscription;
use App\Services\Email\SystemMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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

    /** Langue demandee (repli francais). */
    private function locale(Request $request): string
    {
        return EmailTemplate::normalizeLocale($request->query('locale', $request->input('locale')));
    }

    public function index(Request $request): JsonResponse
    {
        $locale = $this->locale($request);

        $data = collect(self::KEYS)->map(function (string $key) use ($locale) {
            $t = EmailTemplate::getOrDefault($key, $locale);
            return [
                'key'       => $key,
                'label'     => self::LABELS[$key],
                'subject'   => $t['subject'],
                'body_html' => $t['body_html'],
                'is_custom' => $t['is_custom'],
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => ['locale' => $locale, 'locales' => EmailTemplate::LOCALES],
        ]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        if (!in_array($key, self::KEYS, true)) {
            return response()->json(['errors' => [['code' => 'NOT_FOUND', 'message' => 'Modèle inconnu.']]], 404);
        }

        $v = $request->validate([
            'subject'   => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string'],
            'locale'    => ['sometimes', 'in:fr,en,ar'],
        ]);
        $locale = $v['locale'] ?? 'fr';
        unset($v['locale']);

        $template = EmailTemplate::updateOrCreate(
            ['key' => $key, 'locale' => $locale],
            [...$v, 'updated_by' => $request->user()->id],
        );

        return response()->json(['data' => [
            'key' => $key, 'label' => self::LABELS[$key], 'locale' => $locale,
            'subject' => $template->subject, 'body_html' => $template->body_html, 'is_custom' => true,
        ]]);
    }

    public function preview(Request $request, string $key): JsonResponse
    {
        if (!in_array($key, self::KEYS, true)) {
            return response()->json(['errors' => [['code' => 'NOT_FOUND', 'message' => 'Modèle inconnu.']]], 404);
        }

        return response()->json(['data' => SystemMailer::preview($key, $this->locale($request))]);
    }

    /** Sends the rendered preview (sample data) to the requesting admin's own email. */
    public function sendTest(Request $request, string $key): JsonResponse
    {
        if (!in_array($key, self::KEYS, true)) {
            return response()->json(['errors' => [['code' => 'NOT_FOUND', 'message' => 'Modèle inconnu.']]], 404);
        }

        $preview = SystemMailer::preview($key, $this->locale($request));
        Mail::to($request->user()->email)->send(new SystemMail('[TEST] '.$preview['subject'], $preview['html']));

        return response()->json(['data' => ['sent_to' => $request->user()->email]]);
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
            $locale = $sub->organization?->locale ?? 'fr';
            $ok = SystemMailer::send('subscription_reminder', $to, [
                'name'           => $name,
                'plan_name'      => $sub->plan?->name ?? '—',
                'expires_at'     => $sub->expires_at?->format('d/m/Y'),
                'days_remaining' => (string) now()->diffInDays($sub->expires_at, false),
            ], $locale);
            if ($ok) $sent++;
        }

        $trialSubs = Subscription::with(['organization'])
            ->where('status', 'trial')
            ->whereBetween('expires_at', [now(), now()->addDays(2)])
            ->get();

        foreach ($trialSubs as $sub) {
            $to   = $sub->organization?->contact_email;
            $name = $sub->organization?->name ?? 'Client Qayed';
            $locale = $sub->organization?->locale ?? 'fr';
            $days = max(0, (int) now()->diffInDays($sub->expires_at, false));
            $date = $sub->expires_at->format('d/m/Y');
            $trialMessage = match ($locale) {
                'en' => $days > 0
                    ? "Your free trial ends in {$days} day(s), on {$date}."
                    : 'Your free trial ends today.',
                'ar' => $days > 0
                    ? "تنتهي تجربتك المجانية خلال {$days} يوم/أيام، بتاريخ {$date}."
                    : 'تنتهي تجربتك المجانية اليوم.',
                default => $days > 0
                    ? "Votre essai gratuit se termine dans {$days} jour(s), le {$date}."
                    : "Votre essai gratuit se termine aujourd'hui.",
            };
            $ok = SystemMailer::send('trial_ending', $to, [
                'name'          => $name,
                'trial_message' => $trialMessage,
                'cta_button' => SystemMailer::ctaButton(SystemMailer::frontendUrl('/hotel/settings'), SystemMailer::label('view_subscriptions', $locale)),
            ], $locale);
            if ($ok) $sent++;
        }

        return response()->json(['data' => ['candidates' => $paidSubs->count() + $trialSubs->count(), 'sent' => $sent]]);
    }
}
