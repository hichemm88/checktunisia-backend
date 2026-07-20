<?php

namespace App\Services\Billing;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Application des codes promo aux factures.
 *
 * La remise porte sur le montant HT (avant taxe) et ne peut jamais depasser ce
 * montant (une facture ne devient pas negative). Le comptage des usages et la
 * trace de redemption se font dans une transaction, avec un verrou sur la ligne
 * coupon pour empecher deux applications concurrentes de depasser max_uses.
 */
class CouponService
{
    /**
     * Valide un code sans le consommer et renvoie le coupon correspondant.
     *
     * @throws CouponException si le code est inconnu / inactif / expire / epuise
     *                         / sous le montant minimum.
     */
    public function validate(string $code, float $amount): Coupon
    {
        $coupon = Coupon::whereRaw('UPPER(code) = ?', [Str::upper(trim($code))])->first();

        if (! $coupon) {
            throw new CouponException('not_found', 'Code promo introuvable.');
        }
        if (! $coupon->active) {
            throw new CouponException('inactive', 'Ce code promo est desactive.');
        }
        if ($coupon->isExpired()) {
            throw new CouponException('expired', 'Ce code promo a expire.');
        }
        if ($coupon->isExhausted()) {
            throw new CouponException('exhausted', 'Ce code promo a atteint son nombre maximum d\'utilisations.');
        }
        if ($coupon->min_amount !== null && $amount < (float) $coupon->min_amount) {
            throw new CouponException('below_min', 'Montant insuffisant pour appliquer ce code promo.');
        }

        return $coupon;
    }

    /**
     * Montant de la remise pour un coupon sur un montant HT donne. Bornee au
     * montant lui-meme (jamais de total negatif) et arrondie a la millieme.
     */
    public function discountFor(Coupon $coupon, float $amount): float
    {
        $raw = $coupon->type === Coupon::TYPE_PERCENT
            ? $amount * (float) $coupon->value / 100
            : (float) $coupon->value;

        return round(min(max($raw, 0), $amount), 3);
    }

    /**
     * Applique un coupon a une facture : calcule la remise, l'inscrit sur la
     * facture (discount_amount + coupon_code), recalcule le total, incremente le
     * compteur d'usage et enregistre la redemption. Idempotent par facture grace
     * a la contrainte unique (coupon_id, invoice_id).
     *
     * @throws CouponException already_applied si ce coupon est deja pose sur la facture.
     */
    public function apply(Coupon $coupon, Invoice $invoice, ?string $recordedBy = null): float
    {
        $baseAmount = (float) $invoice->amount;
        $discount   = $this->discountFor($coupon, $baseAmount);

        return DB::transaction(function () use ($coupon, $invoice, $discount, $recordedBy) {
            // Verrou pessimiste : relit le coupon FOR UPDATE pour serialiser le
            // controle de quota face a des applications concurrentes.
            $locked = Coupon::whereKey($coupon->id)->lockForUpdate()->first();
            if ($locked->isExhausted()) {
                throw new CouponException('exhausted', 'Ce code promo a atteint son nombre maximum d\'utilisations.');
            }

            if (CouponRedemption::where('invoice_id', $invoice->id)->exists()) {
                throw new CouponException('already_applied', 'Un code promo est deja applique a cette facture.');
            }

            $tax   = (float) $invoice->tax_amount;
            $total = max(0, round((float) $invoice->amount + $tax - $discount, 3));

            $invoice->update([
                'discount_amount' => $discount,
                'coupon_code'     => $locked->code,
                'total_amount'    => $total,
            ]);

            CouponRedemption::create([
                'coupon_id'         => $locked->id,
                'invoice_id'        => $invoice->id,
                'organization_id'   => $invoice->subscription?->organization_id,
                'amount_discounted' => $discount,
                'redeemed_by'       => $recordedBy,
                'created_at'        => now(),
            ]);

            $locked->increment('used_count');

            AuditLogger::log('coupon.applied', $invoice, newValues: [
                'coupon_code'     => $locked->code,
                'discount_amount' => (string) $discount,
            ]);

            return $discount;
        });
    }
}
