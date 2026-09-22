<?php

namespace Tests\Support\Prospection;

use App\Models\Prospection\ProspectionUser;
use App\Services\Prospection\WebPushSender;

/**
 * Double de test pour App\Services\Prospection\WebPushSender : enregistre
 * les envois au lieu d'appeler le client Web Push réel (pas d'appel réseau
 * en test). Bind avec `$this->app->instance(WebPushSender::class, ...)`.
 */
class FakeWebPushSender extends WebPushSender
{
    /** @var array<int, array{user_id: string, title: string, body: string, data: array}> */
    public array $sent = [];

    public function __construct() {}

    public function sendToUser(ProspectionUser $user, string $title, string $body, array $data = []): void
    {
        $this->sent[] = ['user_id' => $user->id, 'title' => $title, 'body' => $body, 'data' => $data];
    }

    /** @return string[] */
    public function recipientIds(): array
    {
        return array_column($this->sent, 'user_id');
    }
}
