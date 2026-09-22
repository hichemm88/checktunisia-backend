<?php

namespace Tests\Feature\Prospection;

use App\Models\Prospection\Establishment;
use App\Models\Prospection\ProspectionUser;
use App\Services\Prospection\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Prospection\FakeWebPushSender;
use Tests\TestCase;

class DigestCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sends_only_to_users_whose_hour_just_matched(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 08:30:00', 'Africa/Tunis'));

        $due = ProspectionUser::create([
            'name' => 'À l\'heure', 'email' => 'due@qayed.tn', 'password' => Hash::make('x'),
            'notif_digest_enabled' => true, 'notif_digest_hour' => '08:30',
        ]);
        ProspectionUser::create([
            'name' => 'Pas encore', 'email' => 'later@qayed.tn', 'password' => Hash::make('x'),
            'notif_digest_enabled' => true, 'notif_digest_hour' => '09:00',
        ]);
        ProspectionUser::create([
            'name' => 'Désactivé', 'email' => 'off@qayed.tn', 'password' => Hash::make('x'),
            'notif_digest_enabled' => false, 'notif_digest_hour' => '08:30',
        ]);

        $fake = new FakeWebPushSender;
        $this->app->instance(WebPushSender::class, $fake);

        $this->artisan('prospection:send-digest')->assertSuccessful();

        $this->assertSame([$due->id], $fake->recipientIds());
        $this->assertSame('2026-09-22', $due->fresh()->last_digest_sent_at->toDateString());
    }

    public function test_does_not_send_twice_the_same_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 08:30:00', 'Africa/Tunis'));

        ProspectionUser::create([
            'name' => 'À l\'heure', 'email' => 'due@qayed.tn', 'password' => Hash::make('x'),
            'notif_digest_enabled' => true, 'notif_digest_hour' => '08:30',
            'last_digest_sent_at' => '2026-09-22',
        ]);

        $fake = new FakeWebPushSender;
        $this->app->instance(WebPushSender::class, $fake);

        $this->artisan('prospection:send-digest')->assertSuccessful();

        $this->assertSame([], $fake->recipientIds());
    }

    public function test_digest_body_counts_relances_and_demos(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 08:30:00', 'Africa/Tunis'));

        $user = ProspectionUser::create([
            'name' => 'À l\'heure', 'email' => 'due@qayed.tn', 'password' => Hash::make('x'),
            'notif_digest_enabled' => true, 'notif_digest_hour' => '08:30',
        ]);

        Establishment::create(['name' => 'Relance A', 'status' => 'relance', 'next_action_at' => now()->subDay()]);
        Establishment::create(['name' => 'Demo A', 'status' => 'demo_planifiee', 'next_action_at' => now()->addHours(2)]);

        $fake = new FakeWebPushSender;
        $this->app->instance(WebPushSender::class, $fake);

        $this->artisan('prospection:send-digest')->assertSuccessful();

        $this->assertCount(1, $fake->sent);
        $this->assertStringContainsString('1 relance(s)', $fake->sent[0]['body']);
        $this->assertStringContainsString('1 démo(s)', $fake->sent[0]['body']);
    }
}
