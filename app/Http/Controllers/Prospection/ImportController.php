<?php

namespace App\Http\Controllers\Prospection;

use App\Http\Controllers\Controller;
use App\Services\Prospection\Import\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImportController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls']]);

        return response()->json(['data' => ImportService::preview($request->file('file'))]);
    }

    public function commit(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls'],
            'resolutions' => ['sometimes', 'array'],
        ]);

        $resolutions = [];
        foreach ((array) $request->input('resolutions', []) as $rowNumber => $resolution) {
            if (!in_array($resolution, ['create', 'merge', 'skip'], true)) {
                throw ValidationException::withMessages(['resolutions' => ["Décision inconnue pour la ligne {$rowNumber}."]]);
            }
            $resolutions[(int) $rowNumber] = $resolution;
        }

        $result = ImportService::commit($request->file('file'), $resolutions, $request->user()->id);

        return response()->json(['data' => $result]);
    }
}
