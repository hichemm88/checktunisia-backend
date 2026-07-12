<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Services\Notifications\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The notification centre (§5.8). Manager-scoped: a user only ever sees the rows addressed to
 * them (one row per recipient), which span all of their properties. The server is the source
 * of truth so no event is lost even if a push wasn't received (§6.4 / critère 6).
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId  = $request->user()->id;
        $perPage = min((int) $request->query('per_page', 20), 50);

        $query = AppNotification::with('hotel')->where('user_id', $userId);

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($property = $request->query('property')) {
            $query->where('hotel_id', $property);
        }

        $page = $query->latest()->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (AppNotification $n) => $this->resource($n)),
            'meta' => [
                'total'        => $page->total(),
                'current_page' => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'last_page'    => $page->lastPage(),
            ],
        ]);
    }

    /**
     * Manager → receptionists broadcast: send a free-text message to the receptionists of a
     * property (defaults to all the manager's properties). Manager-only (gated in routes).
     */
    public function broadcast(Request $request, PushNotificationService $push): JsonResponse
    {
        $validated = $request->validate([
            'message'        => ['required', 'string', 'max:500'],
            'property_id'    => ['nullable', 'uuid'],
            'recipient_ids'  => ['nullable', 'array'],
            'recipient_ids.*' => ['uuid'],
        ]);

        $sent = $push->notifyReceptionists(
            $request->user(),
            $validated['message'],
            $validated['property_id'] ?? null,
            $validated['recipient_ids'] ?? null,
        );

        return response()->json(['data' => ['sent' => $sent]], 201);
    }

    /**
     * List the manager's receptionists across all their properties for the broadcast recipient
     * picker (§9). Each receptionist appears once, tagged with every property they're assigned
     * to (their first property leads, ordered by the property's creation date).
     */
    public function recipients(Request $request): JsonResponse
    {
        $hotels = $request->user()->hotels()->orderBy('hotels.created_at')->get();

        $map = []; // user_id => ['user' => User, 'properties' => [['id','name'], ...]]
        foreach ($hotels as $hotel) {
            $receps = $hotel->users()
                ->whereHas('roles', fn ($q) => $q->where('name', 'receptionist'))
                ->get();

            foreach ($receps as $u) {
                if (!isset($map[$u->id])) {
                    $map[$u->id] = ['user' => $u, 'properties' => []];
                }
                $map[$u->id]['properties'][] = ['id' => $hotel->id, 'name' => $hotel->name];
            }
        }

        $data = array_values(array_map(fn ($e) => [
            'id'         => $e['user']->id,
            'first_name' => $e['user']->first_name,
            'last_name'  => $e['user']->last_name,
            'properties' => $e['properties'],
        ], $map));

        return response()->json(['data' => $data]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = AppNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['data' => ['count' => $count]]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = AppNotification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if (!$notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['data' => ['read_at' => $notification->read_at?->toIso8601String()]]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $updated = AppNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['data' => ['updated' => $updated]]);
    }

    /**
     * @return array<string,mixed>
     */
    private function resource(AppNotification $n): array
    {
        return [
            'id'            => $n->id,
            'type'          => $n->type,
            'title'         => $this->stripEmoji($n->title),
            'body'          => $this->stripEmoji($n->body),
            'property_id'   => $n->hotel_id,
            'property_name' => $n->hotel?->name,
            'check_in_id'   => $n->check_in_id,
            'actor_name'    => $n->data['actor_name'] ?? null,
            'created_at'    => $n->created_at?->toIso8601String(),
            'read_at'       => $n->read_at?->toIso8601String(),
        ];
    }

    /**
     * Strip leading emoji/pictographs from historical notification copy so pre-§6 rows render
     * cleanly without a data migration. New notifications are already emoji-free at creation.
     */
    private function stripEmoji(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $clean = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', '', $text);

        return trim($clean ?? $text);
    }
}
