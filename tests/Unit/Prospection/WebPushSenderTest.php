<?php

namespace Tests\Unit\Prospection;

use App\Models\Prospection\ProspectionUser;
use App\Models\Prospection\PushSubscription;
use App\Services\Prospection\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Tests\TestCase;

class WebPushSenderTest extends TestCase
{
    use RefreshDatabase;

    private function userWithSubscription(string $endpoint = 'https://push.example/abc'): ProspectionUser
    {
        $user = ProspectionUser::create([
            'name' => 'Hichem', 'email' => 'hichem@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'), 'role' => 'admin',
        ]);

        PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'public_key' => 'public-key',
            'auth_token' => 'auth-secret',
            'content_encoding' => 'aesgcm',
        ]);

        return $user;
    }

    private function reportFor(string $endpoint, bool $success, bool $expired = false): MessageSentReport
    {
        $report = $this->createMock(MessageSentReport::class);
        $report->method('getEndpoint')->willReturn($endpoint);
        $report->method('isSuccess')->willReturn($success);
        $report->method('isSubscriptionExpired')->willReturn($expired);
        $report->method('getReason')->willReturn('some failure');

        return $report;
    }

    public function test_does_nothing_when_the_user_has_no_subscription(): void
    {
        $user = ProspectionUser::create([
            'name' => 'Hichem', 'email' => 'hichem@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'), 'role' => 'admin',
        ]);

        $client = $this->createMock(WebPush::class);
        $client->expects($this->never())->method('queueNotification');

        (new WebPushSender($client))->sendToUser($user, 'Titre', 'Corps');
    }

    public function test_a_successful_send_touches_last_used_at(): void
    {
        $user = $this->userWithSubscription();

        $client = $this->createMock(WebPush::class);
        $client->expects($this->once())->method('queueNotification');
        $client->method('flush')->willReturn((function () use ($user) {
            yield $this->reportFor($user->pushSubscriptions()->first()->endpoint, success: true);
        })());

        (new WebPushSender($client))->sendToUser($user, 'Titre', 'Corps');

        $this->assertNotNull($user->pushSubscriptions()->first()->last_used_at);
    }

    public function test_an_expired_subscription_is_deleted(): void
    {
        $user = $this->userWithSubscription();
        $endpoint = $user->pushSubscriptions()->first()->endpoint;

        $client = $this->createMock(WebPush::class);
        $client->method('flush')->willReturn((function () use ($endpoint) {
            yield $this->reportFor($endpoint, success: false, expired: true);
        })());

        (new WebPushSender($client))->sendToUser($user, 'Titre', 'Corps');

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_a_non_expired_failure_keeps_the_subscription(): void
    {
        $user = $this->userWithSubscription();
        $endpoint = $user->pushSubscriptions()->first()->endpoint;

        $client = $this->createMock(WebPush::class);
        $client->method('flush')->willReturn((function () use ($endpoint) {
            yield $this->reportFor($endpoint, success: false, expired: false);
        })());

        (new WebPushSender($client))->sendToUser($user, 'Titre', 'Corps');

        $this->assertSame(1, PushSubscription::count());
    }
}
