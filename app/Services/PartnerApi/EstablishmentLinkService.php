<?php

namespace App\Services\PartnerApi;

use App\Exceptions\PartnerApi\PartnerApiException;
use App\Models\ApiPartner;
use App\Models\EstablishmentLinkCode;
use App\Models\EstablishmentPartnerLink;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Liaison établissement ↔ partenaire (§1). Le code de liaison est à usage
 * unique, valable 24h, généré par l'owner et échangé par le partenaire.
 */
class EstablishmentLinkService
{
    /** Alphabet Crockford (sans caractères ambigus) — code lisible à voix haute. */
    private const CODE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** @return array{code: EstablishmentLinkCode, plaintext: string} */
    public function generate(Hotel $hotel, User $owner): array
    {
        $plaintext = $this->randomCode();

        $code = EstablishmentLinkCode::create([
            'hotel_id' => $hotel->id,
            'created_by' => $owner->id,
            'code_hash' => hash('sha256', $plaintext),
            'expires_at' => now()->addHours((int) config('partner_api.link_code.ttl_hours', 24)),
        ]);

        return ['code' => $code, 'plaintext' => $plaintext];
    }

    /**
     * Échange le code contre une liaison active. Verrouille la ligne pour
     * qu'un même code ne puisse jamais être consommé deux fois en concurrence.
     */
    public function exchange(ApiPartner $partner, string $plaintextCode): EstablishmentPartnerLink
    {
        return DB::transaction(function () use ($partner, $plaintextCode) {
            $code = EstablishmentLinkCode::where('code_hash', hash('sha256', strtoupper(trim($plaintextCode))))
                ->lockForUpdate()
                ->first();

            if (! $code) {
                throw new PartnerApiException(ErrorCodes::INVALID_LINK_CODE);
            }

            if ($code->isConsumed()) {
                throw new PartnerApiException(ErrorCodes::LINK_CODE_ALREADY_USED);
            }

            if ($code->isExpired()) {
                throw new PartnerApiException(ErrorCodes::LINK_CODE_EXPIRED);
            }

            $code->update(['consumed_at' => now(), 'consumed_by_partner_id' => $partner->id]);

            $link = EstablishmentPartnerLink::query()
                ->where('hotel_id', $code->hotel_id)
                ->where('partner_id', $partner->id)
                ->first();

            if ($link) {
                $link->update(['linked_at' => now(), 'revoked_at' => null, 'revoked_by' => null]);

                return $link;
            }

            return EstablishmentPartnerLink::create([
                'hotel_id' => $code->hotel_id,
                'partner_id' => $partner->id,
                'linked_at' => now(),
            ]);
        });
    }

    public function revoke(EstablishmentPartnerLink $link, User $revokedBy): void
    {
        $link->update(['revoked_at' => now(), 'revoked_by' => $revokedBy->id]);
    }

    /** Établissement actif et bien lié à ce partenaire — lève establishment_not_linked sinon. */
    public function assertLinked(ApiPartner $partner, string $hotelId): EstablishmentPartnerLink
    {
        $link = EstablishmentPartnerLink::active()
            ->where('partner_id', $partner->id)
            ->where('hotel_id', $hotelId)
            ->first();

        if (! $link) {
            throw new PartnerApiException(ErrorCodes::ESTABLISHMENT_NOT_LINKED);
        }

        return $link;
    }

    private function randomCode(): string
    {
        $chars = '';
        for ($i = 0; $i < 10; $i++) {
            $chars .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        return Str::of($chars)->upper();
    }
}
