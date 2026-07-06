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
    private const KEYS = ['welcome', 'account_suspended', 'payment_received', 'subscription_reminder'];

    private const LABELS = [
        'welcome'                => 'Bienvenue',
        'account_suspended'      => 'Suspension de compte',
        'payment_received'       => 'Paiement reçu',
        'subscription_reminder'  => "Rappel d'expiration",
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

    /** Manually trigger reminder emails for subscriptions expiring within 7 days. */
    public function sendReminders(): JsonResponse
    {
        $subs = Subscription::with(['plan', 'organization', 'hotel'])
            ->where('status', 'active')
            ->whereBetween('expires_at', [now(), now()->addDays(7)])
            ->get();

        $sent = 0;
        foreach ($subs as $sub) {
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

        return response()->json(['data' => ['candidates' => $subs->count(), 'sent' => $sent]]);
    }
}
