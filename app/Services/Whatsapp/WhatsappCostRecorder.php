<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappBillableMessage;
use App\Models\WhatsappMessageCost;
use App\Models\WhatsappSendLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Comptabilité des messages Meta : qui enregistre quoi, quand, et surtout
 * quand il ne faut RIEN enregistrer.
 *
 * ── La règle de facturation ──────────────────────────────────────────────
 *
 * Depuis juillet 2025, Meta facture par message template LIVRÉ. Trois
 * conséquences, toutes contre-intuitives, et toutes des sources d'erreur
 * classiques :
 *
 *  1. `sent` NE COÛTE RIEN. C'est l'accusé d'acceptation de Meta, pas une
 *     livraison. Compter au `sent` surestimerait de tous les numéros morts,
 *     bloqués ou éteints — c'est-à-dire précisément la part du trafic qu'on
 *     voudrait voir.
 *  2. `failed` ne coûte rien non plus, y compris après un `sent`.
 *  3. Un `read` sans `delivered` préalable FACTURE : Meta ne garantit pas
 *     l'ordre des accusés, et un message lu a forcément été livré. Le verrou
 *     d'idempotence évite le double comptage quand les deux finissent par
 *     arriver.
 *
 * ── Le verrou ────────────────────────────────────────────────────────────
 *
 * Meta rejoue chaque livraison de webhook non acquittée, et rejoue aussi après
 * ses propres incidents. Le comptage passe donc par un UPDATE conditionnel sur
 * `counted_at IS NULL` : c'est la base qui arbitre, pas une lecture suivie
 * d'une écriture — deux rejeux simultanés se départagent en SQL, et le perdant
 * ne compte rien.
 *
 * ── Les deux sources ─────────────────────────────────────────────────────
 *
 * Ce service alimente la source `estimate` (calcul local, toujours
 * disponible). `whatsapp:sync-costs` alimente la source `meta` (montants
 * réels, autoritaires). Les deux vivent côte à côte : voir la migration.
 */
class WhatsappCostRecorder
{
    /** Clé de cache portant l'instant de la dernière synchro Meta réussie. */
    private const SYNC_KEY = 'whatsapp:costs:last_meta_sync';

    /**
     * Enregistre un message SORTANT au registre. Aucun coût à ce stade : la
     * ligne existe pour qu'un `delivered` ultérieur sache à qui l'imputer.
     *
     * Sans wamid — canal historique WhatsApp Web, envoi refusé — il n'y a rien
     * à suivre : Meta ne facturera rien et le webhook ne remontera rien.
     */
    public function registerSend(
        ?string $wamid,
        string $category,
        ?string $hotelId = null,
        ?string $sendLogId = null,
        ?string $templateName = null,
    ): ?WhatsappBillableMessage {
        if (blank($wamid)) {
            return null;
        }

        $category = $this->normalizeCategory($category);

        try {
            /*
             * `updateOrCreate` et non `create` : un même wamid peut repasser
             * ici (rejeu d'un job, reprise manuelle). On rafraîchit
             * l'attribution sans jamais toucher au comptage — `counted_at`,
             * `cost_usd` et `unit_price_usd` ne sont pas dans la charge.
             */
            return WhatsappBillableMessage::updateOrCreate(
                ['wamid' => $wamid],
                [
                    'category' => $category,
                    'hotel_id' => $hotelId,
                    'send_log_id' => $sendLogId,
                    'template_name' => $templateName,
                    'sent_at' => now(),
                ],
            );
        } catch (QueryException $e) {
            // La comptabilité ne doit JAMAIS faire échouer un envoi : une
            // fiche non transmise coûte infiniment plus cher qu'une ligne de
            // coût manquante.
            Log::warning('[whatsapp-costs] enregistrement d\'envoi impossible : '.$e->getMessage());

            return null;
        }
    }

