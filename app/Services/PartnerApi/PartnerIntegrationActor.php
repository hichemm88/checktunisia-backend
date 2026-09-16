<?php

namespace App\Services\PartnerApi;

use App\Models\Hotel;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Utilisateur système, un par organisation, qui "agit" pour le compte de
 * l'API partenaire dans CheckInService. Zéro changement à la logique
 * métier existante : dédup document, invariant "un seul principal", audit,
 * quota, relais WhatsApp fonctionnent à l'identique (voir plan, décision
 * structurante).
 */
class PartnerIntegrationActor
{
    public function forOrganization(Organization $org): User
    {
        $email = "integration+{$org->id}@partners.qayed.tn";

        $user = User::withTrashed()->where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'organization_id' => $org->id,
                'email' => $email,
                'password' => Str::password(64),
                'first_name' => 'Intégration API',
                'last_name' => $org->name,
                'status' => 'active',
                'is_system_actor' => true,
                'email_verified_at' => now(),
            ]);
            $user->assignRole('api_integration');
        }

        return $user;
    }

    /**
     * Rattache l'acteur système à l'établissement (user_hotels) s'il ne l'est
     * pas déjà — nécessaire pour que ResolveTenant/CheckInService le
     * reconnaissent comme un membre légitime de l'établissement.
     */
    public function ensureAttachedTo(User $actor, Hotel $hotel): void
    {
        if ($actor->hotels()->where('hotels.id', $hotel->id)->exists()) {
            return;
        }

        DB::table('user_hotels')->insertOrIgnore([
            'user_id' => $actor->id,
            'hotel_id' => $hotel->id,
            'granted_at' => now(),
        ]);
    }
}
