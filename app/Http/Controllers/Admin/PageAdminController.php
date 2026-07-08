<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePageRequest;
use App\Http\Requests\Admin\UpdatePageRequest;
use App\Models\Page;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pages CMS (contenu Puck par langue) — CRUD platform_admin. */
class PageAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $pages = Page::orderBy('slug')->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => collect($pages->items())->map(fn(Page $p) => [
                'id'         => $p->id,
                'slug'       => $p->slug,
                'status'     => $p->status,
                // Indique quelles langues ont du contenu, sans embarquer les JSON complets.
                'languages'  => collect(['fr', 'en', 'ar'])->filter(fn($l) => !empty($p->content[$l]))->values(),
                'updated_at' => $p->updated_at,
            ]),
            'meta' => ['total' => $pages->total(), 'current_page' => $pages->currentPage(), 'per_page' => $pages->perPage()],
        ]);
    }

    public function store(StorePageRequest $request): JsonResponse
    {
        $v = $request->validated();
        $page = Page::create([
            'slug'    => $v['slug'],
            'status'  => $v['status'] ?? 'draft',
            'content' => $v['content'] ?? null,
            'meta'    => $v['meta'] ?? null,
        ]);

        AuditLogger::log('page.created', $page, [], ['slug' => $page->slug]);

        return response()->json(['data' => $page], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => Page::findOrFail($id)]);
    }

    public function update(UpdatePageRequest $request, string $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        $old  = ['slug' => $page->slug, 'status' => $page->status];

        $page->update($request->validated());
        $fresh = $page->fresh();

        $action = ($old['status'] !== 'published' && $fresh->status === 'published')
            ? 'page.published'
            : 'page.updated';
        AuditLogger::log($action, $page, $old, ['slug' => $fresh->slug, 'status' => $fresh->status]);

        return response()->json(['data' => $fresh]);
    }

    public function destroy(string $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        AuditLogger::log('page.deleted', $page, ['slug' => $page->slug], []);
        $page->delete(); // FK menu_items.page_id → null

        return response()->json(null, 204);
    }
}
