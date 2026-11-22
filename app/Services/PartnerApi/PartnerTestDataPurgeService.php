<?php

namespace App\Services\PartnerApi;

use App\Models\ApiKey;
use App\Models\ApiPartner;
use App\Models\CheckIn;
use App\Models\FicheSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Purge des données de TEST d'un partenaire API (clés qyd_test_…).
 *
 * Partagé par la commande `partner:purge-test-data` et l'action admin
 * POST /api/v1/admin/partners/{id}/purge-test-data : une seule règle de
 * sélection, un seul chemin de suppression.
 *
 * ── Ce qui est supprimé ─────────────────────────────────────────────────
 *  - toutes les fiche_sessions du partenaire créées avec une clé de test ;
 *  - les check-ins (brouillons, fiches, séjours) PUREMENT de test : créés
 *    par l'API en mode test pour ce partenaire (metadata.source=api,
 *    test_mode=true, partner_id=P) — supprimés DÉFINITIVEMENT (pas de soft
 *    delete) : un check-in soft-deleted garderait les voyageurs, leurs
 *    documents et les images, ce qu'interdit la minimisation INPDP ;
 *  - en cascade SQL : liens check_in_guests, document_scans (copie image en
 *    base comprise), registre de consommation (vide pour une fiche de test) ;
 *  - les fichiers image des scans sur le disque (le cascade SQL ne les
 *    touche pas) ;
 *  - les voyageurs rattachés UNIQUEMENT à ces check-ins (et, en cascade,
 *    leurs documents de voyage) — un voyageur présent sur un autre check-in,
 *    même supprimé logiquement, est conservé ;
 *  - les envois WhatsApp en file, notifications in-app et livraisons webhook
 *    de ces sessions/fiches.
 *  Les chambres sont libérées par construction : leur occupation est dérivée
 *  des séjours ouverts (voir PartnerStayService), le séjour disparaît.
 *
 * ── Ce qui n'est JAMAIS touché ──────────────────────────────────────────
 *  - une fiche créée avec une clé live ou saisie dans Qayed (test_mode absent
 *    ou faux) — y compris si une session de test l'a ensuite rouverte ;
 *  - un check-in « mixte » : créé en test mais visé aussi par une session
 *    live (ou d'un autre partenaire), qui l'a donc complété « pour de vrai » ;
 *  - un check-in qui porte la trace d'une déclaration réelle : consommation
 *    de quota ou envoi WhatsApp hors test ;
 *  - un check-in ayant déclenché une alerte watchlist : c'est une donnée de
 *    sécurité, à traiter à la main.
 *  Ces check-ins sont comptés dans `kept_check_ins` de l'aperçu.
 *  Le journal d'audit n'est pas réécrit (traçabilité).
 */
class PartnerTestDataPurgeService
{
    /** Clés des comptes, dans l'ordre d'affichage (aperçu, confirmation, audit). */
    public const COUNT_KEYS = [
        'sessions',
        'drafts',
        'fiches',
        'guests',
        'travel_documents',
        'scans',
        'whatsapp_jobs',
        'notifications',
        'webhook_deliveries',
        'rooms_released',
        'kept_check_ins',
    ];

    /** Nombre d'éléments supprimés (hors indicateurs chambres libérées / check-ins conservés). */
    public static function deletableTotal(array $counts): int
    {
        return array_sum(array_map('intval', array_diff_key(
            array_intersect_key($counts, array_flip(self::COUNT_KEYS)),
            array_flip(['rooms_released', 'kept_check_ins']),
        )));
    }

    /** @return array<string,int> */
    public function preview(ApiPartner $partner): array
    {
        return $this->plan($partner)['counts'];
    }

    /**
     * Jeton de confirmation : lie l'exécution aux comptes EXACTS montrés à
     * l'admin. Si des données de test sont apparues ou ont disparu entre
     * l'aperçu et la confirmation, le jeton ne correspond plus.
     */
    public function confirmationToken(ApiPartner $partner, array $counts): string
    {
        $payload = $partner->id.'|'.json_encode(array_map('intval', array_intersect_key($counts, array_flip(self::COUNT_KEYS))));

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    /**
     * @param  string|null  $expectedToken  jeton de l'aperçu confirmé ; revérifié SOUS
     *         VERROU, dans la transaction, pour qu'aucune donnée apparue entre la
     *         vérification et la suppression ne soit emportée sans avoir été montrée.
     * @return array<string,int> comptes effectivement supprimés
     *
     * @throws PurgeConfirmationMismatch
     */
    public function purge(ApiPartner $partner, ?User $actor = null, string $via = 'admin', ?string $expectedToken = null): array
    {
        $scanFiles = [];

        $counts = DB::transaction(function () use ($partner, $expectedToken, &$scanFiles) {
            $plan = $this->plan($partner, lock: true);

            if ($expectedToken !== null && ! hash_equals($this->confirmationToken($partner, $plan['counts']), $expectedToken)) {
                throw new PurgeConfirmationMismatch($plan['counts']);
            }
            $checkInIds = $plan['check_in_ids'];
            $sessionIds = $plan['session_ids'];

            $scanFiles = DB::table('document_scans')
                ->whereIn('check_in_id', $checkInIds)
                ->whereNotNull('file_path')
                ->get(['check_in_id', 'file_path'])
                ->all();

            // Webhooks : clé d'idempotence = session (fiche.submitted,
            // session.expired…) ou fiche (fiche.delivered…).
            DB::table('partner_webhook_deliveries')
                ->whereIn('endpoint_id', DB::table('partner_webhook_endpoints')->where('partner_id', $partner->id)->select('id'))
                ->whereIn('idempotency_key', $sessionIds->merge($checkInIds)->all())
                ->delete();

            DB::table('whatsapp_send_log')->whereIn('check_in_id', $checkInIds)->delete();
            DB::table('app_notifications')->whereIn('check_in_id', $checkInIds)->delete();

            DB::table('fiche_sessions')->whereIn('id', $sessionIds)->delete();

            // Définitif : cascade check_in_guests, document_scans, usage.
            DB::table('check_ins')->whereIn('id', $checkInIds)->delete();

            // Après la suppression des liens : un voyageur sans plus aucun
            // check-in (supprimé logiquement compris) n'appartenait qu'aux
            // fiches de test. Cascade travel_documents.
            DB::table('guests')
                ->whereIn('id', $plan['guest_ids'])
                ->whereNotExists(fn ($q) => $q->from('check_in_guests')->whereColumn('check_in_guests.guest_id', 'guests.id'))
                ->delete();

            return $plan['counts'];
        });

        // Les fichiers après le COMMIT : une transaction annulée ne doit pas
        // laisser des lignes pointant vers des images déjà effacées.
        $this->deleteScanFiles($scanFiles);

        AuditLogger::log('partner.test_data_purged', $partner, [], [
            'via' => $via,
            'counts' => $counts,
        ], $actor);

        return $counts;
    }

    /**
     * Sélection unique (aperçu et exécution).
     *
     * @return array{counts: array<string,int>, check_in_ids: Collection<int,string>, session_ids: Collection<int,string>, guest_ids: Collection<int,string>}
     */
    private function plan(ApiPartner $partner, bool $lock = false): array
    {
        $testKeyIds = ApiKey::where('partner_id', $partner->id)->where('mode', ApiKey::MODE_TEST)->pluck('id');

        $sessionQuery = FicheSession::where('partner_id', $partner->id)
            ->where(fn ($q) => $q->where('is_test', true)->orWhereIn('api_key_id', $testKeyIds));
        if ($lock) {
            $sessionQuery->lockForUpdate();
        }
        $sessions = $sessionQuery->get(['id', 'check_in_id']);
        $sessionIds = $sessions->pluck('id');

        // Candidats : check-ins visés par ces sessions + check-ins marqués
        // test de ce partenaire (dont la session aurait disparu).
        $candidateIds = $sessions->pluck('check_in_id')->filter()
            ->merge(
                CheckIn::withTrashed()
                    ->where('metadata->source', 'api')
                    ->where('metadata->partner_id', $partner->id)
                    ->where('metadata->test_mode', true)
                    ->pluck('id')
            )
            ->unique()->values();

        $checkIns = CheckIn::withTrashed()->whereIn('id', $candidateIds)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get(['id', 'room_id', 'status', 'metadata', 'deleted_at']);

        $purgeable = $checkIns->filter(fn (CheckIn $c) => $this->isPureTestCheckIn($c, $partner));
        $checkInIds = $purgeable->pluck('id')->values();

        // Gardes « déclaration réelle » / « mixte » évaluées en base, en lot.
        // Mixte = visé par une session qui n'est pas une session de test de CE
        // partenaire (clé live, ou autre partenaire sur le même booking).
        $blocked = collect()
            ->merge(FicheSession::whereIn('check_in_id', $checkInIds)->whereNotIn('id', $sessionIds)->pluck('check_in_id'))
            ->merge(DB::table('checkin_usage_events')->whereIn('check_in_id', $checkInIds)->pluck('check_in_id'))
            ->merge(DB::table('whatsapp_send_log')->whereIn('check_in_id', $checkInIds)->where('is_test', false)->pluck('check_in_id'))
            ->merge(DB::table('watchlist_hits')->whereIn('check_in_id', $checkInIds)->pluck('check_in_id'))
            ->unique();

        $checkInIds = $checkInIds->diff($blocked)->values();
        $purged = $purgeable->whereIn('id', $checkInIds);

        $guestIds = DB::table('check_in_guests')->whereIn('check_in_id', $checkInIds)->distinct()->pluck('guest_id');
        // Voyageur partagé = rattaché à au moins un check-in hors purge.
        $sharedGuestIds = DB::table('check_in_guests')
            ->whereIn('guest_id', $guestIds)
            ->whereNotIn('check_in_id', $checkInIds)
            ->distinct()
            ->pluck('guest_id')
            // Voyageur visé par une alerte watchlist (même sur un séjour dont
            // il a été retiré depuis) : donnée de sécurité, jamais purgée.
            ->merge(DB::table('watchlist_hits')->whereIn('guest_id', $guestIds)->pluck('guest_id'));
        $ownGuestIds = $guestIds->diff($sharedGuestIds)->values();

        $counts = [
            'sessions' => $sessionIds->count(),
            'drafts' => $purged->where('status', 'draft')->count(),
            'fiches' => $purged->where('status', '!=', 'draft')->count(),
            'guests' => $ownGuestIds->count(),
            'travel_documents' => DB::table('travel_documents')->whereIn('guest_id', $ownGuestIds)->count(),
            'scans' => DB::table('document_scans')->whereIn('check_in_id', $checkInIds)->count(),
            'whatsapp_jobs' => DB::table('whatsapp_send_log')->whereIn('check_in_id', $checkInIds)->count(),
            'notifications' => DB::table('app_notifications')->whereIn('check_in_id', $checkInIds)->count(),
            'webhook_deliveries' => DB::table('partner_webhook_deliveries')
                ->whereIn('endpoint_id', DB::table('partner_webhook_endpoints')->where('partner_id', $partner->id)->select('id'))
                ->whereIn('idempotency_key', $sessionIds->merge($checkInIds)->all())
                ->count(),
            'rooms_released' => $purged
                ->filter(fn (CheckIn $c) => $c->room_id !== null && $c->deleted_at === null && in_array($c->status, ['draft', 'active'], true))
                ->pluck('room_id')->unique()->count(),
            'kept_check_ins' => $checkIns->count() - $checkInIds->count(),
        ];

        return [
            'counts' => $counts,
            'check_in_ids' => $checkInIds,
            'session_ids' => $sessionIds,
            'guest_ids' => $ownGuestIds,
        ];
    }

    /** Créé par l'API, en mode test, pour CE partenaire — rien d'autre n'est purgeable. */
    private function isPureTestCheckIn(CheckIn $checkIn, ApiPartner $partner): bool
    {
        $meta = $checkIn->metadata ?? [];

        return ($meta['source'] ?? null) === 'api'
            && ($meta['test_mode'] ?? false) === true
            && ($meta['partner_id'] ?? null) === $partner->id;
    }

    /** @param  array<int,object{check_in_id:string,file_path:string}>  $files */
    private function deleteScanFiles(array $files): void
    {
        $disk = Storage::disk(config('filesystems.passport_scan_disk', 'local'));

        foreach ($files as $file) {
            try {
                if ($disk->exists($file->file_path)) {
                    $disk->delete($file->file_path);
                }
                // Dossier scans/{hotel}/{check_in} — propre à la fiche purgée.
                $dir = dirname($file->file_path);
                if (str_ends_with($dir, '/'.$file->check_in_id) && $disk->directoryExists($dir) && $disk->allFiles($dir) === []) {
                    $disk->deleteDirectory($dir);
                }
            } catch (\Throwable $e) {
                // Jamais de chemin ni de nom dans le journal (données personnelles).
                Log::warning('[partner-purge] suppression d\'un fichier de scan impossible : '.get_class($e));
            }
        }
    }
}
