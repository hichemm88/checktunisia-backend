<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

/**
 * Médias CMS (images des pages Puck) — upload platform_admin.
 * Les images sont redimensionnées (max 1920px de large) et ré-encodées en
 * WebP qualité 82 avant stockage en base ; voir la migration
 * create_cms_tables pour la justification du stockage Postgres.
 */
class MediaAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $media = Media::orderByDesc('created_at')->paginate($request->integer('per_page', 40));

        return response()->json([
            'data' => collect($media->items())->map(fn(Media $m) => [
                'id'         => $m->id,
                'filename'   => $m->filename,
                'mime'       => $m->mime,
                'size'       => $m->size,
                'url'        => $m->publicUrl(),
                'created_at' => $m->created_at,
            ]),
            'meta' => ['total' => $media->total(), 'current_page' => $media->currentPage(), 'per_page' => $media->perPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpeg,png,webp,gif', 'max:5120'], // 5 Mo avant compression
        ]);

        $upload  = $request->file('file');
        $manager = new ImageManager(new Driver());

        $image = $manager->read($upload->getRealPath())
            ->scaleDown(width: 1920);
        $encoded = $image->toWebp(quality: 82);

        $media = Media::create([
            'filename'   => pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME) . '.webp',
            'mime'       => 'image/webp',
            'size'       => strlen((string) $encoded),
            'data'       => (string) $encoded,
            'created_by' => $request->user()->id,
        ]);

        AuditLogger::log('media.uploaded', $media, [], ['filename' => $media->filename, 'size' => $media->size]);

        return response()->json(['data' => [
            'id'       => $media->id,
            'filename' => $media->filename,
            'mime'     => $media->mime,
            'size'     => $media->size,
            'url'      => $media->publicUrl(),
        ]], 201);
    }

    public function destroy(string $id): JsonResponse
    {
        $media = Media::findOrFail($id);
        AuditLogger::log('media.deleted', $media, ['filename' => $media->filename], []);
        $media->delete();

        return response()->json(null, 204);
    }
}
