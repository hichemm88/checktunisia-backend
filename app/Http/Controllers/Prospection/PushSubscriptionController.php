<?php

namespace App\Http\Controllers\Prospection;

use App\Http\Controllers\Controller;
use App\Models\Prospection\PushSubscription;
use App\Services\Prospection\WebPushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Abonnement Web Push d'un appareil (§ Notifications push). `store` est
 * appelé après que le navigateur a accordé la permission ET créé un
 * PushSubscription côté client (Service Worker `pushManager.subscribe`) ;
 * upsert par endpoint pour que réabonner un appareil déjà connu ne crée pas
 * de doublon.
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'content_encoding' => ['sometimes', 'nullable', 'string'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['content_encoding'] ?? 'aesgcm',
                'last_used_at' => now(),
            ],
        );

        return response()->json(['data' => ['subscribed' => true]], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string']]);

        // Pure suppression en base : pas besoin du client Web Push (donc pas
        // de résolution de WebPushSender ici) pour simplement oublier un
        // abonnement, voir aussi PushSubscription::forgetFor().
        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->delete();

        return response()->json(['data' => ['unsubscribed' => true]]);
    }

    /**
     * Bouton "Tester les notifications" (§ Réglages) : seul cas où un
     * utilisateur se notifie lui-même — volontaire, pour vérifier que
     * l'installation fonctionne, contrairement aux 3 déclencheurs
     * automatiques qui ne notifient jamais leur propre auteur.
     */
    public function test(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->pushSubscriptions()->count() === 0) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'NO_SUBSCRIPTION', 'message' => 'Aucun appareil abonné aux notifications.', 'field' => null]],
            ], 422);
        }

        app(WebPushSender::class)->sendToUser(
            $user,
            'Qayed CRM',
            'Notifications activées — vous recevrez le récap du matin, les rappels de démo et l\'activité de l\'équipe ici.',
        );

        return response()->json(['data' => ['sent' => true]]);
    }
}
