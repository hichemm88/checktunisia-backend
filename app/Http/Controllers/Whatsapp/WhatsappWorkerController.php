<?php

namespace App\Http\Controllers\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\DocumentScan;
use App\Models\WhatsappSendLog;
use App\Models\WhatsappSessionState;
use App\Services\Whatsapp\WhatsappAlertService;
use App\Services\Whatsapp\WhatsappOutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MODULE PROVISOIRE — à retirer après homologation MI.
 * Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
 *
 * API interne consommée UNIQUEMENT par le service Node (whatsapp-service/),
 * authentifiée par secret partagé. Le worker reste « bête » : il réclame un
 * job, récupère la photo, envoie, rend le résultat. Toute la logique métier
 * (backoff, journal, alertes) vit dans WhatsappOutboxService.
 */
class WhatsappWorkerController extends Controller
{
    public function __construct(
        private WhatsappOutboxService $outbox,
        private WhatsappAlertService $alerts,
    ) {}

    /** GET internal/whatsapp/next — réclame le prochain envoi (FIFO, un à la fois). */
    public function next(): JsonResponse
    {
        $job = $this->outbox->claimNextJob();

        if (! $job) {
            return response()->json(['data' => ['job' => null]]);
        }

        // La photo n'est proposée au worker que si le fichier existe encore :
        // un scan purgé ou perdu (disque éphémère redéployé) ne doit jamais
        // bloquer la fiche en boucle de 404 — elle part alors sans photo.
        $hasPhoto = false;
        if ($job->scan_id) {
            $scan = DocumentScan::find($job->scan_id);
            $disk = config('filesystems.passport_scan_disk', 'local');
            $hasPhoto = $scan !== null && Storage::disk($disk)->exists($scan->file_path);
        }

        return response()->json(['data' => ['job' => [
            'id' => $job->id,
            'recipient' => $job->recipient,
            'caption' => $job->caption,
            'has_photo' => $hasPhoto,
            'photo_url' => $hasPhoto
                ? url("/api/v1/internal/whatsapp/scan/{$job->scan_id}")
                : null,
        ]]]);
    }

    /** GET internal/whatsapp/scan/{scanId} — flux binaire de la photo (jamais dupliquée). */
    public function scan(string $scanId): StreamedResponse|JsonResponse
    {
        $scan = DocumentScan::find($scanId);
        $disk = config('filesystems.passport_scan_disk', 'local');

        if (! $scan || ! Storage::disk($disk)->exists($scan->file_path)) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'RESOURCE_NOT_FOUND', 'message' => 'Scan not found.', 'field' => null]],
            ], 404);
        }

        return Storage::disk($disk)->response(
            $scan->file_path,
            'document',
            ['Content-Type' => $scan->mime_type ?: 'application/octet-stream'],
        );
    }

    /** POST internal/whatsapp/jobs/{id}/result — le worker rend le verdict d'envoi. */
    public function result(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:sent,failed',
            'message_id' => 'nullable|string',
            'error' => 'nullable|string',
        ]);

        $job = WhatsappSendLog::find($id);
        if (! $job) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'RESOURCE_NOT_FOUND', 'message' => 'Job not found.', 'field' => null]],
            ], 404);
        }

        if ($data['status'] === 'sent') {
            $this->outbox->markSent($job, $data['message_id'] ?? null);
        } else {
            $this->outbox->markFailed($job, $data['error'] ?? null);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * POST internal/whatsapp/session — le worker rapporte ready/disconnected/
     * auth_failure. Déclenche l'alerte admin sur perte de session.
     */
    public function session(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:initializing,ready,disconnected,auth_failure',
            'reason' => 'nullable|string',
        ]);

        $state = WhatsappSessionState::current();
        $previous = $state->status;

        $state->status = $data['status'];
        $state->reason = $data['reason'] ?? null;
        $state->heartbeat_at = now();
        if ($data['status'] === WhatsappSessionState::STATUS_READY) {
            $state->last_ready_at = now();
        }
        $state->save();

        // Alerte uniquement à la TRANSITION vers un état « tombé » (pas de spam).
        $down = [WhatsappSessionState::STATUS_DISCONNECTED, WhatsappSessionState::STATUS_AUTH_FAILURE];
        if (in_array($data['status'], $down, true) && $previous !== $data['status']) {
            $this->alerts->sessionDown($data['status'], $data['reason'] ?? null);
        }

        return response()->json(['data' => [
            'paused' => $state->paused,
            'enabled' => $this->outbox->enabled(),
        ]]);
    }

    /** GET internal/whatsapp/control — le worker interroge pause/enabled/cadence. */
    public function control(): JsonResponse
    {
        $state = WhatsappSessionState::current();

        // Simple heartbeat : le worker interroge control() régulièrement.
        $state->forceFill(['heartbeat_at' => now()])->save();

        return response()->json(['data' => [
            'enabled' => $this->outbox->enabled(),
            'paused' => $state->paused,
            'min_interval_seconds' => (int) config('whatsapp.min_interval_seconds', 3),
        ]]);
    }
}
