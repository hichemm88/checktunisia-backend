<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MenuItemRequest;
use App\Models\MenuItem;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;

/** Menus publics (navbar/footer) — CRUD platform_admin. */
class MenuItemAdminController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => MenuItem::with('page:id,slug,status')
                ->orderBy('location')->orderBy('sort_order')
                ->get(),
        ]);
    }

    public function store(MenuItemRequest $request): JsonResponse
    {
        $v = $request->validated();
        $item = MenuItem::create(array_merge($v, [
            'sort_order' => $v['sort_order'] ?? ((int) MenuItem::where('location', $v['location'])->max('sort_order') + 1),
        ]));

        AuditLogger::log('menu_item.created', $item, [], ['label' => $item->label['fr'] ?? '', 'location' => $item->location]);

        return response()->json(['data' => $item->load('page:id,slug,status')], 201);
    }

    public function update(MenuItemRequest $request, string $id): JsonResponse
    {
        $item = MenuItem::findOrFail($id);
        $item->update($request->validated());

        AuditLogger::log('menu_item.updated', $item);

        return response()->json(['data' => $item->fresh()->load('page:id,slug,status')]);
    }

    public function destroy(string $id): JsonResponse
    {
        $item = MenuItem::findOrFail($id);
        AuditLogger::log('menu_item.deleted', $item, ['label' => $item->label['fr'] ?? ''], []);
        $item->delete();

        return response()->json(null, 204);
    }
}
