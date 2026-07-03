<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Flouci Payment Gateway — Tunisia
 *
 * Docs: https://developers.flouci.com
 *
 * Flow:
 *   1. createPayment()  → returns payment_id + payment_url (redirect user to this URL)
 *   2. User completes payment on Flouci's hosted page
 *   3. Flouci redirects user to success_url or fail_url (query param: payment_id)
 *   4. verifyPayment()  → confirm status server-side
 *
 * Required env vars:
 *   FLOUCI_APP_TOKEN   — provided by Flouci merchant dashboard
 *   FLOUCI_APP_SECRET  — provided by Flouci merchant dashboard
 */
class FlouciService
{
    private string $appToken;
    private string $appSecret;
    private string $baseUrl;

    public function __construct()
    {
        $this->appToken  = config('flouci.app_token');
        $this->appSecret = config('flouci.app_secret');
        $this->baseUrl   = rtrim(config('flouci.base_url', 'https://developers.flouci.com/api'), '/');
    }

    /**
     * Create a Flouci hosted payment session.
     *
     * @param  int    $amountMillimes  Amount in TND millimes (1.500 TND = 1500)
     * @param  string $trackingId      Our internal reference (UUID), stored as developer_tracking_id
     * @return array{payment_id: string, payment_url: string}
     *
     * @throws \RuntimeException  if the gateway is unavailable or returns an error
     */
    public function createPayment(int $amountMillimes, string $trackingId): array
    {
        $payload = [
            'app_token'             => $this->appToken,
            'app_secret'            => $this->appSecret,
            'amount'                => $amountMillimes,
            'accept_card'           => true,
            'session_timeout_secs'  => (int) config('flouci.timeout_secs', 900),
            'success_link'          => config('flouci.success_url'),
            'fail_link'             => config('flouci.fail_url'),
            'developer_tracking_id' => $trackingId,
        ];

        Log::info('Flouci: initiating payment', ['tracking_id' => $trackingId, 'amount_millimes' => $amountMillimes]);

        $response = Http::timeout(15)->post("{$this->baseUrl}/generate_payment", $payload);

        if (!$response->successful()) {
            Log::error('Flouci: HTTP error on createPayment', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException("Flouci gateway error: HTTP {$response->status()}");
        }

        $data = $response->json();

        if (!($data['result']['success'] ?? false)) {
            Log::error('Flouci: createPayment returned failure', ['response' => $data]);
            throw new \RuntimeException('Flouci: payment creation failed');
        }

        return [
            'payment_id'  => $data['result']['paymentId'],
            'payment_url' => $data['result']['link'],
        ];
    }

    /**
     * Verify a Flouci payment by ID (server-side check after redirect).
     *
     * @return array{success: bool, status: string, payment_id: string, raw: array}
     * @throws \RuntimeException
     */
    public function verifyPayment(string $paymentId): array
    {
        $response = Http::timeout(15)->get("{$this->baseUrl}/verify_payment/{$paymentId}", [
            'app_token'  => $this->appToken,
            'app_secret' => $this->appSecret,
        ]);

        if (!$response->successful()) {
            Log::error('Flouci: HTTP error on verifyPayment', [
                'payment_id' => $paymentId,
                'status'     => $response->status(),
            ]);
            throw new \RuntimeException("Flouci verify error: HTTP {$response->status()}");
        }

        $data   = $response->json();
        $status = $data['result']['status'] ?? 'UNKNOWN';

        Log::info('Flouci: verifyPayment result', ['payment_id' => $paymentId, 'status' => $status]);

        return [
            'success'    => $status === 'SUCCESS',
            'status'     => $status,
            'payment_id' => $paymentId,
            'raw'        => $data,
        ];
    }
}
