<?php

namespace App\Http\Controllers\Prospection;

use App\Http\Controllers\Controller;
use App\Models\Prospection\MessageTemplate;
use Illuminate\Http\JsonResponse;

/**
 * Lecture seule ici (nécessaire au bouton WhatsApp, § Écran 4). Le CRUD
 * complet (§ Écran 5) arrive avec l'écran Templates.
 */
class MessageTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => MessageTemplate::where('active', true)->orderBy('name')->get(),
        ]);
    }
}
