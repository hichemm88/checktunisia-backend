<?php

namespace App\Services\PartnerApi;

use App\Models\ApiKey;
use App\Models\ApiPartner;
use Illuminate\Support\Str;

/**
 * Émission/résolution des clés API partenaires (qyd_live_… / qyd_test_…).
 * Le clair n'est jamais persisté ni loggé — seul le hash SHA-256 l'est.
 */
class ApiKeyService
{
    /**
     * @return array{key: ApiKey, plaintext: string} le clair, retourné UNE SEULE FOIS
     */
    public function issue(ApiPartner $partner, string $mode): array
    {
        $random = Str::random(40);
        $plaintext = "qyd_{$mode}_{$random}";

        $key = ApiKey::create([
            'partner_id' => $partner->id,
            'mode' => $mode,
            'prefix' => "qyd_{$mode}_".substr($random, 0, 6),
            'key_hash' => hash('sha256', $plaintext),
        ]);

        return ['key' => $key, 'plaintext' => $plaintext];
    }

    public function resolve(?string $bearerToken): ?ApiKey
    {
        if (! $bearerToken || ! str_starts_with($bearerToken, 'qyd_')) {
            return null;
        }

        $key = ApiKey::where('key_hash', hash('sha256', $bearerToken))
            ->whereNull('revoked_at')
            ->with('partner')
            ->first();

        if (! $key || ! $key->partner || ! $key->partner->isActive()) {
            return null;
        }

        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        return $key;
    }

    public function revoke(ApiKey $key): void
    {
        $key->update(['revoked_at' => now()]);
    }
}
