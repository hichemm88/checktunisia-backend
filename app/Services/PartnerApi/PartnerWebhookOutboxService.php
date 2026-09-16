<?php

namespace App\Services\PartnerApi;

use App\Models\FicheSession;
use App\Models\PartnerWebhookDelivery;
use App\Models\PartnerWebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Livraison des webhooks partenaires (§4) — même pattern outbox que
 * WhatsappOutboxService (DB polling, pas de queue Laravel dans ce repo) :
 * claim FIFO verrouillé, backoff exponentiel, désactivation auto de
 * l'endpoint après trop d'échecs consécutifs.
 */
class PartnerWebhookOutboxService
{
    private const CLAIM_LOCK_SECONDS = 120;

    public function enqueueForSession(FicheSession $session, string $event, array $payload): void
    {
        $endpoints = PartnerWebhookEndpoint::where('partner_id', $session->partner_id)
            ->where('active', true)
            ->get()
            ->filter(fn (PartnerWebhookEndpoint $e) => $e->subscribesTo($event));

        foreach ($endpoints as $endpoint) {
            $this->enqueue($endpoint, $event, $payload, $session->id);
        }
    }

    public function enqueue(PartnerWebhookEndpoint $endpoint, string $event, array $payload, string $idempotencyKey): void
    {
        PartnerWebhookDelivery::firstOrCreate(
            ['endpoint_id' => $endpoint->id, 'event_type' => $event, 'idempotency_key' => $idempotencyKey],
            ['payload' => $payload, 'status' => PartnerWebhookDelivery::STATUS_PENDING, 'queued_at' => now()],
        );
    }

    public function claimNextJob(): ?PartnerWebhookDelivery
    {
        return DB::transaction(function () {
            $staleBefore = now()->subSeconds(self::CLAIM_LOCK_SECONDS);

            $job = PartnerWebhookDelivery::query()
                ->where('status', PartnerWebhookDelivery::STATUS_PENDING)
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<=', $staleBefore))
                ->orderBy('queued_at')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();

            if (! $job) {
                return null;
            }

            $job->update(['claimed_at' => now(), 'attempts' => $job->attempts + 1]);

            return $job->fresh();
        });
    }

    public function dispatchPending(int $max = 50): array
    {
        $sent = 0;
        $failed = 0;

        for ($i = 0; $i < $max; $i++) {
            $job = $this->claimNextJob();

            if (! $job) {
                break;
            }

            $endpoint = $job->endpoint;

            if (! $endpoint || ! $endpoint->active) {
                $job->update(['status' => PartnerWebhookDelivery::STATUS_CANCELLED, 'claimed_at' => null]);

                continue;
            }

            if ($this->deliver($job, $endpoint)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function deliver(PartnerWebhookDelivery $job, PartnerWebhookEndpoint $endpoint): bool
    {
        $body = json_encode($job->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = time();
        $signature = $this->sign($body, $timestamp, $endpoint->secret);

        try {
            $response = Http::timeout((int) config('partner_api.webhooks.http_timeout_seconds', 5))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Qayed-Signature' => "t={$timestamp},v1={$signature}",
                    'Qayed-Event' => $job->event_type,
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (\Throwable $e) {
            Log::warning('[partner-webhooks] envoi en exception : '.$e->getMessage());
            $this->markFailed($job, $endpoint, null, $e->getMessage());

            return false;
        }

        if ($response->successful()) {
            $this->markSent($job, $endpoint, $response->status());

            return true;
        }

        $this->markFailed($job, $endpoint, $response->status(), $response->body());

        return false;
    }

    public static function sign(string $body, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    private function markSent(PartnerWebhookDelivery $job, PartnerWebhookEndpoint $endpoint, int $status): void
    {
        $job->update([
            'status' => PartnerWebhookDelivery::STATUS_SENT,
            'last_response_status' => $status,
            'last_error' => null,
            'claimed_at' => null,
            'sent_at' => now(),
        ]);

        if ($endpoint->consecutive_failures > 0) {
            $endpoint->update(['consecutive_failures' => 0]);
        }
    }

    private function markFailed(PartnerWebhookDelivery $job, PartnerWebhookEndpoint $endpoint, ?int $status, ?string $error): void
    {
        $maxAge = (int) config('partner_api.webhooks.max_age_minutes', 1440);
        $ageMinutes = now()->diffInMinutes($job->queued_at);

        if ($ageMinutes >= $maxAge) {
            $job->update([
                'status' => PartnerWebhookDelivery::STATUS_FAILED,
                'last_response_status' => $status,
                'last_error' => $error,
                'claimed_at' => null,
                'next_attempt_at' => null,
            ]);
        } else {
            $job->update([
                'status' => PartnerWebhookDelivery::STATUS_PENDING,
                'last_response_status' => $status,
                'last_error' => $error,
                'claimed_at' => null,
                'next_attempt_at' => $this->nextAttemptAt($job->attempts),
            ]);
        }

        $failures = $endpoint->consecutive_failures + 1;
        $ceiling = (int) config('partner_api.webhooks.auto_disable_after_failures', 20);

        $endpoint->update([
            'consecutive_failures' => $failures,
            'active' => $ceiling > 0 && $failures >= $ceiling ? false : $endpoint->active,
        ]);
    }

    private function nextAttemptAt(int $attempts): \Illuminate\Support\Carbon
    {
        $schedule = config('partner_api.webhooks.retry_schedule_minutes', [1, 5, 15, 60, 240, 1440]);
        $index = min(max($attempts - 1, 0), count($schedule) - 1);

        return now()->addMinutes((int) $schedule[$index]);
    }

    public function redrive(PartnerWebhookDelivery $delivery): void
    {
        $delivery->update([
            'status' => PartnerWebhookDelivery::STATUS_PENDING,
            'attempts' => 0,
            'next_attempt_at' => now(),
            'claimed_at' => null,
        ]);
    }
}
