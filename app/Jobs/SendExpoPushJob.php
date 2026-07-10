<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notifications to devices via the Expo Push API. Queued so it never blocks the
 * request that triggered it; failures are logged, never rethrown (§6.3 — push must never
 * make a check-in fail).
 *
 * Expo relays to FCM (Android) / APNs (iOS), so no Firebase service account is required —
 * the app registers Expo push tokens ("ExponentPushToken[...]").
 */
class SendExpoPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    /**
     * @param  string[]              $tokens
     * @param  array<string,mixed>   $data
     */
    public function __construct(
        public array $tokens,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    public function handle(): void
    {
        $messages = collect($this->tokens)
            ->filter(fn ($t) => is_string($t) && str_starts_with($t, 'ExponentPushToken'))
            ->unique()
            ->map(fn ($t) => [
                'to'        => $t,
                'title'     => $this->title,
                'body'      => $this->body,
                'data'      => $this->data,
                'sound'     => 'default',
                'channelId' => 'default',
                'priority'  => 'high',
            ])
            ->values();

        if ($messages->isEmpty()) {
            return;
        }

        // Expo accepts up to 100 messages per request.
        foreach ($messages->chunk(100) as $chunk) {
            try {
                $response = Http::acceptJson()->asJson()->post(self::ENDPOINT, $chunk->all());
                if (!$response->successful()) {
                    Log::warning('Expo push returned non-2xx', [
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Expo push send failed', ['error' => $e->getMessage()]);
            }
        }
    }
}