    /** Raccourci pour les fiches : catégorie déduite du modèle de la ligne. */
    public function registerFicheSend(WhatsappSendLog $job, ?string $wamid): ?WhatsappBillableMessage
    {
        $template = $job->template_name ?: (string) config('whatsapp.cloud.template.name');

        return $this->registerSend(
            $wamid,
            $this->categoryForTemplate($template),
            $job->hotel_id,
            $job->id,
            $template,
        );
    }

    /**
     * Raccourci pour les codes de connexion.
     *
     * `hotel_id` reste nul, et ce n'est pas un oubli : un code appartient au
     * compte autorité qui se connecte, pas à un client hôtelier. L'imputer à
     * l'établissement de la dernière fiche consultée gonflerait sa marge
     * apparente avec un coût qu'il n'a pas causé.
     */
    public function registerOtpSend(?string $wamid): ?WhatsappBillableMessage
    {
        $template = (string) config('whatsapp.cloud.template.otp_name');

        return $this->registerSend(
            $wamid,
            WhatsappBillableMessage::CATEGORY_AUTHENTICATION,
            null,
            null,
            $template,
        );
    }

    /**
     * Un message a été LIVRÉ : c'est le seul événement facturable.
     *
     * @param  WhatsappSendLog|null  $job  ligne d'outbox, pour reconstituer une
     *                                     entrée de registre absente (messages
     *                                     partis avant la mise en service du
     *                                     suivi).
     * @return bool true si ce message vient d'être compté, false s'il l'était
     *              déjà ou s'il n'y a rien à compter.
     */
    public function recordDelivered(string $wamid, ?WhatsappSendLog $job = null, ?Carbon $at = null): bool
    {
        if (blank($wamid)) {
            return false;
        }

        $entry = WhatsappBillableMessage::find($wamid);

        if ($entry === null) {
            $entry = $job !== null ? $this->registerFicheSend($job, $wamid) : null;
        }

        if ($entry === null) {
            // Message inconnu et sans ligne d'outbox : un autre système
            // partage le numéro, ou le journal a été purgé. Rien à imputer.
            return false;
        }

        $at ??= now();
        $rate = $this->rateFor($entry->category);

        /*
         * Le verrou. `whereNull('counted_at')` transforme la question « ai-je
         * déjà compté ? » en une décision prise par la base : deux rejeux
         * concurrents produisent un gagnant et un `0 ligne affectée`.
         */
        $claimed = WhatsappBillableMessage::query()
            ->whereKey($entry->wamid)
            ->whereNull('counted_at')
            ->update([
                'delivered_at' => $entry->delivered_at ?? $at,
                'counted_at' => $at,
                'unit_price_usd' => $rate,
                'cost_usd' => $rate,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $this->bumpEstimate(
            $at->toDateString(),
            $entry->category,
            $entry->hotel_id,
            1,
            $rate,
        );

        return true;
    }

    /**
     * Remplace les agrégats d'une journée par les montants réels de Meta.
     *
     * REMPLACE, et n'additionne pas : les analytics Meta sont un état
     * consolidé de la journée, pas un flux d'événements. Rejouer la
     * synchronisation deux fois de suite doit donner le même total.
     *
     * Meta ne ventile pas par établissement — il ne connaît pas nos clients.
     * Les lignes `meta` sont donc globales (hotel_id nul), et la ventilation
     * par client reste celle de l'estimation locale.
     */
    public function applyMetaDaily(string $date, string $category, int $messages, float $costUsd): void
    {
        $category = $this->normalizeCategory($category);

        $query = fn () => $this->aggregate($date, $category, null, WhatsappMessageCost::SOURCE_META)->first();

        if ($existing = $query()) {
            $existing->update(['messages' => $messages, 'cost_usd' => $costUsd]);

            return;
        }

        if ($this->insertAggregate($date, $category, null, WhatsappMessageCost::SOURCE_META, $messages, $costUsd)) {
            return;
        }

        // Course perdue contre une autre exécution : la ligne existe désormais.
        $query()?->update(['messages' => $messages, 'cost_usd' => $costUsd]);
    }

    /** Mémorise l'instant d'une synchronisation Meta réussie. */
    public function rememberMetaSync(?Carbon $at = null): void
    {
        Cache::put(self::SYNC_KEY, ($at ?? now())->toIso8601String(), now()->addDays(90));
    }

    /**
     * Dernière synchronisation Meta réussie.
     *
     * Le cache porte la dernière TENTATIVE aboutie, y compris quand elle n'a
     * ramené aucune donnée — cas parfaitement normal sur une journée sans
     * trafic. La base sert de repli : après un vidage de cache, le plus récent
     * agrégat `meta` reste une réponse honnête, quoique pessimiste.
     */
    public function lastMetaSyncAt(): ?Carbon
    {
        $cached = Cache::get(self::SYNC_KEY);

        if (filled($cached)) {
            return Carbon::parse($cached);
        }

        $latest = WhatsappMessageCost::where('source', WhatsappMessageCost::SOURCE_META)
            ->max('updated_at');

        return filled($latest) ? Carbon::parse($latest) : null;
    }

    // ── Lecture ──────────────────────────────────────────────────────────────

    /**
     * Source a servir pour une periode.
     *
     * Meta fait autorite DES QU'IL A REPONDU sur la periode ; sinon
     * l'estimation locale, qui est toujours disponible. On ne panache jamais
     * les deux dans un meme total : un mois moitie reel moitie estime ne
     * serait ni l'un ni l'autre, et aucun libelle ne pourrait le dire
     * honnetement a l'ecran.
     */
    public function preferredSource(Carbon $from, Carbon $to): string
    {
        $hasMeta = WhatsappMessageCost::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('source', WhatsappMessageCost::SOURCE_META)
            ->exists();

        return $hasMeta ? WhatsappMessageCost::SOURCE_META : WhatsappMessageCost::SOURCE_ESTIMATE;
    }

    /**
     * Total du mois en cours — le chiffre porte par la carte du dashboard et
     * par le bloc `meta_costs` des KPI.
     *
     * Un seul endroit calcule ce total, pour que la carte, la page et les KPI
     * ne puissent pas afficher trois montants differents du meme mois.
     *
     * @return array{cost_usd:string, messages:int, source:string, currency:string}
     */
    public function currentMonthTotals(): array
    {
        $from = now()->startOfMonth();
        $to = now()->endOfMonth();
        $source = $this->preferredSource($from, $to);

        $row = WhatsappMessageCost::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('source', $source)
            ->selectRaw('COALESCE(SUM(messages),0) as n, COALESCE(SUM(cost_usd),0) as cost')
            ->first();

        return [
            'currency' => 'USD',
            'cost_usd' => number_format((float) ($row->cost ?? 0), 6, '.', ''),
            'messages' => (int) ($row->n ?? 0),
            'source' => $source,
        ];
    }

    // ── Tarifs ───────────────────────────────────────────────────────────────

    /**
     * Catégorie de facturation d'un modèle.
     *
     * Ordre de résolution : surcharge explicite, puis les deux modèles que
     * nous envoyons réellement, puis `utility` — la catégorie de nos envois
     * normaux, et la seule valeur par défaut qui ne fasse pas apparaître un
     * coût marketing là où il n'y en a pas.
     */
    public function categoryForTemplate(?string $template): string
    {
        $template = (string) $template;

        $explicit = (array) config('whatsapp.pricing.template_categories', []);
        if (isset($explicit[$template])) {
            return $this->normalizeCategory((string) $explicit[$template]);
        }

        if ($template !== '' && $template === (string) config('whatsapp.cloud.template.otp_name')) {
            return WhatsappBillableMessage::CATEGORY_AUTHENTICATION;
        }

        return WhatsappBillableMessage::CATEGORY_UTILITY;
    }

    /** Prix USD d'un message livré dans cette catégorie. */
    public function rateFor(string $category): float
    {
        $rates = (array) config('whatsapp.pricing.rates', []);

        return round((float) ($rates[$this->normalizeCategory($category)] ?? 0.0), 6);
    }

    /** @return array<string,float> */
    public function rates(): array
    {
        $out = [];
        foreach (WhatsappBillableMessage::CATEGORIES as $category) {
            $out[$category] = $this->rateFor($category);
        }

        return $out;
    }

    /** Taux de change d'affichage USD → TND. Jamais utilisé à l'écriture. */
    public function usdToTnd(): float
    {
        return round((float) config('whatsapp.pricing.usd_to_tnd', 0), 4);
    }

    private function normalizeCategory(string $category): string
    {
        $category = strtolower(trim($category));

        return in_array($category, WhatsappBillableMessage::CATEGORIES, true)
            ? $category
            : WhatsappBillableMessage::CATEGORY_UTILITY;
    }

    // ── Agrégats ─────────────────────────────────────────────────────────────

    /**
     * Ajoute un message à l'agrégat local du jour.
     *
     * Lecture-puis-incrément, avec repli sur l'insertion et re-lecture si un
     * autre processus a créé la ligne entre-temps : les deux index uniques
     * partiels de la migration garantissent qu'il n'existera jamais deux
     * lignes pour la même clé, quelle qu'ait été la course.
     */
    private function bumpEstimate(string $date, string $category, ?string $hotelId, int $messages, float $cost): void
    {
        $query = fn () => $this->aggregate($date, $category, $hotelId, WhatsappMessageCost::SOURCE_ESTIMATE)->first();

        $existing = $query();

        if ($existing === null) {
            $existing = $this->insertAggregate(
                $date, $category, $hotelId, WhatsappMessageCost::SOURCE_ESTIMATE, $messages, $cost,
            );

            // Insertion réussie : le total est déjà bon, rien à incrémenter.
            if ($existing !== null) {
                return;
            }

            // Insertion refusée par l'index unique : la ligne existe désormais.
            $existing = $query();
        }

        if ($existing === null) {
            Log::warning('[whatsapp-costs] agrégat introuvable après insertion concurrente', [
                'date' => $date, 'category' => $category,
            ]);

            return;
        }

        // Incréments SQL (et non lecture + écriture) : plusieurs webhooks
        // arrivent en parallèle sur la même journée et la même catégorie.
        $existing->increment('messages', $messages);
        $existing->increment('cost_usd', $cost);
    }

    /**
     * Insère un agrégat. Rend null — sans lever — quand l'index unique refuse
     * l'insertion, ce qui signifie qu'un autre processus a gagné la course.
     *
     * L'insertion est enveloppée dans une transaction pour que l'échec
     * n'invalide pas une transaction englobante : sous PostgreSQL, une erreur
     * dans une transaction ouverte la met en état « aborted » et tout ce qui
     * suit échoue. Imbriquée, `DB::transaction` pose un SAVEPOINT, dont le
     * retour arrière est local.
     */
    private function insertAggregate(
        string $date,
        string $category,
        ?string $hotelId,
        string $source,
        int $messages,
        float $cost,
    ): ?WhatsappMessageCost {
        try {
            return DB::transaction(fn () => WhatsappMessageCost::create([
                'date' => $date,
                'category' => $category,
                'hotel_id' => $hotelId,
                'source' => $source,
                'messages' => $messages,
                'cost_usd' => $cost,
            ]));
        } catch (QueryException $e) {
            return null;
        }
    }

    /** @return \Illuminate\Database\Eloquent\Builder<WhatsappMessageCost> */
    private function aggregate(string $date, string $category, ?string $hotelId, string $source)
    {
        return WhatsappMessageCost::query()
            ->whereDate('date', $date)
            ->where('category', $category)
            ->where('source', $source)
            ->when(
                $hotelId === null,
                fn ($q) => $q->whereNull('hotel_id'),
                fn ($q) => $q->where('hotel_id', $hotelId),
            );
    }
}
