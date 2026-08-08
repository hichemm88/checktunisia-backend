<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\WhatsappSendLog;
use App\Services\Delivery\DeliveryChannelManager;
use App\Services\Delivery\WhatsAppCloudChannel;
use App\Services\Delivery\WhatsAppWebChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Canal de transmission interchangeable (STRAT-07).
 *
 * Enjeu : le contournement du relais WhatsApp non officiel expire le
 * 10/09/2026. L'extraction doit permettre de basculer sans opération
 * chirurgicale — et surtout sans changer le comportement d'ici là.
 */
class DeliveryChannelTest extends TestCase
{
    use RefreshDatabase;

    // ── Sélection du canal ───────────────────────────────────────────────────

    public function test_web_channel_is_the_default(): void
    {
        // Rien ne doit changer en production tant que la variable n'est pas posée.
        $this->assertInstanceOf(WhatsAppWebChannel::class, app(DeliveryChannelManager::class)->active());
    }

    public function test_an_unknown_channel_fails_loudly(): void
    {
        config(['whatsapp.channel' => 'sftp-inexistant']);

        // Surtout pas de repli silencieux : une faute de frappe ferait croire
        // que la bascule a eu lieu alors que l'ancien canal continue.
        $this->expectException(\InvalidArgumentException::class);
        app(DeliveryChannelManager::class)->active();
    }

    // ── La différence de format entre canaux ─────────────────────────────────

    public function test_the_two_channels_format_recipients_differently(): void
    {
        $web = new WhatsAppWebChannel();
        $cloud = new WhatsAppCloudChannel();

        // C'est précisément ce détail qui aurait fuité partout sans interface.
        $this->assertSame('21620123456@c.us', $web->formatRecipient('+216 20 123 456'));
        $this->assertSame('21620123456', $cloud->formatRecipient('+216 20 123 456'));
    }

    public function test_cloud_channel_rejects_truncated_numbers(): void
    {
        $cloud = new WhatsAppCloudChannel();

        $this->assertNull($cloud->formatRecipient('123'), 'Un numéro tronqué doit être refusé, pas transmis.');
        $this->assertNull($cloud->formatRecipient(null));
    }

    public function test_web_channel_is_pull_and_refuses_direct_send(): void
    {
        // La transmission passe par le worker Node ; appeler send() ici serait
        // un bug d'appelant, il doit être bruyant.
        $this->expectException(\LogicException::class);
        (new WhatsAppWebChannel())->send(new WhatsappSendLog());
    }

    // ── Transmission par la Cloud API ────────────────────────────────────────

    private function configureCloud(): void
    {
        config([
            'whatsapp.enabled' => true,
            'whatsapp.channel' => 'cloud',
            'whatsapp.cloud.token' => 'test-token',
            'whatsapp.cloud.phone_number_id' => '123456',
        ]);
    }

    public function test_cloud_channel_sends_and_returns_the_message_id(): void
    {
        $this->configureCloud();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]], 200),
        ]);

        $job = new WhatsappSendLog(['recipient' => '21620123456', 'caption' => 'Fiche voyageur']);

        $result = (new WhatsAppCloudChannel())->send($job);

        $this->assertTrue($result->success);
        $this->assertSame('wamid.TEST', $result->messageId);

        Http::assertSent(fn ($request) => $request['messaging_product'] === 'whatsapp'
            && $request['to'] === '21620123456'
            && $request['text']['body'] === 'Fiche voyageur');
    }

    public function test_cloud_channel_marks_4xx_as_permanent(): void
    {
        $this->configureCloud();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid recipient']], 400),
        ]);

        $result = (new WhatsAppCloudChannel())->send(
            new WhatsappSendLog(['recipient' => '21620123456', 'caption' => 'x'])
        );

        $this->assertFalse($result->success);
        // Retenter à l'identique ne changerait rien : ce serait gaspiller des
        // tentatives et retarder l'alerte.
        $this->assertFalse($result->retryable);
        $this->assertSame('Invalid recipient', $result->error);
    }

    public function test_cloud_channel_marks_5xx_and_429_as_retryable(): void
    {
        $this->configureCloud();

        foreach ([500, 503, 429] as $status) {
            Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'oops']], $status)]);

            $result = (new WhatsAppCloudChannel())->send(
                new WhatsappSendLog(['recipient' => '21620123456', 'caption' => 'x'])
            );

            $this->assertFalse($result->success, "HTTP {$status}");
            $this->assertTrue($result->retryable, "HTTP {$status} doit être réessayable.");
        }
    }

    public function test_cloud_channel_refuses_to_send_when_unconfigured(): void
    {
        config(['whatsapp.enabled' => true, 'whatsapp.cloud.token' => null]);

        Http::fake();

        $result = (new WhatsAppCloudChannel())->send(
            new WhatsappSendLog(['recipient' => '21620123456', 'caption' => 'x'])
        );

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    // ── Mode ombre ───────────────────────────────────────────────────────────

    public function test_shadow_mode_never_transmits(): void
    {
        config([
            'whatsapp.enabled' => true,
            'whatsapp.recipient' => '21611111111@c.us',
            'whatsapp.channel' => 'web',
            'whatsapp.shadow_channel' => 'cloud',
        ]);

        Http::fake();

        $hotel = Hotel::factory()->withActiveSubscription()->create();
        app(DeliveryChannelManager::class)->compareRecipients($hotel, ['21611111111@c.us']);

        // Une comparaison qui enverrait de vrais messages serait pire que pas
        // de comparaison du tout.
        Http::assertNothingSent();
    }

    public function test_shadow_mode_reports_a_recipient_count_mismatch(): void
    {
        config([
            'whatsapp.enabled' => true,
            'whatsapp.recipient' => '',   // le canal cible ne résoudra personne
            'whatsapp.channel' => 'web',
            'whatsapp.shadow_channel' => 'cloud',
        ]);

        Log::spy();

        $hotel = Hotel::factory()->withActiveSubscription()->create();

        // Le canal actif a un destinataire, le canal cible zéro : après
        // bascule, la fiche ne partirait plus. C'est exactement l'écart que le
        // mode ombre doit faire remonter AVANT la bascule.
        app(DeliveryChannelManager::class)->compareRecipients($hotel, ['21611111111@c.us']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'delivery-shadow'))
            ->atLeast()->once();
    }

    public function test_shadow_mode_is_inert_when_not_configured(): void
    {
        config(['whatsapp.shadow_channel' => null]);

        $this->assertNull(app(DeliveryChannelManager::class)->shadow());
    }

    public function test_shadow_channel_identical_to_active_is_ignored(): void
    {
        config(['whatsapp.channel' => 'web', 'whatsapp.shadow_channel' => 'web']);

        // Se comparer à soi-même ne produirait que du bruit.
        $this->assertNull(app(DeliveryChannelManager::class)->shadow());
    }
}
