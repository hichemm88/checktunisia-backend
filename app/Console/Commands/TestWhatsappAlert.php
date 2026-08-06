<?php

namespace App\Console\Commands;

use App\Services\Whatsapp\WhatsappAlertService;
use Illuminate\Console\Command;

/**
 * MODULE PROVISOIRE — à retirer après homologation MI.
 * Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
 *
 * Envoie l'email d'alerte « session déconnectée » aux platform_admin, marqué
 * [TEST], sans toucher à l'état réel de la session. Permet de vérifier
 * l'habillage et le bouton « Reconnecter (scanner le QR) » sans provoquer de
 * vraie déconnexion. À lancer depuis la console Railway :
 *   php artisan whatsapp:test-alert
 */
class TestWhatsappAlert extends Command
{
    protected $signature = 'whatsapp:test-alert';

    protected $description = "Envoie l'email d'alerte de déconnexion aux admins, marqué [TEST] (relais WhatsApp, provisoire)";

    public function handle(WhatsappAlertService $alerts): int
    {
        $alerts->sessionDown(
            'disconnected',
            '[TEST] Vérification de l\'email d\'alerte — la session est en réalité connectée, aucune action nécessaire.',
        );

        $this->info('Alerte [TEST] envoyée aux platform_admin (email + push).');
        if (! config('whatsapp.qr_url')) {
            $this->warn('WHATSAPP_QR_URL est vide : l\'email partira sans le bouton « Reconnecter ».');
        }

        return self::SUCCESS;
    }
}
