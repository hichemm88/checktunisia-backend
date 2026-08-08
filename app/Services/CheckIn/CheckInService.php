<?php

namespace App\Services\CheckIn;

use App\Models\CheckIn;
use App\Models\CheckInGuest;
use App\Models\DocumentScan;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\PushNotificationService;
use App\Services\OCR\OcrService;
use App\Services\Watchlist\WatchlistService;
use App\Services\Whatsapp\WhatsappOutboxService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Intervention\Image\ImageManager;

class CheckInService
{
    /**
     * Renseigné par le dernier addGuest() : indique à l'appelant si le voyageur
     * a été rapproché d'une fiche existante, et quels champs d'identité
     * divergent de ce qui vient d'être saisi. Remonté tel quel dans la réponse
     * API pour que la réception voie qu'elle réutilise un dossier connu.
     *
     * @var array{matched_existing:bool,identity_mismatch:array<int,string>,possible_duplicates:array<int,array<string,mixed>>}
     */
    public array $lastGuestMatch = [
        'matched_existing' => false,
        'identity_mismatch' => [],
        'possible_duplicates' => [],
    ];

    public function __construct(private OcrService $ocrService) {}

    /**
     * Create a new draft check-in.
     */
    public function create(Hotel $hotel, User $creator, array $data): CheckIn
    {
        return DB::transaction(function () use ($hotel, $creator, $data) {
            $checkIn = CheckIn::create([
                'hotel_id' => $hotel->id,
                'room_id' => $data['room_id'] ?? null,
                'booking_reference' => $data['booking_reference'] ?? null,
                'booking_source' => $data['booking_source'] ?? 'direct',
                'check_in_date' => $data['check_in_date'],
                'expected_check_out_date' => $data['expected_check_out_date'],
                'adults_count' => $data['adults_count'] ?? 1,
                'children_count' => $data['children_count'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'created_by' => $creator->id,
            ]);

            AuditLogger::log(
                action: 'check_in.created',
                subject: $checkIn,
                newValues: $checkIn->toArray(),
                hotelId: $hotel->id,
            );

            return $checkIn->load(['room', 'guests']);
        });
    }

    /**
     * Add a guest to an existing check-in.
     */
    public function addGuest(CheckIn $checkIn, User $addedBy, array $data): Guest
    {
        $this->lastGuestMatch = ['matched_existing' => false, 'identity_mismatch' => [], 'possible_duplicates' => []];

        return DB::transaction(function () use ($checkIn, $addedBy, $data) {
            // Upsert guest: if same document exists, reuse the guest record
            $guest = $this->findOrCreateGuest($data);

            // Upsert travel document
            if (! empty($data['document'])) {
                $this->upsertDocument($guest, $data['document']);
            }

            // Link to check-in — invariant : une fiche avec ≥1 voyageur a toujours
            // exactement UN principal.
            //  • is_primary=true explicite → promotion (les autres sont rétrogradés).
            //  • sinon → principal par défaut s'il n'existe aucun AUTRE principal.
            // On exclut le voyageur courant du test (ré-enregistrement / édition) :
            // sans ça, `updateOrCreate` rétrogradait l'unique principal à false quand
            // la même fiche était ré-enregistrée (count ≥ 1), d'où des fiches « sans
            // voyageur principal » → « Sans nom ».
            $hasOtherPrimary = $checkIn->guests()
                ->wherePivot('is_primary', true)
                ->where('guests.id', '!=', $guest->id)
                ->exists();
            $isPrimary = ! empty($data['is_primary']) ? true : ! $hasOtherPrimary;

            CheckInGuest::updateOrCreate(
                ['check_in_id' => $checkIn->id, 'guest_id' => $guest->id],
                ['is_primary' => $isPrimary, 'added_by' => $addedBy->id, 'added_at' => now()],
            );

            // Un seul principal : si ce voyageur le devient, rétrograder les autres.
            if ($isPrimary) {
                CheckInGuest::where('check_in_id', $checkIn->id)
                    ->where('guest_id', '!=', $guest->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            // MODULE PROVISOIRE — relais WhatsApp : relie le scan de ce voyageur
            // (téléversé à l'étape scan, guest_id null) à son voyageur, pour que
            // chaque fiche parte avec LA bonne photo (support multi-voyageurs).
            if (! empty($data['scan_id'])) {
                DocumentScan::where('id', $data['scan_id'])
                    ->where('check_in_id', $checkIn->id)
                    ->whereNull('guest_id')
                    ->update([
                        'guest_id' => $guest->id,
                        'travel_document_id' => $guest->documents()->latest('created_at')->value('id'),
                    ]);
            }

            AuditLogger::log(
                action: 'guest.added',
                subject: $guest,
                newValues: [
                    'check_in_id' => $checkIn->id, 'guest_id' => $guest->id,
                    'first_name' => $guest->first_name, 'last_name' => $guest->last_name,
                ],
                hotelId: $checkIn->hotel_id,
            );

            // ── MODULE PROVISOIRE — à retirer après homologation MI. ──────────
            // Séjour DÉJÀ finalisé : enqueueForCheckIn() ne tourne qu'à la
            // finalisation, donc la fiche d'un voyageur ajouté après coup ne
            // partait jamais. On l'enfile ici, pour ce voyageur uniquement.
            // Sur un check-in encore en draft on ne fait rien : la finalisation
            // enfilera tout le monde (sinon doublon).
            if ($checkIn->status !== 'draft') {
                app(WhatsappOutboxService::class)->enqueueForGuest($checkIn, $guest);
            }

            return $guest->load(['documents']);
        });
    }

    /**
     * Update a guest's data (post-OCR correction).
     */
    public function updateGuest(CheckIn $checkIn, Guest $guest, array $data): Guest
    {
        return DB::transaction(function () use ($checkIn, $guest, $data) {
            $old = $guest->toArray();
            $guest->update(array_filter([
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'sex' => $data['sex'] ?? null,
                'nationality_code' => $data['nationality_code'] ?? null,
                'place_of_birth' => $data['place_of_birth'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
            ], fn ($v) => ! is_null($v)));

            if (! empty($data['document'])) {
                $doc = $guest->primaryDocument;
                if ($doc) {
                    $changes = array_filter($data['document'], fn ($v) => ! is_null($v));

                    // Même normalisation qu'à la création, sinon une correction
                    // manuelle ré-introduisait les formes non canoniques
                    // (« tn », « AB 123 ») que le rapprochement ne sait plus voir.
                    if (array_key_exists('document_number', $changes)) {
                        $changes['document_number'] = DocumentIdentity::normalizeNumber($changes['document_number']);
                    }
                    if (array_key_exists('issuing_country_code', $changes)) {
                        $changes['issuing_country_code'] = DocumentIdentity::normalizeCountry($changes['issuing_country_code']);
                    }

                    // Corriger un numéro vers celui d'un document DÉJÀ enregistré
                    // violait la contrainte d'unicité et remontait en erreur 500.
                    // On refuse explicitement, avec une cause lisible.
                    $target = array_merge(
                        ['type' => $doc->type, 'document_number' => $doc->document_number, 'issuing_country_code' => $doc->issuing_country_code],
                        array_intersect_key($changes, array_flip(['type', 'document_number', 'issuing_country_code'])),
                    );

                    $taken = TravelDocument::where('type', $target['type'])
                        ->where('document_number', $target['document_number'])
                        ->where('issuing_country_code', $target['issuing_country_code'])
                        ->whereKeyNot($doc->id)
                        ->exists();

                    if ($taken) {
                        throw new \DomainException('Ce numéro de document est déjà enregistré pour un autre voyageur.');
                    }

                    $doc->update($changes);
                }
            }

            AuditLogger::log('guest.updated', $guest, $old, $guest->fresh()->toArray(), hotelId: $checkIn->hotel_id);

            return $guest->load(['documents']);
        });
    }

    /**
     * Remove a guest from a check-in.
     */
    public function removeGuest(CheckIn $checkIn, Guest $guest): void
    {
        DB::transaction(function () use ($checkIn, $guest) {
            $link = CheckInGuest::where('check_in_id', $checkIn->id)
                ->where('guest_id', $guest->id)
                ->firstOrFail();

            if ($link->is_primary && $checkIn->guests()->count() === 1) {
                throw new \DomainException('Cannot remove the only primary guest.');
            }

            $wasPrimary = (bool) $link->is_primary;
            $link->delete();

            // Invariant « un principal » : si on vient de retirer le principal et
            // qu'il reste des voyageurs, promouvoir le plus ancien restant.
            if ($wasPrimary) {
                $next = CheckInGuest::where('check_in_id', $checkIn->id)
                    ->orderBy('added_at')
                    ->orderBy('id')
                    ->first();
                $next?->update(['is_primary' => true]);
            }

            AuditLogger::log('guest.removed', $guest, [
                'check_in_id' => $checkIn->id,
                'first_name' => $guest->first_name, 'last_name' => $guest->last_name,
            ], [], hotelId: $checkIn->hotel_id);
        });
    }

    /**
     * Complete (validate) a check-in — moves from draft → active.
     */
    public function complete(CheckIn $checkIn, User $completedBy): CheckIn
    {
        return DB::transaction(function () use ($checkIn, $completedBy) {
            // Le contrôle du statut DOIT être fait sous verrou, dans la même
            // transaction que l'écriture. Vérifié avant la transaction, deux
            // requêtes concurrentes (double clic, retry après timeout réseau,
            // deux réceptionnistes) voyaient toutes les deux « draft » et
            // finalisaient toutes les deux : double contrôle watchlist, double
            // notification, et surtout DEUX fiches de police envoyées.
            $checkIn = $this->lockFresh($checkIn);

            if (! $checkIn->isDraft()) {
                throw new \DomainException('Only draft check-ins can be completed.');
            }

            if ($checkIn->guests()->count() === 0) {
                throw new \DomainException('At least one guest is required before completing a check-in.');
            }

            $checkIn->update([
                'status' => 'active',
                'completed_by' => $completedBy->id,
                'completed_at' => now(),
            ]);

            AuditLogger::log('check_in.completed', $checkIn, ['status' => 'draft'], ['status' => 'active', 'reference' => $checkIn->reference], hotelId: $checkIn->hotel_id);

            // ── Consommation de quota : c'est ICI, et nulle part ailleurs ──
            // La finalisation est l'acte déclaratif facturable. Dans la même
            // transaction que le passage en « active » : si la finalisation
            // échoue, aucune consommation n'est laissée derrière. L'unicité
            // SQL sur check_in_id rend l'écriture rejouable sans risque.
            \App\Services\Subscription\CheckinUsageRecorder::recordSafely($checkIn);

            // ── Watchlist check: flag hotel if any guest is on a watchlist ──
            app(WatchlistService::class)->checkCheckIn($checkIn->load('guests.documents'));

            // ── Notify the property's managers (§6) — async, never blocks the check-in ──
            app(PushNotificationService::class)
                ->notifyCheckInEvent($checkIn, PushNotificationService::TYPE_CHECK_IN, $completedBy);

            // ── MODULE PROVISOIRE — à retirer après homologation MI. ──────────────
            // Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
            // Enfile la fiche de police + photo pour envoi WhatsApp au destinataire
            // unique. Uniquement de l'enfilage (inserts) : l'envoi réel est fait par
            // le worker Node. Entièrement gardé/avalé — un souci WhatsApp ne doit
            // jamais bloquer ni ralentir le check-in. Inerte si WHATSAPP_POLICE_ENABLED=false.
            app(WhatsappOutboxService::class)->enqueueForCheckIn($checkIn);

            return $checkIn->fresh()->load(['room', 'guests.documents', 'creator']);
        });
    }

    /**
     * Record actual check-out.
     */
    public function checkout(CheckIn $checkIn, string $checkOutDate, ?User $actor = null): CheckIn
    {
        return DB::transaction(function () use ($checkIn, $checkOutDate, $actor) {
            // Même verrou que complete() : sans lui, un double clic enregistrait
            // deux fois le départ et envoyait deux notifications « check-out » aux
            // managers. Un séjour déjà clôturé ou annulé n'a pas de départ à
            // enregistrer.
            $checkIn = $this->lockFresh($checkIn);

            if (! in_array($checkIn->status, ['draft', 'active'], true)) {
                throw new \DomainException('Only an open (draft or active) check-in can be checked out.');
            }

            $old = ['status' => $checkIn->status, 'actual_check_out_date' => null];
            $checkIn->update([
                'status' => 'completed',
                'actual_check_out_date' => $checkOutDate,
            ]);

            AuditLogger::log('check_in.checked_out', $checkIn, $old, $checkIn->fresh()->only(['status', 'actual_check_out_date', 'reference']), hotelId: $checkIn->hotel_id);

            app(PushNotificationService::class)
                ->notifyCheckInEvent($checkIn, PushNotificationService::TYPE_CHECK_OUT, $actor);

            return $checkIn->fresh();
        });
    }

    /**
     * Annule un départ enregistré par erreur : un séjour « Terminé » repasse en
     * « Actif » (le client est en réalité toujours présent). Réservé au manager
     * établissement (voir route). N'a AUCUN effet WhatsApp : les fiches de police
     * sont enfilées à la finalisation (draft → active), jamais au check-out —
     * revenir sur le départ ne réenfile donc rien.
     */
    public function revertCheckout(CheckIn $checkIn, ?User $actor = null): CheckIn
    {
        if ($checkIn->status !== 'completed') {
            throw new \DomainException('Only a completed (checked-out) check-in can be reverted to active.');
        }

        return DB::transaction(function () use ($checkIn) {
            // Re-contrôle SOUS VERROU : le test ci-dessus a pu être franchi par
            // deux requêtes simultanées.
            $checkIn = $this->lockFresh($checkIn);

            if ($checkIn->status !== 'completed') {
                throw new \DomainException('Only a completed (checked-out) check-in can be reverted to active.');
            }

            $old = ['status' => $checkIn->status, 'actual_check_out_date' => $checkIn->actual_check_out_date];

            $checkIn->update([
                'status' => 'active',
                'actual_check_out_date' => null,
            ]);

            AuditLogger::log(
                'check_in.checkout_reverted',
                $checkIn,
                $old,
                $checkIn->fresh()->only(['status', 'actual_check_out_date', 'reference']),
                hotelId: $checkIn->hotel_id,
            );

            return $checkIn->fresh();
        });
    }

    /**
     * Cancel a check-in.
     */
    public function cancel(CheckIn $checkIn, string $reason, ?User $actor = null): CheckIn
    {
        return DB::transaction(function () use ($checkIn, $reason, $actor) {
            // Annuler deux fois écrasait le motif initial et renotifiait les
            // managers ; annuler un séjour déjà clôturé effaçait un départ réel.
            $checkIn = $this->lockFresh($checkIn);

            if (! in_array($checkIn->status, ['draft', 'active'], true)) {
                throw new \DomainException('Only an open (draft or active) check-in can be cancelled.');
            }

            $checkIn->update([
                'status' => 'cancelled',
                'metadata' => array_merge($checkIn->metadata ?? [], ['cancel_reason' => $reason]),
            ]);

            AuditLogger::log('check_in.cancelled', $checkIn, newValues: ['reference' => $checkIn->reference, 'cancel_reason' => $reason], hotelId: $checkIn->hotel_id);

            // Transparence : l'annulation est horodatée dans le registre de
            // consommation. Elle ne rend PAS le check-in — la fiche a été
            // déclarée. Sans consommation posée (annulation d'un brouillon),
            // il n'y a rien à horodater.
            \App\Services\Subscription\CheckinUsageRecorder::markCancelled($checkIn);

            app(PushNotificationService::class)
                ->notifyCheckInEvent($checkIn, PushNotificationService::TYPE_FICHE_CANCELLED, $actor);

            return $checkIn->fresh();
        });
    }

    /**
     * Upload a passport scan and trigger OCR.
     */
    public function uploadScan(CheckIn $checkIn, User $uploader, UploadedFile $file): DocumentScan
    {
        $hash = hash_file('sha256', $file->getRealPath());

        // Idempotence du téléversement : un double clic sur « prendre la photo »,
        // ou un retry après un timeout réseau côté réception, renvoyait
        // exactement le même fichier et créait un second scan. La fiche de
        // police pouvait alors partir avec la copie orpheline (celle qui n'est
        // rattachée à aucun voyageur), et le quota de scans du pack était
        // décompté deux fois. Même octet + même séjour = même scan.
        $existing = DocumentScan::where('check_in_id', $checkIn->id)
            ->where('file_hash', $hash)
            ->latest('created_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        $path = $file->store("scans/{$checkIn->hotel_id}/{$checkIn->id}", config('filesystems.passport_scan_disk', 'local'));

        // MODULE PROVISOIRE — relais WhatsApp : copie compressée en base (base64).
        // Le disque Railway est éphémère (effacé à chaque redéploiement) : sans
        // cette copie, une fiche envoyée après un redéploiement partait sans
        // photo. 1600 px / JPEG q80 ≈ 300 Ko, purgé après 24 h. Best-effort :
        // un format illisible ne bloque jamais le scan.
        $imageData = null;
        try {
            $image = ImageManager::gd()->read($file->getRealPath());
            $image->scaleDown(1600, 1600);
            $imageData = base64_encode((string) $image->toJpeg(80));
        } catch (\Throwable $e) {
            \Log::warning('[whatsapp] compression du scan à l\'upload impossible : '.$e->getMessage());
        }

        $scan = DocumentScan::create([
            'check_in_id' => $checkIn->id,
            'file_path' => $path,
            'file_hash' => $hash,
            'file_size_bytes' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'ocr_status' => 'pending',
            'uploaded_by' => $uploader->id,
            'image_data' => $imageData,
        ]);

        AuditLogger::log('scan.uploaded', $scan, [], ['check_in_id' => $checkIn->id], hotelId: $checkIn->hotel_id);

        // Le scan est TOUJOURS traité : l'ancienne branche « sinon, dispatcher un
        // job » pointait vers un dispatch resté commenté, si bien qu'avec un
        // pilote OCR réel configuré la ligne restait « pending » indéfiniment —
        // un état d'attente qui ne se résolvait jamais et que rien ne signalait.
        // OcrService décide seul de ce qui est honnête selon le pilote et
        // l'environnement (extraction, échec explicite, ou lecture non effectuée).
        $this->ocrService->process($scan);

        return $scan->fresh();
    }

    // ─── Private helpers ──────────────────────────────────────────────

    /**
     * Relit le séjour SOUS VERROU exclusif (SELECT … FOR UPDATE), à appeler
     * depuis une transaction avant toute transition d'état.
     *
     * Tant que la garde de statut était évaluée hors transaction, deux requêtes
     * concurrentes la franchissaient toutes les deux : c'est la cause unique du
     * double check-in, du double check-out et des fiches de police en double.
     * Ici la seconde requête attend, relit « active » et part en DomainException
     * (422) — l'opération métier n'est exécutée qu'une fois.
     */
    private function lockFresh(CheckIn $checkIn): CheckIn
    {
        return CheckIn::whereKey($checkIn->getKey())->lockForUpdate()->firstOrFail();
    }

    private function findOrCreateGuest(array $data): Guest
    {
        // Identify a returning traveler by their travel document so the same person
        // is never created twice — their whole stay history (check_in_guests) then
        // hangs off the single reused Guest record.
        //
        // The match MUST key on (type, document_number, issuing_country_code) — the
        // exact same triple `upsertDocument()` uses. Matching on number+type alone
        // would wrongly merge two DIFFERENT people from two countries that happen to
        // share a document number.
        //
        // Le triplet est NORMALISÉ (cf. DocumentIdentity) : sans ça « TN »/« TUN »
        // ou « ab 123 »/« AB123 » désignaient deux documents, donc deux voyageurs,
        // pour une seule et même personne.
        if (! empty($data['document']['document_number'])) {
            $key = DocumentIdentity::key($data['document']);

            $doc = TravelDocument::where('type', $key['type'])
                ->where('document_number', $key['document_number'])
                ->where('issuing_country_code', $key['issuing_country_code'])
                ->first();

            // $doc->guest peut être null si l'enregistrement Guest a été supprimé
            // sans cascader sur travel_documents (orphelin). On ignore et on recrée.
            if ($doc && $doc->guest) {
                // Même document, identité saisie différente : on GARDE le dossier
                // existant (un document identifie une personne) mais on ne le fait
                // plus en silence — la réception doit savoir que la fiche partira
                // au nom déjà enregistré, et l'écart est tracé pour l'audit.
                $mismatch = DocumentIdentity::identityMismatch($doc->guest, $data);

                $this->lastGuestMatch['matched_existing'] = true;
                $this->lastGuestMatch['identity_mismatch'] = $mismatch;

                if ($mismatch !== []) {
                    AuditLogger::log(
                        action: 'guest.identity_mismatch',
                        subject: $doc->guest,
                        oldValues: [
                            'first_name' => $doc->guest->first_name,
                            'last_name' => $doc->guest->last_name,
                            'date_of_birth' => (string) $doc->guest->date_of_birth,
                        ],
                        newValues: [
                            'document_number' => $key['document_number'],
                            'diverging_fields' => $mismatch,
                            'submitted_first_name' => $data['first_name'] ?? null,
                            'submitted_last_name' => $data['last_name'] ?? null,
                        ],
                    );
                }

                return $doc->guest;
            }
        }

        // Nouveau voyageur : on signale (sans jamais bloquer) les fiches déjà en
        // base qui lui ressemblent fortement — même nom, prénom et date de
        // naissance. Cas typique : la même personne re-scannée avec un document
        // d'un autre type, ou saisie à la main après un scan raté.
        $this->lastGuestMatch['possible_duplicates'] = DocumentIdentity::similarGuests($data)
            ->map(fn (Guest $g) => [
                'id' => $g->id,
                'first_name' => $g->first_name,
                'last_name' => $g->last_name,
                'date_of_birth' => (string) $g->date_of_birth,
            ])
            ->all();

        return Guest::create([
            'first_name' => $data['first_name'],
            'last_name' => strtoupper($data['last_name']),
            'date_of_birth' => $data['date_of_birth'],
            'sex' => $data['sex'] ?? 'M',
            'nationality_code' => $data['nationality_code'],
            'country_of_birth' => $data['country_of_birth'] ?? null,
            'place_of_birth' => $data['place_of_birth'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
        ]);
    }

    private function upsertDocument(Guest $guest, array $docData): TravelDocument
    {
        $key = DocumentIdentity::key($docData);

        // Ne JAMAIS écraser une date déjà connue par un null : un second scan du
        // même passeport dont le MRZ ne rend pas l'expiration effaçait la valeur
        // saisie/lue au premier passage — la fiche de police repartait sans date.
        $optional = array_filter([
            'issue_date' => $docData['issue_date'] ?? null,
            'expiry_date' => $docData['expiry_date'] ?? null,
            'mrz_line1' => $docData['mrz_line1'] ?? null,
            'mrz_line2' => $docData['mrz_line2'] ?? null,
        ], fn ($v) => ! is_null($v) && $v !== '');

        return TravelDocument::updateOrCreate(
            $key,
            array_merge($optional, [
                'guest_id' => $guest->id,
                'is_verified' => true,
            ])
        );
    }
}
