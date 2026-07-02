<?php

namespace App\Http\Controllers\Authority;

use App\Http\Controllers\Controller;
use App\Models\WatchlistEntry;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WatchlistController extends Controller
{
    // ─── List ─────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $profile = $this->authorityProfile($request);
        $query   = WatchlistEntry::with(['addedBy', 'organization']);

        // Ministry sees all; police sees their org only
        if ($profile['org_type'] !== 'ministry') {
            $query->where('organization_id', $profile['org_id']);
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            $query->where('status', 'active'); // default: show active only
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('last_name', 'ilike', "%$s%")
                    ->orWhere('first_name', 'ilike', "%$s%")
                    ->orWhere('document_number', 'ilike', "%$s%");
            });
        }

        $entries = $query->orderByRaw("CASE severity WHEN 'critique' THEN 1 WHEN 'eleve' THEN 2 ELSE 3 END")
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => $entries->map(fn ($e) => $this->format($e, $profile['org_type'])),
            'meta' => [
                'total'        => $entries->total(),
                'current_page' => $entries->currentPage(),
                'per_page'     => $entries->perPage(),
            ],
        ]);
    }

    // ─── Create (manual) ──────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $profile   = $this->authorityProfile($request);
        $validated = $request->validate([
            'document_number'  => ['nullable', 'string', 'max:100'],
            'document_type'    => ['nullable', 'in:passport,national_id,any'],
            'first_name'       => ['nullable', 'string', 'max:100'],
            'last_name'        => ['nullable', 'string', 'max:100'],
            'date_of_birth'    => ['nullable', 'date'],
            'nationality_code' => ['nullable', 'string', 'size:3'],
            'severity'         => ['required', 'in:critique,eleve,moyen'],
            'reason'           => ['nullable', 'string', 'max:1000'],
            'reason_code'      => ['required', 'in:MANDAT_ARRET,FRAUDE,MIGRATION,AUTRE'],
            'expires_at'       => ['nullable', 'date', 'after:now'],
        ]);

        if (empty($validated['document_number']) && empty($validated['last_name'])) {
            return response()->json([
                'errors' => [[
                    'code'    => 'VALIDATION_ERROR',
                    'message' => "Au moins un critère d'identification est requis (numéro de document ou nom).",
                    'field'   => null,
                ]],
            ], 422);
        }

        $entry = WatchlistEntry::create([
            ...$validated,
            'last_name'       => isset($validated['last_name']) ? strtoupper($validated['last_name']) : null,
            'organization_id' => $profile['org_id'],
            'added_by'        => $request->user()->id,
            'source'          => 'manual',
            'status'          => 'active',
        ]);

        AuditLogger::log('watchlist.entry_added', $entry, [], $entry->toArray());

        return response()->json(['data' => $this->format($entry->load(['addedBy', 'organization']), $profile['org_type'])], 201);
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function update(Request $request, string $id): JsonResponse
    {
        $profile = $this->authorityProfile($request);
        $entry   = $this->findEntry($id, $profile);

        $validated = $request->validate([
            'severity'    => ['sometimes', 'in:critique,eleve,moyen'],
            'reason'      => ['sometimes', 'nullable', 'string', 'max:1000'],
            'reason_code' => ['sometimes', 'in:MANDAT_ARRET,FRAUDE,MIGRATION,AUTRE'],
            'status'      => ['sometimes', 'in:active,inactive'],
            'expires_at'  => ['sometimes', 'nullable', 'date'],
        ]);

        $old = $entry->toArray();
        $entry->update($validated);
        AuditLogger::log('watchlist.entry_updated', $entry, $old, $entry->fresh()->toArray());

        return response()->json(['data' => $this->format($entry->load(['addedBy', 'organization']), $profile['org_type'])]);
    }

    // ─── Delete ───────────────────────────────────────────────────────────────

    public function destroy(string $id, Request $request): JsonResponse
    {
        $profile = $this->authorityProfile($request);
        $entry   = $this->findEntry($id, $profile);
        $entry->delete();
        AuditLogger::log('watchlist.entry_removed', $entry, $entry->toArray(), []);

        return response()->json(null, 204);
    }

    // ─── CSV Import ───────────────────────────────────────────────────────────

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $profile  = $this->authorityProfile($request);
        $batchId  = 'IMPORT-' . now()->format('YmdHis') . '-' . Str::random(6);
        $lines    = file($request->file('file')->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $headers  = array_map('trim', str_getcsv(array_shift($lines)));
        $created  = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($lines as $i => $line) {
            $row = array_map('trim', str_getcsv($line));
            if (count($row) !== count($headers)) {
                $skipped++;
                continue;
            }
            $data = array_combine($headers, $row);

            try {
                $docNumber = $data['document_number'] ?? '';
                $lastName  = strtoupper($data['last_name'] ?? '');

                if (!$docNumber && !$lastName) {
                    $skipped++;
                    continue;
                }

                // Skip exact duplicates for this org
                $exists = WatchlistEntry::where('organization_id', $profile['org_id'])
                    ->where(function ($q) use ($docNumber, $lastName, $data) {
                        if ($docNumber) {
                            $q->where('document_number', $docNumber);
                        } else {
                            $q->where('last_name', $lastName)
                                ->whereDate('date_of_birth', $data['date_of_birth'] ?? '');
                        }
                    })
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                $severity   = in_array($data['severity'] ?? '', ['critique', 'eleve', 'moyen']) ? $data['severity'] : 'moyen';
                $reasonCode = in_array($data['reason_code'] ?? '', ['MANDAT_ARRET', 'FRAUDE', 'MIGRATION', 'AUTRE'])
                    ? $data['reason_code'] : 'AUTRE';

                WatchlistEntry::create([
                    'organization_id'  => $profile['org_id'],
                    'added_by'         => $request->user()->id,
                    'document_number'  => $docNumber ?: null,
                    'document_type'    => $data['document_type'] ?? null,
                    'first_name'       => $data['first_name'] ?? null,
                    'last_name'        => $lastName ?: null,
                    'date_of_birth'    => $data['date_of_birth'] ?? null,
                    'nationality_code' => strtoupper($data['nationality_code'] ?? '') ?: null,
                    'severity'         => $severity,
                    'reason'           => $data['reason'] ?? null,
                    'reason_code'      => $reasonCode,
                    'source'           => 'import',
                    'import_batch_id'  => $batchId,
                    'status'           => 'active',
                ]);
                $created++;

            } catch (\Exception $e) {
                $errors[] = "Ligne " . ($i + 2) . ": " . $e->getMessage();
            }
        }

        AuditLogger::log('watchlist.import', null, [], [
            'batch_id' => $batchId,
            'created'  => $created,
            'skipped'  => $skipped,
        ]);

        return response()->json([
            'data' => [
                'created'  => $created,
                'skipped'  => $skipped,
                'errors'   => $errors,
                'batch_id' => $batchId,
            ],
        ]);
    }

    // ─── Template CSV ─────────────────────────────────────────────────────────

    public function template(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $headers = ['document_number', 'document_type', 'first_name', 'last_name', 'date_of_birth', 'nationality_code', 'severity', 'reason_code', 'reason'];
        $example = ['TN12345678', 'passport', 'Ahmed', 'BEN ALI', '1985-03-12', 'TUN', 'critique', 'MANDAT_ARRET', 'Mandat d\'arret actif'];

        return response()->streamDownload(function () use ($headers, $example) {
            $f = fopen('php://output', 'w');
            fputcsv($f, $headers);
            fputcsv($f, $example);
            fclose($f);
        }, 'watchlist_template.csv', ['Content-Type' => 'text/csv']);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function format(WatchlistEntry $e, string $orgType): array
    {
        return [
            'id'               => $e->id,
            'document_number'  => $e->document_number,
            'document_type'    => $e->document_type,
            'first_name'       => $e->first_name,
            'last_name'        => $e->last_name,
            'date_of_birth'    => $e->date_of_birth,
            'nationality_code' => $e->nationality_code,
            'severity'         => $e->severity,
            'reason_code'      => $e->reason_code,
            'reason'           => $orgType === 'ministry' ? $e->reason : null,
            'status'           => $e->status,
            'expires_at'       => $e->expires_at?->toDateString(),
            'source'           => $e->source,
            'added_at'         => $e->created_at?->toDateTimeString(),
            'added_by_name'    => trim(($e->addedBy?->first_name ?? '') . ' ' . ($e->addedBy?->last_name ?? '')),
            'organization_name'=> $e->organization?->name,
        ];
    }

    private function authorityProfile(Request $request): array
    {
        $profile = $request->user()->authorityProfile()->with('organization')->first();
        return [
            'org_type'    => $profile?->organization?->type,
            'org_id'      => $profile?->organization?->id,
            'governorate' => $profile?->organization?->governorate,
        ];
    }

    private function findEntry(string $id, array $profile): WatchlistEntry
    {
        $entry = WatchlistEntry::findOrFail($id);

        // Ministry can touch any entry; police can only touch their own org's entries
        if ($profile['org_type'] !== 'ministry' && $entry->organization_id !== $profile['org_id']) {
            abort(403, "Vous ne pouvez modifier que les entrées de votre organisation.");
        }

        return $entry;
    }
}
