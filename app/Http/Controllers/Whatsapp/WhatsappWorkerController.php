<?php

namespace App\Http\Controllers\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\DocumentScan;
use App\Models\WhatsappSendLog;
use App\Models\WhatsappSessionState;
use App\Services\Whatsapp\WhatsappAlertService;
use App\Services\Whatsapp\WhatsappOutboxService;
use App\Services\Whatsapp\WhatsappSessionVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
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
        private WhatsappSessionVault $vault,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Coffre de session
    |--------------------------------------------------------------------------
    |
    | La session WhatsApp Web ne vivait que sur le volume Railway du worker :
    | un exemplaire unique, non sauvegardé, d'un secret qu'on ne peut
    | reconstituer qu'en re-scannant un QR sur place. Ces trois routes lui
    | donnent une copie chiffrée dans le stockage objet des sauvegardes.
    |
    | Le contenu de l'archive ne transite QUE dans ces réponses binaires : il
    | n'est jamais journalisé, ni inclus dans un message d'erreur.
    */

    /** GET internal/whatsapp/session-archive/meta — taille et date, jamais le contenu. */
    public function sessionArchiveMeta(): JsonResponse
    {
        return response()->json(['data' => [
            'configured' => $this->vault->isConfigured(),
        ] + $this->vault->metadata()]);
    }

    /**
     * GET internal/whatsapp/session-archive — restitue l'archive de session.
     *
     * 404 quand le coffre est vide : c'est le cas normal du tout premier
     * démarrage, le worker enchaîne alors sur l'appairage par QR.
     */
    public function sessionArchive(): StreamedResponse|JsonResponse
    {
        if (! $this->vault->isConfigured()) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'VAULT_NOT_CONFIGURED', 'message' => 'Session vault not configured.', 'field' => null]],
            ], 503);
        }

        try {
            $path = $this->vault->retrieve();
        } catch (\Throwable $e) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'VAULT_UNREADABLE', 'message' => 'Stored session is unreadable.', 'field' => null]],
            ], 500);
        }

        if ($path === null) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'RESOURCE_NOT_FOUND', 'message' => 'No stored session.', 'field' => null]],
            ], 404);
        }

        // Flux + suppression immédiate : la copie EN CLAIR de la session ne
        // doit pas rester sur le disque du conteneur une seconde de plus que
        // le transfert, ni y tenir en mémoire d'un seul bloc.
        return response()->streamDownload(function () use ($path) {
            $handle = fopen($path, 'rb');

            try {
                while (! feof($handle)) {
                    echo fread($handle, 1048576);
                }
            } finally {
                fclose($handle);
                @unlink($path);
            }
        }, 'session.tar.gz', [
            'Content-Type' => 'application/gzip',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * POST internal/whatsapp/session-archive — le worker dépose sa session.
     *
     * Un refus (archive trop petite, empreinte non conforme) laisse la session
     * déjà en coffre STRICTEMENT intacte : c'est la propriété qui compte, un
     * worker démarré sans volume ne doit jamais pouvoir effacer les credentials.
     */
    public function storeSessionArchive(Request $request): JsonResponse
    {
        $maxKilobytes = (int) ceil(((int) config('whatsapp.session_vault.max_bytes', 64 * 1024 * 1024)) / 1024);

        $request->validate([
            'archive' => "required|file|max:{$maxKilobytes}",
            'sha256' => 'nullable|string|size:64',
        ]);

        try {
            $result = $this->vault->store(
                $request->file('archive')->getRealPath(),
                $request->input('sha256'),
            );
        } catch (\Throwable $e) {
            // Le message de refus porte des tailles, jamais de contenu.
            \Log::warning('[wa-session] dépôt refusé : '.$e->getMessage());

            return response()->json([
                'data' => null,
                'errors' => [['code' => 'VAULT_REJECTED', 'message' => $e->getMessage(), 'field' => 'archive']],
            ], 422);
        }

        return response()->json(['data' => $result]);
    }

    /** GET internal/whatsapp/next — réclame le prochain envoi (FIFO, un à la fois). */
    public function next(): JsonResponse
    {
        $job = $this->outbox->claimNextJob();

        if (! $job) {
            return response()->json(['data' => ['job' => null]]);
        }

        // La photo n'est proposée au worker que si elle est encore disponible :
        // copie compressée en base (survit aux redéploiements — disque Railway
        // éphémère) OU fichier disque encore présent. Un scan purgé ne doit
        // jamais bloquer la fiche en boucle de 404 — elle part alors sans photo.
        $hasPhoto = false;
        if ($job->scan_id) {
            $scan = DocumentScan::query()
                ->select('id', 'file_path')
                ->selectRaw('(image_data IS NOT NULL) as has_image_data')
                ->find($job->scan_id);
            $disk = config('filesystems.passport_scan_disk', 'local');
            $hasPhoto = $scan !== null
                && ($scan->has_image_data || Storage::disk($disk)->exists($scan->file_path));
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
    public function scan(string $scanId): Response|JsonResponse
    {
        $scan = DocumentScan::find($scanId);
        $disk = config('filesystems.passport_scan_disk', 'local');

        // Source privilégiée : la copie compressée en base (déjà en 1600 px JPEG,
        // survit aux redéploiements — disque Railway éphémère). Repli : le
        // fichier disque, s'il existe encore.
        $binary = $scan?->imageBytes();
        $mime = 'image/jpeg';

        if ($binary === null) {
            if (! $scan || ! Storage::disk($disk)->exists($scan->file_path)) {
                return response()->json([
                    'data' => null,
                    'errors' => [['code' => 'RESOURCE_NOT_FOUND', 'message' => 'Scan not found.', 'field' => null]],
                ], 404);
            }
            $binary = Storage::disk($disk)->get($scan->file_path);
            $mime = $scan->mime_type ?: 'application/octet-stream';
        }

        // Compression à la volée (filet de sécurité) : les photos brutes de
        // téléphone (5-12 Mo) font planter l'envoi média côté whatsapp-web.js
        // (« Runtime.callFunctionOn timed out » — le base64 traverse le protocole
        // Chromium). 1600 px / JPEG reste parfaitement lisible pour une pièce
        // d'identité. En cas d'échec (format exotique), on sert l'original.
        try {
            $image = ImageManager::gd()->read($binary);
            if (max($image->width(), $image->height()) > 1600 || strlen($binary) > 1_000_000) {
                $image->scaleDown(1600, 1600);
                $binary = (string) $image->toJpeg(80);
                $mime = 'image/jpeg';
            }
        } catch (\Throwable $e) {
            \Log::warning('[whatsapp] compression scan '.$scan->id.' impossible, envoi original : '.$e->getMessage());
        }

        return response($binary, 200, ['Content-Type' => $mime]);
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
            // Exposé pour le self-test média du worker (/selftest-media) — évite
            // de faire transiter le numéro en clair dans une URL.
            'recipient' => (string) config('whatsapp.recipient'),
            // État connu du backend : le worker le compare au sien et resynchronise
            // s'il diverge. Un POST session « ready » perdu (ex. backend en plein
            // redéploiement) laissait sinon le backend en « initializing » pour
            // toujours → canDispatch() false → file gelée en silence.
            'session_status' => $state->status,
        ]]);
    }
}
