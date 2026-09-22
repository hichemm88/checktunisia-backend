<?php

namespace App\Services\Prospection;

use App\Models\Prospection\ProspectionUser;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Envoi de notifications Web Push (§ Notifications push). Le client
 * `Minishlink\WebPush\WebPush` est injecté (voir ProspectionServiceProvider,
 * singleton configuré avec les clés VAPID) plutôt qu'instancié ici, pour
 * pouvoir le remplacer par un faux en test sans appel réseau réel.
 *
 * Jamais bloquant : un push qui échoue ne doit jamais faire échouer l'action
 * qui l'a déclenché (journalisation d'une action, changement de statut...) —
 * même convention que App\Services\Notifications\PushNotificationService
 * pour les push mobiles de l'app principale.
 */
class WebPushSender
{
    public function __construct(private WebPush $client) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendToUser(ProspectionUser $user, string $title, string $body, array $data = []): void
    {
        $subscriptions = $user->pushSubscriptions()->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        try {
            $payload = json_encode(['title' => $title, 'body' => $body, 'data' => $data], JSON_THROW_ON_ERROR);

            foreach ($subscriptions as $subscription) {
                $this->client->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'publicKey' => $subscription->public_key,
                        'authToken' => $subscription->auth_token,
                        'contentEncoding' => $subscription->content_encoding,
                    ]),
                    $payload,
                );
            }

            foreach ($this->client->flush() as $report) {
                $subscription = $subscriptions->firstWhere('endpoint', $report->getEndpoint());

                if ($report->isSuccess()) {
                    $subscription?->forceFill(['last_used_at' => now()])->save();

                    continue;
                }

                if ($report->isSubscriptionExpired()) {
                    // 404/410 : le navigateur a révoqué cet abonnement (désinstallation,
                    // effacement des données du site...) — le garder ne ferait
                    // qu'échouer à chaque envoi futur.
                    $subscription?->delete();

                    continue;
                }

                Log::warning('Web push failed', [
                    'user_id' => $user->id,
                    'endpoint' => $report->getEndpoint(),
                    'reason' => $report->getReason(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('WebPushSender failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
