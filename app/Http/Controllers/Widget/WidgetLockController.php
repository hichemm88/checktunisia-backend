<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\FicheSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Verrou de saisie du widget. La session est déjà résolue par
 * VerifyWidgetSessionToken, qui renvoie 409 session_taken_over si la saisie a
 * été reprise par un collègue — c'est ce que le widget guette.
 */
class WidgetLockController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['held' => true]);
    }

    /** Action réelle (saisie, navigation) : le middleware a déjà renouvelé le verrou. */
    public function touch(): JsonResponse
    {
        return response()->json(['held' => true]);
    }

    /** Widget fermé sans soumettre : la fiche se libère, les voyageurs saisis restent. */
    public function release(): Response
    {
        app(FicheSession::class)->releaseLock();

        return response()->noContent();
    }
}
