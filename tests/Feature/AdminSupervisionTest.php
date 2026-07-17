<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappSessionState;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Chantier B3 — supervision opérationnelle : export CSV du journal
 * d'activité, liste des actions pour les filtres, et alerte « worker
 * WhatsApp silencieux ».
 */
class AdminSupervisionTest extends TestCase
{
    use RefreshDatabase;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->platformAdmin = User::factory()->platformAdmin()->create();
    }

    public function test_audit_log_actions_and_csv_export(): void
    {
        $this->actingAs($this->platformAdmin);
        AuditLogger::log('hotel.created', $this->platformAdmin);
        AuditLogger::log('invoice.updated', $this->platformAdmin);

        $actions = $this->getJson('/api/v1/admin/audit-logs/actions')->assertOk()->json('data');
        $this->assertContains('hotel.created', $actions);
        $this->assertContains('invoice.updated', $actions);

        $response = $this->get('/api/v1/admin/audit-logs/export?action=hotel.created');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('hotel.created', $csv);
        $this->assertStringNotContainsString('invoice.updated', $csv);
    }

    public function test_export_requires_platform_admin(): void
    {
        $hotel = \App\Models\Hotel::factory()->create();
        $receptionist = User::factory()->receptionist($hotel)->create();

        $this->actingAs($receptionist)->get('/api/v1/admin/audit-logs/export')->assertForbidden();
    }

    public function test_silent_worker_triggers_one_alert_until_heartbeat_returns(): void
    {
        config(['whatsapp.enabled' => true, 'whatsapp.recipient' => 'x@c.us']);
        Mail::fake();

        $state = WhatsappSessionState::current();
        $state->forceFill(['heartbeat_at' => now()->subMinutes(30)])->save();

        $this->artisan('whatsapp:check-health')->assertOk();
        Mail::assertSent(\App\Mail\SystemMail::class, 1);

        // Deuxième passage pendant la même panne : pas de spam.
        $this->artisan('whatsapp:check-health')->assertOk();
        Mail::assertSent(\App\Mail\SystemMail::class, 1);

        // Le worker revient → le flag est levé, une future panne réalerte.
        $state->forceFill(['heartbeat_at' => now()])->save();
        $this->artisan('whatsapp:check-health')->assertOk();
        $this->assertNull(Cache::get('whatsapp:worker-silent-alerted'));
    }
}
