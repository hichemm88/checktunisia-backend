<?php

namespace App\Services\Watchlist;

use App\Models\WatchlistEntry;
use Illuminate\Support\Facades\Log;

/**
 * Syncs the watchlist with public OpenSanctions datasets (Interpol Red Notices + UN SC Sanctions).
 *
 * Data source: https://www.opensanctions.org
 * Format: NDJSON (FollowTheMoney) — one JSON entity per line.
 * Update frequency: OpenSanctions refreshes daily; we call this service once per day via scheduler.
 *
 * Strategy:
 *  1. Stream each dataset URL line-by-line (avoids loading gigabytes into memory).
 *  2. Upsert on `external_id` — insert new persons, update existing ones.
 *  3. After sync, mark as "inactive" any entries from this dataset whose
 *     `import_batch_id` doesn't match the current batch (= removed from the list).
 */
class OpenSanctionsService
{
    /**
     * Datasets to sync, in priority order.
     * Keys are used as a prefix in import_batch_id for lifecycle management.
     */
    private const DATASETS = [
        'interpol_red_notices' => [
            'url'      => 'https://data.opensanctions.org/datasets/latest/interpol_red_notices/entities.ftm.json',
            'severity' => 'critique',
            'code'     => 'MANDAT_ARRET',
            'reason'   => 'Interpol Red Notice — personne recherchée internationalement',
        ],
        'un_sc_sanctions' => [
            'url'      => 'https://data.opensanctions.org/datasets/latest/un_sc_sanctions/entities.ftm.json',
            'severity' => 'eleve',
            'code'     => 'AUTRE',
            'reason'   => 'Sanctions Conseil de Sécurité des Nations Unies',
        ],
    ];

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Run a full sync of all configured datasets.
     *
     * @return array{inserted:int, updated:int, deactivated:int, errors:int}
     */
    public function sync(): array
    {
        $totals = ['inserted' => 0, 'updated' => 0, 'deactivated' => 0, 'errors' => 0];

        foreach (self::DATASETS as $name => $config) {
            try {
                $stats = $this->syncDataset($name, $config);
                foreach ($stats as $key => $val) {
                    $totals[$key] += $val;
                }
                Log::info(sprintf(
                    'OpenSanctions [%s]: %d inserted, %d updated, %d deactivated',
                    $name, $stats['inserted'], $stats['updated'], $stats['deactivated']
                ));
            } catch (\Throwable $e) {
                $totals['errors']++;
                Log::error("OpenSanctions sync [{$name}] failed: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        }

        return $totals;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function syncDataset(string $name, array $config): array
    {
        $batchId    = 'os-' . $name . '-' . now()->format('Ymd');
        $inserted   = 0;
        $updated    = 0;

        $handle = $this->openStream($config['url']);

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                $entity = json_decode($line, true);
                if (!$entity || ($entity['schema'] ?? '') !== 'Person') {
                    continue;
                }

                $person = $this->extractPerson($entity);
                if (!$person) {
                    continue;
                }

                $externalId = $person['external_id'];
                $exists     = WatchlistEntry::where('external_id', $externalId)->exists();

                WatchlistEntry::updateOrCreate(
                    ['external_id' => $externalId],
                    [
                        'organization_id'  => null,
                        'added_by'         => null,
                        'first_name'       => $person['first_name'],
                        'last_name'        => $person['last_name'],
                        'date_of_birth'    => $person['date_of_birth'],
                        'nationality_code' => $person['nationality_code'],
                        'document_number'  => $person['document_number'],
                        'document_type'    => $person['document_number'] ? 'passport' : null,
                        'severity'         => $config['severity'],
                        'reason_code'      => $config['code'],
                        'reason'           => $config['reason'],
                        'status'           => 'active',
                        'source'           => 'opensanctions',
                        'import_batch_id'  => $batchId,
                        'expires_at'       => null,
                    ]
                );

                $exists ? $updated++ : $inserted++;
            }
        } finally {
            fclose($handle);
        }

        // Deactivate entries from this dataset that were NOT seen in this sync batch
        // (= they've been removed from the OpenSanctions list).
        $deactivated = WatchlistEntry::where('source', 'opensanctions')
            ->where('import_batch_id', 'like', 'os-' . $name . '-%')
            ->where('import_batch_id', '!=', $batchId)
            ->where('status', 'active')
            ->update(['status' => 'inactive']);

        return compact('inserted', 'updated', 'deactivated');
    }

    /**
     * Open a streaming HTTP connection.
     * Returns a resource handle (fgets-compatible).
     */
    private function openStream(string $url)
    {
        $context = stream_context_create([
            'http' => [
                'timeout'    => 120,
                'user_agent' => 'QayedTN/1.0 (hotel watchlist sync; contact: support@qayed.tn)',
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $handle = @fopen($url, 'r', false, $context);

        if ($handle === false) {
            throw new \RuntimeException("Cannot open stream: {$url}");
        }

        return $handle;
    }

    /**
     * Extract a structured person record from a FollowTheMoney entity.
     * Returns null if the entity lacks enough data to be useful for matching.
     */
    private function extractPerson(array $entity): ?array
    {
        $props = $entity['properties'] ?? [];

        // ── Name ──────────────────────────────────────────────────────────────
        $firstName = '';
        $lastName  = '';

        $names = array_merge($props['name'] ?? [], $props['lastName'] ?? []);

        if (!empty($props['firstName']) && !empty($props['lastName'])) {
            // FtM sometimes provides split fields
            $firstName = ucwords(strtolower(implode(' ', $props['firstName'])));
            $lastName  = strtoupper(implode(' ', $props['lastName']));
        } elseif (!empty($names)) {
            $full = $names[0];
            if (str_contains($full, ',')) {
                // "LASTNAME, Firstname" format common in Interpol data
                [$last, $first] = array_map('trim', explode(',', $full, 2));
                $lastName  = strtoupper($last);
                $firstName = ucwords(strtolower($first));
            } else {
                $parts     = explode(' ', trim($full));
                $lastName  = strtoupper(array_pop($parts));
                $firstName = ucwords(strtolower(implode(' ', $parts)));
            }
        }

        // Must have a last name — otherwise not matchable
        if (empty($lastName)) {
            return null;
        }

        // ── Date of birth ─────────────────────────────────────────────────────
        $dob = null;
        foreach ($props['birthDate'] ?? [] as $bd) {
            $bd = trim($bd);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd)) {
                $dob = $bd;
                break;
            }
            if (preg_match('/^\d{4}$/', $bd)) {
                $dob = $bd . '-01-01'; // year-only birth date
                break;
            }
        }

        // ── Nationality (prefer ISO 3166-1 alpha-3) ───────────────────────────
        $nationality = null;
        foreach ($props['nationality'] ?? [] as $nat) {
            $nat = strtoupper(trim($nat));
            if (strlen($nat) === 3) {
                $nationality = $nat;
                break;
            }
        }

        // ── Document number (passport preferred) ──────────────────────────────
        $docNumber = null;
        foreach (array_merge($props['passportNumber'] ?? [], $props['idNumber'] ?? []) as $doc) {
            $doc = trim($doc);
            if (!empty($doc)) {
                $docNumber = $doc;
                break;
            }
        }

        return [
            'external_id'      => $entity['id'],
            'first_name'       => $firstName,
            'last_name'        => $lastName,
            'date_of_birth'    => $dob,
            'nationality_code' => $nationality,
            'document_number'  => $docNumber,
        ];
    }
}
