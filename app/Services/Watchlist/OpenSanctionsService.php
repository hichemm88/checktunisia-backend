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
     * ISO 3166-1 alpha-2 → alpha-3 mapping.
     * OpenSanctions nationality codes are alpha-2; our DB stores alpha-3.
     */
    private const ALPHA2_TO_ALPHA3 = [
        'AF'=>'AFG','AX'=>'ALA','AL'=>'ALB','DZ'=>'DZA','AS'=>'ASM','AD'=>'AND',
        'AO'=>'AGO','AI'=>'AIA','AQ'=>'ATA','AG'=>'ATG','AR'=>'ARG','AM'=>'ARM',
        'AW'=>'ABW','AU'=>'AUS','AT'=>'AUT','AZ'=>'AZE','BS'=>'BHS','BH'=>'BHR',
        'BD'=>'BGD','BB'=>'BRB','BY'=>'BLR','BE'=>'BEL','BZ'=>'BLZ','BJ'=>'BEN',
        'BM'=>'BMU','BT'=>'BTN','BO'=>'BOL','BQ'=>'BES','BA'=>'BIH','BW'=>'BWA',
        'BV'=>'BVT','BR'=>'BRA','IO'=>'IOT','BN'=>'BRN','BG'=>'BGR','BF'=>'BFA',
        'BI'=>'BDI','CV'=>'CPV','KH'=>'KHM','CM'=>'CMR','CA'=>'CAN','KY'=>'CYM',
        'CF'=>'CAF','TD'=>'TCD','CL'=>'CHL','CN'=>'CHN','CX'=>'CXR','CC'=>'CCK',
        'CO'=>'COL','KM'=>'COM','CG'=>'COG','CD'=>'COD','CK'=>'COK','CR'=>'CRI',
        'CI'=>'CIV','HR'=>'HRV','CU'=>'CUB','CW'=>'CUW','CY'=>'CYP','CZ'=>'CZE',
        'DK'=>'DNK','DJ'=>'DJI','DM'=>'DMA','DO'=>'DOM','EC'=>'ECU','EG'=>'EGY',
        'SV'=>'SLV','GQ'=>'GNQ','ER'=>'ERI','EE'=>'EST','SZ'=>'SWZ','ET'=>'ETH',
        'FK'=>'FLK','FO'=>'FRO','FJ'=>'FJI','FI'=>'FIN','FR'=>'FRA','GF'=>'GUF',
        'PF'=>'PYF','TF'=>'ATF','GA'=>'GAB','GM'=>'GMB','GE'=>'GEO','DE'=>'DEU',
        'GH'=>'GHA','GI'=>'GIB','GR'=>'GRC','GL'=>'GRL','GD'=>'GRD','GP'=>'GLP',
        'GU'=>'GUM','GT'=>'GTM','GG'=>'GGY','GN'=>'GIN','GW'=>'GNB','GY'=>'GUY',
        'HT'=>'HTI','HM'=>'HMD','VA'=>'VAT','HN'=>'HND','HK'=>'HKG','HU'=>'HUN',
        'IS'=>'ISL','IN'=>'IND','ID'=>'IDN','IR'=>'IRN','IQ'=>'IRQ','IE'=>'IRL',
        'IM'=>'IMN','IL'=>'ISR','IT'=>'ITA','JM'=>'JAM','JP'=>'JPN','JE'=>'JEY',
        'JO'=>'JOR','KZ'=>'KAZ','KE'=>'KEN','KI'=>'KIR','KP'=>'PRK','KR'=>'KOR',
        'KW'=>'KWT','KG'=>'KGZ','LA'=>'LAO','LV'=>'LVA','LB'=>'LBN','LS'=>'LSO',
        'LR'=>'LBR','LY'=>'LBY','LI'=>'LIE','LT'=>'LTU','LU'=>'LUX','MO'=>'MAC',
        'MG'=>'MDG','MW'=>'MWI','MY'=>'MYS','MV'=>'MDV','ML'=>'MLI','MT'=>'MLT',
        'MH'=>'MHL','MQ'=>'MTQ','MR'=>'MRT','MU'=>'MUS','YT'=>'MYT','MX'=>'MEX',
        'FM'=>'FSM','MD'=>'MDA','MC'=>'MCO','MN'=>'MNG','ME'=>'MNE','MS'=>'MSR',
        'MA'=>'MAR','MZ'=>'MOZ','MM'=>'MMR','NA'=>'NAM','NR'=>'NRU','NP'=>'NPL',
        'NL'=>'NLD','NC'=>'NCL','NZ'=>'NZL','NI'=>'NIC','NE'=>'NER','NG'=>'NGA',
        'NU'=>'NIU','NF'=>'NFK','MK'=>'MKD','MP'=>'MNP','NO'=>'NOR','OM'=>'OMN',
        'PK'=>'PAK','PW'=>'PLW','PS'=>'PSE','PA'=>'PAN','PG'=>'PNG','PY'=>'PRY',
        'PE'=>'PER','PH'=>'PHL','PN'=>'PCN','PL'=>'POL','PT'=>'PRT','PR'=>'PRI',
        'QA'=>'QAT','RE'=>'REU','RO'=>'ROU','RU'=>'RUS','RW'=>'RWA','BL'=>'BLM',
        'SH'=>'SHN','KN'=>'KNA','LC'=>'LCA','MF'=>'MAF','PM'=>'SPM','VC'=>'VCT',
        'WS'=>'WSM','SM'=>'SMR','ST'=>'STP','SA'=>'SAU','SN'=>'SEN','RS'=>'SRB',
        'SC'=>'SYC','SL'=>'SLE','SG'=>'SGP','SX'=>'SXM','SK'=>'SVK','SI'=>'SVN',
        'SB'=>'SLB','SO'=>'SOM','ZA'=>'ZAF','GS'=>'SGS','SS'=>'SSD','ES'=>'ESP',
        'LK'=>'LKA','SD'=>'SDN','SR'=>'SUR','SJ'=>'SJM','SE'=>'SWE','CH'=>'CHE',
        'SY'=>'SYR','TW'=>'TWN','TJ'=>'TJK','TZ'=>'TZA','TH'=>'THA','TL'=>'TLS',
        'TG'=>'TGO','TK'=>'TKL','TO'=>'TON','TT'=>'TTO','TN'=>'TUN','TR'=>'TUR',
        'TM'=>'TKM','TC'=>'TCA','TV'=>'TUV','UG'=>'UGA','UA'=>'UKR','AE'=>'ARE',
        'GB'=>'GBR','US'=>'USA','UM'=>'UMI','UY'=>'URY','UZ'=>'UZB','VU'=>'VUT',
        'VE'=>'VEN','VN'=>'VNM','VG'=>'VGB','VI'=>'VIR','WF'=>'WLF','EH'=>'ESH',
        'YE'=>'YEM','ZM'=>'ZMB','ZW'=>'ZWE',
    ];

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

        // ── Nationality ───────────────────────────────────────────────────────
        // OpenSanctions uses ISO 3166-1 alpha-2; our DB stores alpha-3.
        $nationality = null;
        foreach ($props['nationality'] ?? [] as $nat) {
            $nat = strtoupper(trim($nat));
            if (strlen($nat) === 3) {
                $nationality = $nat; // already alpha-3
                break;
            }
            if (strlen($nat) === 2 && isset(self::ALPHA2_TO_ALPHA3[$nat])) {
                $nationality = self::ALPHA2_TO_ALPHA3[$nat];
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
