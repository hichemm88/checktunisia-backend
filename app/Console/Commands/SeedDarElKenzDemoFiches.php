<?php

namespace App\Console\Commands;

use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\TravelDocument;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seed de ~40 fiches de police FICTIVES pour l'établissement « Dar El Kenz »
 * (démo/test). AUCUN autre établissement n'est touché.
 *
 * Garde-fous :
 *  - L'établissement est résolu par son nom exact ; 0 ou >1 match → ABORT sans
 *    aucune écriture. Toutes les insertions passent par l'ID résolu.
 *  - Références fournies explicitement au format QYD-YYYYMMDD-Sxxx : le hook
 *    CheckIn::creating ne tourne pas, donc la table GLOBALE check_in_sequences
 *    (partagée entre établissements) n'est jamais incrémentée ; le suffixe
 *    « Sxxx » ne matche pas la regex ^QYD-[0-9]{8}-[0-9]{4}$ du compteur
 *    auto-réparant, il ne peut donc pas décaler la numérotation réelle.
 *  - Insertion directe via les modèles Eloquent, sans passer par
 *    CheckInService::complete()/checkout() : aucun screening watchlist, aucun
 *    push, aucun enfilage WhatsApp (ces effets ne vivent que dans le service —
 *    aucun observer n'existe sur les modèles).
 *  - Chaque ligne créée (check_in, guest, travel_document) porte le marqueur
 *    SEED-DEMO-2026 (notes + metadata.seed_batch) → cleanup ciblé via
 *    demo:cleanup-dar-el-kenz.
 *  - Dry-run par défaut (transaction annulée) ; écriture réelle avec --commit.
 *    Dans les deux cas, les counts de check_ins par établissement sont comparés
 *    AVANT/APRÈS à l'intérieur de la transaction : si un autre établissement a
 *    bougé, ou si check_in_sequences a bougé → rollback + erreur.
 */
class SeedDarElKenzDemoFiches extends Command
{
    protected $signature = 'demo:seed-dar-el-kenz {--commit : Écrit réellement en base (sinon dry-run)}';

    protected $description = 'Insère ~40 fiches de police fictives (marquées SEED-DEMO-2026) dans Dar El Kenz uniquement';

    private const HOTEL_NAME = 'Dar el Kenz';
    private const MARKER     = 'SEED-DEMO-2026';
    private const RNG_SEED   = 20260729;

    /**
     * Pools par nationalité : noms fictifs cohérents + format passeport
     * plausible (# = chiffre, L = lettre, autre caractère = littéral).
     */
    private const COUNTRIES = [
        'FRA' => ['m' => ['Julien', 'Antoine', 'Thomas'], 'f' => ['Camille', 'Élodie', 'Manon'], 'last' => ['Moreau', 'Garnier', 'Lefebvre'], 'fmt' => '##LL#####', 'city' => 'Lyon'],
        'ITA' => ['m' => ['Lorenzo', 'Matteo'], 'f' => ['Giulia', 'Francesca'], 'last' => ['Ricci', 'Marino', 'Greco'], 'fmt' => 'LL#######', 'city' => 'Torino'],
        'DEU' => ['m' => ['Lukas', 'Felix'], 'f' => ['Hannah', 'Lena'], 'last' => ['Hoffmann', 'Schneider'], 'fmt' => 'L########', 'city' => 'Hamburg'],
        'ESP' => ['m' => ['Álvaro', 'Diego'], 'f' => ['Lucía', 'Marta'], 'last' => ['Navarro', 'Iglesias'], 'fmt' => 'LLL######', 'city' => 'Sevilla'],
        'GBR' => ['m' => ['Oliver', 'Harry'], 'f' => ['Amelia', 'Sophie'], 'last' => ['Whitfield', 'Harrington'], 'fmt' => '#########', 'city' => 'Leeds'],
        'NLD' => ['m' => ['Daan', 'Sem'], 'f' => ['Fleur', 'Sanne'], 'last' => ['Vermeulen', 'De Vries'], 'fmt' => 'LL#######', 'city' => 'Utrecht'],
        'SWE' => ['m' => ['Erik', 'Oskar'], 'f' => ['Elsa', 'Freja'], 'last' => ['Lindqvist', 'Bergström'], 'fmt' => '########', 'city' => 'Göteborg'],
        'POL' => ['m' => ['Kacper', 'Szymon'], 'f' => ['Zuzanna', 'Maja'], 'last' => ['Wiśniewski', 'Zielińska'], 'fmt' => 'LL#######', 'city' => 'Kraków'],
        'USA' => ['m' => ['Ethan', 'Tyler'], 'f' => ['Madison', 'Chloe'], 'last' => ['Caldwell', 'Merritt'], 'fmt' => '#########', 'city' => 'Denver'],
        'CAN' => ['m' => ['Liam', 'Nathan'], 'f' => ['Emma', 'Olivia'], 'last' => ['Tremblay', 'Gagnon'], 'fmt' => 'LL######', 'city' => 'Montréal'],
        'BRA' => ['m' => ['Gabriel', 'Rafael'], 'f' => ['Beatriz', 'Larissa'], 'last' => ['Cardoso', 'Barbosa'], 'fmt' => 'LL######', 'city' => 'Curitiba'],
        'ARG' => ['m' => ['Mateo', 'Santiago'], 'f' => ['Valentina', 'Camila'], 'last' => ['Herrera', 'Quiroga'], 'fmt' => 'LLL######', 'city' => 'Córdoba'],
        'JPN' => ['m' => ['Haruto', 'Sota'], 'f' => ['Yui', 'Aoi'], 'last' => ['Takahashi', 'Kobayashi'], 'fmt' => 'LL#######', 'city' => 'Osaka'],
        'KOR' => ['m' => ['Min-jun', 'Ji-ho'], 'f' => ['Seo-yeon', 'Ha-eun'], 'last' => ['Park', 'Choi'], 'fmt' => 'M########', 'city' => 'Busan'],
        'CHN' => ['m' => ['Wei', 'Jun'], 'f' => ['Mei', 'Lan'], 'last' => ['Zhang', 'Chen'], 'fmt' => 'E########', 'city' => 'Chengdu'],
        'TUR' => ['m' => ['Emre', 'Mert'], 'f' => ['Zeynep', 'Elif'], 'last' => ['Yıldırım', 'Demir'], 'fmt' => 'U########', 'city' => 'İzmir'],
        'MAR' => ['m' => ['Yassine', 'Amine'], 'f' => ['Salma', 'Imane'], 'last' => ['El Amrani', 'Bennis'], 'fmt' => 'LL#######', 'city' => 'Rabat'],
        'DZA' => ['m' => ['Sofiane', 'Riad'], 'f' => ['Lina', 'Amel'], 'last' => ['Boudiaf', 'Hamidi'], 'fmt' => '#########', 'city' => 'Oran'],
        'ARE' => ['m' => ['Khalifa', 'Saeed'], 'f' => ['Mariam', 'Noora'], 'last' => ['Al Mansoori', 'Al Suwaidi'], 'fmt' => '#########', 'city' => 'Dubai'],
        'AUS' => ['m' => ['Jack', 'Noah'], 'f' => ['Ruby', 'Isla'], 'last' => ['Thornton', 'Callaghan'], 'fmt' => 'L#######', 'city' => 'Perth'],
        'TUN' => ['m' => ['Seifeddine', 'Wassim'], 'f' => ['Rania', 'Syrine'], 'last' => ['Trabelsi', 'Gharbi'], 'fmt' => 'L######', 'city' => 'Sfax'],
    ];

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');

        // ── Garde-fou 1 : résolution stricte de l'établissement ──────────
        $matches = Hotel::where('name', self::HOTEL_NAME)->get();
        if ($matches->count() !== 1) {
            $this->error(sprintf(
                'ABORT — établissement « %s » : %d correspondance(s) exacte(s) trouvée(s) (1 attendue). Aucune écriture effectuée.',
                self::HOTEL_NAME,
                $matches->count(),
            ));

            return self::FAILURE;
        }
        /** @var Hotel $hotel */
        $hotel = $matches->first();

        // created_by / added_by sont NOT NULL → il faut un utilisateur de
        // l'établissement. Préférence : hotel_admin, sinon receptionist,
        // sinon le premier utilisateur rattaché.
        $creator = $this->resolveCreator($hotel);
        if (! $creator) {
            $this->error(sprintf(
                'ABORT — aucun utilisateur rattaché à « %s » (requis pour created_by/added_by). Aucune écriture effectuée.',
                self::HOTEL_NAME,
            ));

            return self::FAILURE;
        }

        $rooms = $hotel->rooms()->orderBy('number')->get();

        // Le taux d'occupation du dashboard = séjours actifs ÷ room_count :
        // on plafonne les fiches actives à (chambres − 1) pour rester réaliste
        // ET laisser toujours au moins une chambre libre.
        $roomCount = $rooms->count() ?: (int) $hotel->room_count;
        $maxActive = $roomCount > 0 ? max(1, $roomCount - 1) : 3;

        // Jeu de données déterministe (même sortie à chaque exécution).
        mt_srand(self::RNG_SEED);
        $fiches = $this->buildFiches($maxActive);

        $this->info(sprintf(
            'Établissement : %s (%s) — créateur : %s — %d fiches / %d voyageurs à insérer [%s]',
            $hotel->name,
            $hotel->id,
            $creator->email,
            count($fiches),
            collect($fiches)->sum(fn ($f) => count($f['guests'])),
            $commit ? 'COMMIT' : 'DRY-RUN',
        ));

        // ── Counts AVANT (dans la transaction, pour comparaison fiable) ──
        DB::beginTransaction();

        try {
            $before = $this->snapshotCounts();

            $inserted = $this->insertFiches($hotel, $creator, $rooms, $fiches);

            $after = $this->snapshotCounts();

            // ── Garde-fou 3 : seuls les counts de Dar El Kenz ont bougé ──
            $this->assertOnlyTargetChanged($before, $after, $hotel->id, $inserted);

            $this->printReport($before, $after, $hotel->id, $fiches);

            if ($commit) {
                DB::commit();
                $this->info(sprintf('✔ COMMIT — %d fiches / %d voyageurs insérés dans « %s ». Marqueur : %s', $inserted['check_ins'], $inserted['guests'], $hotel->name, self::MARKER));
            } else {
                DB::rollBack();
                $this->warn('DRY-RUN — transaction annulée, rien n\'a été écrit. Relancer avec --commit pour insérer.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('ROLLBACK — '.$e->getMessage());

            return self::FAILURE;
        }
    }

    // ─── Génération du jeu de données ─────────────────────────────────────

    /**
     * ~34 check-ins / 40 voyageurs : 6 couples (même nom, mêmes dates, même
     * nationalité) + 28 voyageurs seuls, dont 2 fiches tunisiennes avec CIN.
     * Statuts : ~70 % terminés (1-5 nuits sur les 90 derniers jours),
     * ~30 % actifs (check-in 0-4 jours, départ prévu demain ou plus tard —
     * jamais aujourd'hui, pour ne pas déclencher le rappel « départ du jour »),
     * plafonnés à $maxActive (l'excédent bascule en terminé) pour un taux
     * d'occupation < 100 % avec une chambre libre.
     */
    private function buildFiches(int $maxActive): array
    {
        $couples = [
            ['FRA', 'completed'], ['ITA', 'completed'], ['GBR', 'completed'],
            ['JPN', 'completed'], ['ESP', 'active'], ['USA', 'active'],
        ];

        $singles = [
            ['DEU', 'completed'], ['DEU', 'completed'], ['NLD', 'completed'], ['SWE', 'completed'],
            ['POL', 'completed'], ['POL', 'completed'], ['CAN', 'completed'], ['BRA', 'completed'],
            ['BRA', 'completed'], ['ARG', 'completed'], ['KOR', 'completed'], ['CHN', 'completed'],
            ['CHN', 'completed'], ['TUR', 'completed'], ['TUR', 'completed'], ['MAR', 'completed'],
            ['DZA', 'completed'], ['ARE', 'completed'], ['AUS', 'completed'], ['TUN', 'completed'],
            ['FRA', 'active'], ['ITA', 'active'], ['DEU', 'active'], ['GBR', 'active'],
            ['SWE', 'active'], ['CAN', 'active'], ['MAR', 'active'], ['TUN', 'active'],
        ];

        $fiches      = [];
        $activesLeft = $maxActive;

        foreach ($couples as [$code, $status]) {
            if ($status === 'active') {
                $status = $activesLeft > 0 ? 'active' : 'completed';
                $activesLeft = max(0, $activesLeft - 1);
            }
            $dates = $this->stayDates($status);
            $last  = $this->pick(self::COUNTRIES[$code]['last']);
            $fiches[] = [
                'status' => $status,
                'dates'  => $dates,
                'purpose' => 'tourism', // les couples voyagent en tourisme
                'guests' => [
                    $this->buildGuest($code, 'M', $last),
                    $this->buildGuest($code, 'F', $last),
                ],
            ];
        }

        foreach ($singles as $i => [$code, $status]) {
            if ($status === 'active') {
                $status = $activesLeft > 0 ? 'active' : 'completed';
                $activesLeft = max(0, $activesLeft - 1);
            }
            $dates = $this->stayDates($status);
            $sex   = mt_rand(0, 1) ? 'M' : 'F';
            $fiches[] = [
                'status' => $status,
                'dates'  => $dates,
                // Majorité tourisme, quelques voyages d'affaires
                'purpose' => ($i % 5 === 2) ? 'business' : 'tourism',
                'guests' => [$this->buildGuest($code, $sex, null)],
            ];
        }

        return $fiches;
    }

    /** Combinaisons (prénom, nom) déjà émises — évite deux voyageurs homonymes. */
    private array $usedNames = [];

    private function buildGuest(string $code, string $sex, ?string $lastName): array
    {
        $pool = self::COUNTRIES[$code];

        // Re-tire tant que la combinaison exacte a déjà servi (pools restreints) ;
        // au-delà de 20 essais on accepte l'homonyme plutôt que de boucler.
        for ($try = 0; $try < 20; $try++) {
            $first = $this->pick($pool[$sex === 'M' ? 'm' : 'f']);
            $last  = $lastName ?? $this->pick($pool['last']);
            if (! isset($this->usedNames["$first|$last"])) {
                break;
            }
        }
        $this->usedNames["$first|$last"] = true;

        // Adultes 20-70 ans, distribution réaliste (majorité 20-50)
        $r   = mt_rand(1, 100);
        $age = $r <= 40 ? mt_rand(20, 35) : ($r <= 75 ? mt_rand(36, 50) : mt_rand(51, 70));
        $dob = now()->subYears($age)->subDays(mt_rand(0, 364))->toDateString();

        // Tunisiens → CIN (national_id), étrangers → passeport au format du pays
        $isTun = $code === 'TUN';

        return [
            'first_name'       => $first,
            'last_name'        => $last,
            'sex'              => $sex,
            'nationality_code' => $code,
            'date_of_birth'    => $dob,
            'place_of_birth'   => $pool['city'],
            'document'         => [
                'type'                 => $isTun ? 'national_id' : 'passport',
                'number'               => $this->documentNumber($isTun ? '########' : $pool['fmt']),
                'issuing_country_code' => $code,
            ],
        ];
    }

    /** Dates de séjour selon le statut. */
    private function stayDates(string $status): array
    {
        if ($status === 'completed') {
            $nights  = mt_rand(1, 5);
            $checkIn = now()->subDays(mt_rand($nights + 1, 90))->startOfDay();

            return [
                'check_in'  => $checkIn,
                'expected'  => $checkIn->copy()->addDays($nights),
                'actual'    => $checkIn->copy()->addDays($nights),
            ];
        }

        // active : arrivée il y a 0-4 jours, départ prévu ≥ demain (jamais
        // aujourd'hui → le rappel planifié « départ du jour » ne se déclenche
        // pas le jour du seed). actual_check_out_date reste null (logique du
        // modèle : renseignée uniquement au check-out réel).
        $daysAgo = mt_rand(0, 4);
        $checkIn = now()->subDays($daysAgo)->startOfDay();

        return [
            'check_in' => $checkIn,
            'expected' => $checkIn->copy()->addDays($daysAgo + mt_rand(1, 4)),
            'actual'   => null,
        ];
    }

    /** Génère un numéro fictif selon un format (# chiffre, L lettre, autre littéral). */
    private function documentNumber(string $fmt): string
    {
        do {
            $out = '';
            foreach (str_split($fmt) as $c) {
                $out .= match ($c) {
                    '#'     => (string) mt_rand(0, 9),
                    'L'     => chr(mt_rand(65, 90)),
                    default => $c,
                };
            }
            // Collision improbable avec un document réel ou déjà généré :
            // on régénère plutôt que de violer l'unique (type, number, country).
            $exists = TravelDocument::where('document_number', $out)->exists();
        } while ($exists);

        return $out;
    }

    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    // ─── Insertion ────────────────────────────────────────────────────────

    private function insertFiches(Hotel $hotel, User $creator, $rooms, array $fiches): array
    {
        $checkInsCount = 0;
        $guestsCount   = 0;
        $activeRoomIdx = 0; // les séjours actifs occupent des chambres DISTINCTES

        foreach ($fiches as $i => $fiche) {
            $dates = $fiche['dates'];

            // Actifs : une chambre différente chacun (maxActive ≤ chambres − 1
            // garantit qu'il en reste au moins une libre). Terminés : round-robin,
            // sans impact sur l'occupation actuelle.
            $roomId = null;
            if ($rooms->isNotEmpty()) {
                $roomId = $fiche['status'] === 'active'
                    ? $rooms[$activeRoomIdx++ % $rooms->count()]->id
                    : $rooms[$i % $rooms->count()]->id;
            }

            // Référence explicite « QYD-YYYYMMDD-Sxxx » : le hook creating ne
            // touche donc jamais check_in_sequences (table globale), et le
            // suffixe non numérique ne matche pas la regex du compteur.
            $reference = sprintf('QYD-%s-S%03d', $dates['check_in']->format('Ymd'), $i + 1);

            $checkIn = CheckIn::create([
                'hotel_id'                => $hotel->id,
                'room_id'                 => $roomId,
                'reference'               => $reference,
                'booking_source'          => $this->pick(['direct', 'booking', 'direct', 'phone', 'expedia']),
                'check_in_date'           => $dates['check_in']->toDateString(),
                'expected_check_out_date' => $dates['expected']->toDateString(),
                'actual_check_out_date'   => $dates['actual']?->toDateString(),
                'status'                  => $fiche['status'],
                'adults_count'            => count($fiche['guests']),
                'children_count'          => 0,
                'notes'                   => self::MARKER,
                'metadata'                => ['seed_batch' => self::MARKER, 'stay_purpose' => $fiche['purpose']],
                'created_by'              => $creator->id,
                'completed_by'            => $creator->id,
                'completed_at'            => $dates['check_in']->copy()->setTime(12, 0),
            ]);
            $checkInsCount++;

            foreach ($fiche['guests'] as $g => $guestData) {
                $guest = Guest::create([
                    'first_name'       => $guestData['first_name'],
                    'last_name'        => mb_strtoupper($guestData['last_name']),
                    'date_of_birth'    => $guestData['date_of_birth'],
                    'sex'              => $guestData['sex'],
                    'nationality_code' => $guestData['nationality_code'],
                    'country_of_birth' => $guestData['nationality_code'],
                    'place_of_birth'   => $guestData['place_of_birth'],
                    'metadata'         => ['seed_batch' => self::MARKER],
                ]);

                TravelDocument::create([
                    'guest_id'             => $guest->id,
                    'type'                 => $guestData['document']['type'],
                    'document_number'      => $guestData['document']['number'],
                    'issuing_country_code' => $guestData['document']['issuing_country_code'],
                    'issue_date'           => $dates['check_in']->copy()->subYears(mt_rand(1, 6))->toDateString(),
                    'expiry_date'          => $dates['check_in']->copy()->addYears(mt_rand(1, 8))->toDateString(),
                    'is_verified'          => true,
                    'metadata'             => ['seed_batch' => self::MARKER],
                ]);

                $checkIn->guests()->attach($guest->id, [
                    'is_primary' => $g === 0,
                    'added_by'   => $creator->id,
                    'added_at'   => $dates['check_in']->copy()->setTime(12, 0),
                ]);
                $guestsCount++;
            }
        }

        return ['check_ins' => $checkInsCount, 'guests' => $guestsCount];
    }

    // ─── Vérifications ────────────────────────────────────────────────────

    private function resolveCreator(Hotel $hotel): ?User
    {
        $users = $hotel->users()->get();

        return $users->first(fn (User $u) => $u->hasRole('hotel_admin'))
            ?? $users->first(fn (User $u) => $u->hasRole('receptionist'))
            ?? $users->first();
    }

    /** Counts par établissement + totaux + compteur de séquence du jour. */
    private function snapshotCounts(): array
    {
        return [
            'check_ins_by_hotel' => CheckIn::withTrashed()
                ->select('hotel_id', DB::raw('count(*) as n'))
                ->groupBy('hotel_id')
                ->pluck('n', 'hotel_id')
                ->toArray(),
            'guests'             => Guest::withTrashed()->count(),
            'travel_documents'   => TravelDocument::count(),
            'sequence_today'     => DB::table('check_in_sequences')
                ->where('date', now()->toDateString())
                ->value('last_number'),
        ];
    }

    private function assertOnlyTargetChanged(array $before, array $after, string $hotelId, array $inserted): void
    {
        foreach ($after['check_ins_by_hotel'] as $hid => $n) {
            $prev = $before['check_ins_by_hotel'][$hid] ?? 0;
            if ($hid === $hotelId) {
                if ($n - $prev !== $inserted['check_ins']) {
                    throw new \RuntimeException(sprintf('Delta inattendu sur Dar El Kenz : %d (attendu %d).', $n - $prev, $inserted['check_ins']));
                }
                continue;
            }
            if ($n !== $prev) {
                throw new \RuntimeException(sprintf('VIOLATION : le count de l\'établissement %s a changé (%d → %d).', $hid, $prev, $n));
            }
        }
        foreach ($before['check_ins_by_hotel'] as $hid => $prev) {
            if ($hid !== $hotelId && ! array_key_exists($hid, $after['check_ins_by_hotel'])) {
                throw new \RuntimeException(sprintf('VIOLATION : l\'établissement %s a disparu des counts.', $hid));
            }
        }
        if ($after['sequence_today'] !== $before['sequence_today']) {
            throw new \RuntimeException('VIOLATION : check_in_sequences (compteur global de références) a été modifié.');
        }
        if ($after['guests'] - $before['guests'] !== $inserted['guests']) {
            throw new \RuntimeException('Delta guests inattendu.');
        }
    }

    private function printReport(array $before, array $after, string $hotelId, array $fiches): void
    {
        $this->newLine();
        $this->line('── Fiches générées ──');
        $this->table(
            ['Réf', 'Statut', 'Arrivée', 'Départ prévu', 'Départ réel', 'Voyageur(s)', 'Nat.', 'Document'],
            collect($fiches)->map(function ($f, $i) {
                $names = collect($f['guests'])->map(fn ($g) => $g['first_name'].' '.mb_strtoupper($g['last_name']))->join(' + ');

                return [
                    sprintf('QYD-%s-S%03d', $f['dates']['check_in']->format('Ymd'), $i + 1),
                    $f['status'],
                    $f['dates']['check_in']->toDateString(),
                    $f['dates']['expected']->toDateString(),
                    $f['dates']['actual']?->toDateString() ?? '—',
                    $names,
                    $f['guests'][0]['nationality_code'],
                    $f['guests'][0]['document']['type'].' '.$f['guests'][0]['document']['number'],
                ];
            })->toArray(),
        );

        $this->line('── Counts check_ins par établissement (AVANT → APRÈS) ──');
        $hotelIds = array_unique(array_merge(array_keys($before['check_ins_by_hotel']), array_keys($after['check_ins_by_hotel'])));
        $rows = [];
        foreach ($hotelIds as $hid) {
            $b = $before['check_ins_by_hotel'][$hid] ?? 0;
            $a = $after['check_ins_by_hotel'][$hid] ?? 0;
            $rows[] = [
                $hid === $hotelId ? "$hid (Dar El Kenz)" : $hid,
                $b,
                $a,
                $a === $b ? '=' : sprintf('%+d', $a - $b),
            ];
        }
        $this->table(['Hotel', 'Avant', 'Après', 'Δ'], $rows);
        $this->line(sprintf('Guests : %d → %d | Travel documents : %d → %d', $before['guests'], $after['guests'], $before['travel_documents'], $after['travel_documents']));
    }
}
