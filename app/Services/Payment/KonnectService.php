<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Konnect Payment Gateway — Tunisie
 *
 * Docs : https://docs.konnect.network
 *
 * Déroulé :
 *   1. createPayment()  → { payUrl, paymentRef } — on redirige le client vers payUrl
 *   2. le client règle sur la page hébergée Konnect
 *   3. Konnect renvoie le client sur successUrl / failUrl (?payment_ref=…)
 *      ET appelle notre webhook — les deux chemins convergent vers le même
 *      encaissement, protégé par idempotence.
 *   4. verifyPayment() → seul juge du sort réel du paiement
 *
 * Identifiants requis (back-office d'abord, environnement en repli) :
 *   KONNECT_API_KEY    — « <idOrganisation>:<secret> », en-tête x-api-key
 *   KONNECT_WALLET_ID  — portefeuille receveur
 *   KONNECT_ENV        — sandbox (simulation) | production
 */
class KonnectService implements PaymentGateway
{
    /**
     * Identifiants effectifs, résolus À CHAQUE APPEL.
     *
     * Même raison que pour Flouci : les figer au constructeur les résoudrait
     * avant même qu'on sache quelle facture est réglée, et un changement de
     * configuration au back-office ne prendrait effet qu'au redémarrage.
     *
     * @return array{api_key: string, wallet_id: string, environment: string, base_url: string}
     */
    private function credentials(): array
    {
        return \App\Models\PlatformSetting::get()->konnectCredentials();
    }

    /**
     * Ouvre une session de paiement hébergée chez Konnect.
     *
     * @param  array<string, mixed> $context  first_name, last_name, email,
     *                                        phone, order_id, description
     * @return array{payment_id: string, payment_url: string}
     *
     * @throws \RuntimeException
     */
    public function createPayment(int $amountMillimes, string $trackingId, array $context = []): array
    {
        $credentials = $this->credentials();

        $payload = array_filter([
            'receiverWalletId'       => $credentials['wallet_id'],
            'token'                  => 'TND',
            'amount'                 => $amountMillimes,
            'type'                   => 'immediate',
            'description'            => $context['description'] ?? 'Abonnement Qayed',
            'acceptedPaymentMethods' => (array) config('konnect.accepted_payment_methods', ['bank_card']),
            'lifespan'               => (int) config('konnect.lifespan_minutes', 15),
            'checkoutForm'           => (bool) config('konnect.checkout_form', false),
            'addPaymentFeesToAmount' => (bool) config('konnect.add_fees_to_amount', false),
            // C'est par `orderId` qu'on retrouve une facture dans le tableau de
            // bord Konnect le jour d'un rapprochement comptable. Notre UUID de
            // suivi n'y dirait rien à personne.
            'orderId'                => $context['order_id'] ?? $trackingId,
            'firstName'              => $context['first_name'] ?? null,
            'lastName'               => $context['last_name'] ?? null,
            'email'                  => $context['email'] ?? null,
            'phoneNumber'            => $this->localPhone($context['phone'] ?? null),
            'successUrl'             => config('konnect.success_url'),
            'failUrl'                => config('konnect.fail_url'),
            'webhook'                => $this->webhookUrl(),
        ], fn ($value) => $value !== null && $value !== '');

        Log::info('Konnect: initiating payment', [
            'tracking_id'     => $trackingId,
            'order_id'        => $payload['orderId'] ?? null,
            'amount_millimes' => $amountMillimes,
            'environment'     => $credentials['environment'],
        ]);

        $response = Http::withHeaders(['x-api-key' => $credentials['api_key']])
            ->timeout(15)
            ->post("{$credentials['base_url']}/payments/init-payment", $payload);

        if (! $response->successful()) {
            Log::error('Konnect: HTTP error on createPayment', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException("Konnect gateway error: HTTP {$response->status()}");
        }

        $data = $response->json() ?? [];

        if (blank($data['payUrl'] ?? null) || blank($data['paymentRef'] ?? null)) {
            Log::error('Konnect: createPayment returned an unusable response', ['response' => $data]);
            throw new \RuntimeException('Konnect: payment creation failed');
        }

        return [
            'payment_id'  => (string) $data['paymentRef'],
            'payment_url' => (string) $data['payUrl'],
        ];
    }

    /**
     * Constate le sort d'un paiement auprès de Konnect.
     *
     * @return array{success: bool, pending: bool, status: string, payment_id: string, raw: array<string, mixed>}
     *
     * @throws \RuntimeException
     */
    public function verifyPayment(string $providerPaymentId): array
    {
        $credentials = $this->credentials();

        $response = Http::withHeaders(['x-api-key' => $credentials['api_key']])
            ->timeout(15)
            ->get("{$credentials['base_url']}/payments/{$providerPaymentId}");

        if (! $response->successful()) {
            Log::error('Konnect: HTTP error on verifyPayment', [
                'payment_ref' => $providerPaymentId,
                'status'      => $response->status(),
            ]);
            throw new \RuntimeException("Konnect verify error: HTTP {$response->status()}");
        }

        $data    = $response->json() ?? [];
        $payment = (array) ($data['payment'] ?? $data);
        $status  = (string) ($payment['status'] ?? 'unknown');

        $success = $status === 'completed' && $this->fullyPaid($payment);

        // « pending » n'est pas un échec : le client peut être en train de
        // saisir sa carte, ou avoir fermé l'onglet une seconde avant que
        // Konnect ne tranche. Le paiement reste en attente jusqu'à son
        // expiration, et le webhook a encore le droit de le conclure.
        $pending = ! $success && $status === 'pending';

        Log::info('Konnect: verifyPayment result', [
            'payment_ref'   => $providerPaymentId,
            'status'        => $status,
            'success'       => $success,
            'pending'       => $pending,
            'amount'        => $payment['amount'] ?? null,
            'reachedAmount' => $payment['reachedAmount'] ?? null,
        ]);

        return [
            'success'    => $success,
            'pending'    => $pending,
            'status'     => $status,
            'payment_id' => $providerPaymentId,
            'raw'        => $data,
        ];
    }

    /**
     * Le montant attendu est-il RÉELLEMENT rentré ?
     *
     * Konnect sait encaisser partiellement : un paiement peut porter un statut
     * favorable alors que `amountDue` n'est pas retombé à zéro. Prolonger un
     * abonnement sur un encaissement partiel reviendrait à offrir la
     * différence, en silence et sans trace comptable.
     *
     * On exige donc trois choses concordantes : le montant atteint couvre le
     * montant dû, il ne reste rien à devoir, et au moins une transaction a
     * réellement abouti. En l'absence de ces champs (réponse plus pauvre que
     * documentée), on s'en remet au statut seul plutôt que de bloquer un
     * règlement légitime.
     *
     * @param  array<string, mixed> $payment
     */
    private function fullyPaid(array $payment): bool
    {
        $amount  = $payment['amount'] ?? null;
        $reached = $payment['reachedAmount'] ?? null;

        if (is_numeric($amount) && is_numeric($reached) && (float) $reached < (float) $amount) {
            return false;
        }

        if (is_numeric($payment['amountDue'] ?? null) && (float) $payment['amountDue'] > 0) {
            return false;
        }

        $transactions = $payment['transactions'] ?? null;

        if (is_array($transactions) && $transactions !== []) {
            foreach ($transactions as $transaction) {
                if (($transaction['status'] ?? null) === 'success') {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Numéro tunisien à 8 chiffres, ou rien.
     *
     * Konnect attend la forme locale (« 22777777 »), alors qu'un numéro saisi
     * dans Qayed peut porter un indicatif ou des espaces. Un champ mal formé
     * ferait échouer l'initiation ENTIÈRE : le client se verrait refuser le
     * paiement à cause d'un détail de saisie. Un préremplissage est un confort
     * — dans le doute, on n'envoie rien plutôt que de bloquer le règlement.
     */
    private function localPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '216')) {
            $digits = substr($digits, 3);
        }

        return strlen($digits) === 8 ? $digits : null;
    }

    /**
     * URL annoncée à Konnect pour le rappel serveur.
     *
     * Le jeton est un segment de CHEMIN et non un paramètre de requête : les
     * paramètres se retrouvent dans les journaux d'accès des intermédiaires,
     * et Konnect ajoute les siens à la suite de l'URL fournie.
     *
     * Sans jeton configuré, aucune URL n'est transmise : mieux vaut un webhook
     * absent qu'un webhook ouvert à tous.
     *
     * Publique parce qu'elle se VÉRIFIE : poser le jeton ne suffit pas, c'est
     * la base (KONNECT_WEBHOOK_URL, à défaut APP_URL) qui décide où Konnect
     * appellera réellement. Une base fausse donne un webhook silencieux, et
     * rien ne le distingue d'un webhook absent tant qu'on ne l'a pas regardé.
     * @see \App\Console\Commands\TestKonnectPayment
     */
    public function webhookUrl(): ?string
    {
        $token = (string) config('konnect.webhook_token', '');

        if ($token === '') {
            return null;
        }

        $base = rtrim((string) (config('konnect.webhook_url') ?: config('app.url')), '/');

        return "{$base}/api/v1/payments/konnect/webhook/{$token}";
    }
}
