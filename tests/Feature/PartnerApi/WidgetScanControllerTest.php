<?php

namespace Tests\Feature\PartnerApi;

use App\Models\AiUsageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * §3 — POST /widget/v1/scan : jamais d'appel réseau réel à Claude dans les
 * tests (même discipline que FicheScanCropperTest) — on éprouve le chemin
 * "lecture désactivée/non configurée" (config sans clé, comme en CI), qui
 * doit échouer PROPREMENT et immédiatement au lieu de laisser le statut
 * bloqué en 'skipped' sans jamais le signaler au widget (régression corrigée
 * ici : c'était le comportement réel en production avant ce correctif).
 */
class WidgetScanControllerTest extends TestCase
{
    use InteractsWithPartnerApi;
    use RefreshDatabase;

    private function bootstrap(array $fixture, string $bookingRef): string
    {
        $create = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($fixture['hotel'], $bookingRef))
            ->assertSuccessful();

        $query = parse_url($create->json('widget_url'), PHP_URL_QUERY);
        parse_str((string) $query, $params);

        return $this->getJson('/widget/v1/bootstrap?token='.$params['token'])
            ->assertOk()
            ->json('widget_token');
    }

    public function test_scan_upload_without_a_configured_vision_key_fails_cleanly_instead_of_hanging(): void
    {
        config(['ocr.widget_vision.api_key' => '', 'ocr.widget_vision.enabled' => true]);

        $fixture = $this->setUpLinkedPartner();
        $widgetToken = $this->bootstrap($fixture, 'BK-SCAN-1');

        $upload = $this->withHeaders(['Authorization' => 'Bearer '.$widgetToken])
            ->post('/widget/v1/scan', [
                'passport_image' => UploadedFile::fake()->image('cin.jpg'),
                'document_type' => 'cin',
            ])
            ->assertStatus(202);

        $this->assertSame('failed', $upload->json('data.status'));

        $status = $this->withHeaders(['Authorization' => 'Bearer '.$widgetToken])
            ->getJson('/widget/v1/scan/'.$upload->json('data.scan_id').'/status')
            ->assertOk();

        $this->assertSame('failed', $status->json('data.status'));
        $this->assertNotEmpty($status->json('data.error'));

        // Aucun appel Claude n'a eu lieu (clé absente) : rien à facturer.
        $this->assertSame(0, AiUsageEvent::count());
    }

    public function test_disabling_vision_entirely_also_fails_cleanly(): void
    {
        config(['ocr.widget_vision.enabled' => false]);

        $fixture = $this->setUpLinkedPartner();
        $widgetToken = $this->bootstrap($fixture, 'BK-SCAN-2');

        $upload = $this->withHeaders(['Authorization' => 'Bearer '.$widgetToken])
            ->post('/widget/v1/scan', ['passport_image' => UploadedFile::fake()->image('passport.jpg')])
            ->assertStatus(202);

        $this->assertSame('failed', $upload->json('data.status'));
    }

    public function test_document_type_is_optional_and_defaults_to_cin(): void
    {
        config(['ocr.widget_vision.enabled' => false]);

        $fixture = $this->setUpLinkedPartner();
        $widgetToken = $this->bootstrap($fixture, 'BK-SCAN-3');

        $this->withHeaders(['Authorization' => 'Bearer '.$widgetToken])
            ->post('/widget/v1/scan', ['passport_image' => UploadedFile::fake()->image('doc.jpg')])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'failed');
    }

    public function test_an_invalid_document_type_is_rejected(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $widgetToken = $this->bootstrap($fixture, 'BK-SCAN-4');

        $this->withHeaders(['Authorization' => 'Bearer '.$widgetToken])
            ->post('/widget/v1/scan', [
                'passport_image' => UploadedFile::fake()->image('doc.jpg'),
                'document_type' => 'driving_license',
            ])
            ->assertStatus(422);
    }
}
