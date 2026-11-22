<?php

namespace App\Services\PartnerApi;

use App\Models\CheckIn;
use App\Models\Organization;
use App\Models\WhatsappSendLog;
use App\Services\Subscription\PlanEntitlements;
use App\Services\Whatsapp\WhatsappOutboxService;
use Illuminate\Support\Collection;

/**
 * v1.2 — statut d'envoi d'une fiche à l'autorité, tel que l'API publique
 * l'expose (`delivery_status`). Dérivé du SEUL circuit de transmission
 * existant : le journal `whatsapp_send_log` (une ligne par couple voyageur ×
 * destinataire, alimentée par WhatsappOutboxService quel que soit le canal
 * actif, Web ou Cloud API).
 *
 * Valeurs :
 *  - not_applicable : fiche en mode test (clé qyd_test_, jamais transmise),
 *                     brouillon (rien n'est transmis avant validation), séjour
 *                     annulé ou non venu sans aucun envoi, ou module d'envoi
 *                     inactif (canal non configuré, relais coupé par le pack de
 *                     l'hébergeur) sans aucun envoi journalisé ;
 *  - failed         : au moins un envoi en échec DÉFINITIF (status failed, ou
 *                     cancelled : fiche bloquée avant envoi, arriéré annulé,
 *                     échec abandonné par un administrateur) ;
 *  - pending        : sinon, au moins un envoi encore en file / en reprise,
 *                     ou fiche validée dont aucun envoi n'est encore journalisé
 *                     alors que le module est actif ;
 *  - sent           : tous les envois ont été acceptés par le canal de
 *                     transmission (status sent).
 *
 * « sent » n'est PAS un accusé de réception gouvernemental (voir
 * authority_status) : c'est l'acceptation par le canal de transmission. Un
 * échec de livraison signalé ensuite par le canal repasse l'envoi en reprise
 * ou en échec, et le statut agrégé suit.
 */
class FicheDeliveryStatus
{
    public const SENT = 'sent';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public const NOT_APPLICABLE = 'not_applicable';

    /** @var array<string, bool> relais actif, par organisation (cache de requête) */
    private array $relayByOrg = [];

    public function __construct(private WhatsappOutboxService $outbox) {}

    public function forCheckIn(CheckIn $checkIn): string
    {
        return $this->forCheckIns(collect([$checkIn]))[$checkIn->id];
    }

    /**
     * Calcul groupé (une seule requête sur le journal) pour une page de liste.
     *
     * @param  Collection<int, CheckIn>  $checkIns
     * @return array<string, string> check_in_id => delivery_status
     */
    public function forCheckIns(Collection $checkIns): array
    {
        $ids = $checkIns->pluck('id')->filter()->values()->all();

        $statusesByCheckIn = $ids === []
            ? collect()
            : WhatsappSendLog::query()
                ->whereIn('check_in_id', $ids)
                ->where('is_test', false)
                ->get(['check_in_id', 'status'])
                ->groupBy('check_in_id')
                ->map(fn (Collection $logs) => $logs->pluck('status')->all());

        $result = [];

        foreach ($checkIns as $checkIn) {
            $result[$checkIn->id] = $this->resolve($checkIn, $statusesByCheckIn->get($checkIn->id, []));
        }

        return $result;
    }

    /** @param  array<int, string>  $statuses */
    private function resolve(CheckIn $checkIn, array $statuses): string
    {
        if ((bool) ($checkIn->metadata['test_mode'] ?? false) || $checkIn->status === 'draft') {
            return self::NOT_APPLICABLE;
        }

        if ($statuses !== []) {
            return self::aggregate($statuses);
        }

        // Aucun envoi journalisé.
        if (! in_array($checkIn->status, ['active', 'completed'], true)) {
            return self::NOT_APPLICABLE;
        }

        return $this->relayActiveFor($checkIn) ? self::PENDING : self::NOT_APPLICABLE;
    }

    /**
     * Agrégation des envois d'une fiche multi-voyageurs : un échec définitif
     * l'emporte, puis un envoi en cours, sinon tout est parti.
     *
     * @param  iterable<int, string>  $statuses  valeurs de whatsapp_send_log.status
     */
    public static function aggregate(iterable $statuses): string
    {
        $statuses = collect($statuses);

        if ($statuses->isEmpty()) {
            return self::PENDING;
        }

        if ($statuses->contains(fn ($s) => in_array($s, [WhatsappSendLog::STATUS_FAILED, WhatsappSendLog::STATUS_CANCELLED], true))) {
            return self::FAILED;
        }

        if ($statuses->contains(fn ($s) => $s !== WhatsappSendLog::STATUS_SENT)) {
            return self::PENDING;
        }

        return self::SENT;
    }

    private function relayActiveFor(CheckIn $checkIn): bool
    {
        if (! $this->outbox->enabled()) {
            return false;
        }

        $org = $checkIn->hotel?->organization;

        if (! $org instanceof Organization) {
            return true;
        }

        return $this->relayByOrg[$org->id] ??= PlanEntitlements::allows($org, 'whatsapp_relay');
    }
}
