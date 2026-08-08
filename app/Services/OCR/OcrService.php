<?php

namespace App\Services\OCR;

use App\Models\DocumentScan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OcrService
{
    public function __construct(private string $driver = 'mock') {}

    /**
     * Dispatch OCR processing for a scan.
     * Marks scan as processing, then runs (sync in mock mode).
     */
    public function process(DocumentScan $scan): array
    {
        // Le pilote « mock » fabrique une identité (MOCK / Test / TN<aléatoire>).
        // C'est utile en développement, mais c'est un FAUX SUCCÈS en production :
        // la ligne repartait marquée « completed » à 95 % de confiance, avec des
        // données inventées, à côté de la photo d'un vrai passeport. Toute
        // relecture ultérieure (export, réquisition, support) les aurait prises
        // pour une lecture réelle.
        //
        // Hors développement, l'absence de pilote OCR est déclarée pour ce
        // qu'elle est : la lecture n'a pas eu lieu. La saisie du voyageur passe
        // de toute façon par la lecture MRZ du navigateur et par /api/scan/cin —
        // ce chemin-ci ne sert qu'à conserver l'image.
        if ($this->driver === 'mock' && app()->environment('production')) {
            $scan->update([
                'ocr_status' => 'skipped',
                'ocr_raw_result' => null,
                'ocr_confidence' => null,
                'ocr_processed_at' => now(),
                'ocr_error' => 'Aucun pilote OCR serveur configuré — image conservée, aucune extraction effectuée.',
            ]);

            return ['status' => 'skipped'];
        }

        $scan->update(['ocr_status' => 'processing']);

        try {
            $result = match ($this->driver) {
                'mock'  => MrzParser::mockExtract($scan),
                default => $this->callExternalService($scan),
            };

            $scan->update([
                'ocr_status'      => $result['status'],
                'ocr_raw_result'  => $result['extracted'] ?? null,
                'ocr_confidence'  => $result['confidence'] ?? null,
                'ocr_processed_at' => now(),
            ]);

            return $result;

        } catch (\Throwable $e) {
            Log::error('OCR processing failed', ['scan_id' => $scan->id, 'error' => $e->getMessage()]);

            $scan->update([
                'ocr_status' => 'failed',
                'ocr_error'  => $e->getMessage(),
                'ocr_processed_at' => now(),
            ]);

            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    private function callExternalService(DocumentScan $scan): array
    {
        // Phase 2: Implement real OCR API call (Mindee, Google Vision, etc.)
        // $fileContent = Storage::disk(config('filesystems.passport_scan_disk'))->get($scan->file_path);
        // $response = Http::withToken(config('ocr.service_key'))
        //     ->attach('document', $fileContent, 'passport.jpg')
        //     ->post(config('ocr.service_url'));
        // return $response->json();

        throw new \RuntimeException('External OCR driver not configured.');
    }
}
