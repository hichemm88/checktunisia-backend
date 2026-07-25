<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\AuthorityUserProfile;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MODULE PROVISOIRE — envoi direct des fiches (Phase 2).
 *
 * Permet au manager établissement (hotel_admin) de VOIR les agents destinataires
 * vérifiés et de COCHER ceux qui reçoivent les fiches de police de SON
 * établissement. Les numéros restent gérés par l'admin plateforme (affichés
 * masqués ici) — le manager ne fait que sélectionner, jamais saisir un numéro.
 */
class HotelWhatsappRecipientController extends Controller
{
    /** GET hotel/whatsapp-recipients — agents vérifiés + lesquels sont cochés pour cet établissement. */
    public function index(): JsonResponse
    {
        $hotel = app('tenant');
        $selected = $hotel->whatsappRecipientProfiles()->pluck('authority_user_profiles.id')->all();

        $available = AuthorityUserProfile::query()
            ->where('receives_whatsapp_fiches', true)
            ->whereNotNull('whatsapp_number')
            ->with(['user', 'organization'])
            ->get()
            ->map(fn (AuthorityUserProfile $p) => [
                'id' => $p->id,
                'name' => trim(((string) $p->user?->first_name).' '.((string) $p->user?->last_name)),
                'organization' => $p->organization?->name,
                'rank' => $p->rank,
                'number_masked' => $this->mask($p->whatsapp_number),
                'selected' => in_array($p->id, $selected, true),
            ])
            ->sortBy('organization')
            ->values();

        return response()->json(['data' => $available]);
    }

    /** PUT hotel/whatsapp-recipients — remplace la sélection des destinataires. */
    public function sync(Request $request): JsonResponse
    {
        $hotel = app('tenant');

        $v = $request->validate([
            'recipient_ids' => ['present', 'array'],
            'recipient_ids.*' => ['integer', 'exists:authority_user_profiles,id'],
        ]);

        // Ne garder que des agents qui reçoivent réellement (sécurité : pas de
        // rattachement à un profil sans numéro / qui ne reçoit pas).
        $valid = AuthorityUserProfile::whereIn('id', $v['recipient_ids'])
            ->where('receives_whatsapp_fiches', true)
            ->whereNotNull('whatsapp_number')
            ->pluck('id')
            ->all();

        $hotel->whatsappRecipientProfiles()->sync($valid);

        AuditLogger::log('hotel.whatsapp_recipients_updated', $hotel, [], ['recipient_ids' => $valid], hotelId: $hotel->id);

        return response()->json(['data' => ['count' => count($valid)]]);
    }

    /** Masque un numéro : ne montre que les 3 derniers chiffres. */
    private function mask(?string $number): string
    {
        $d = preg_replace('/\D+/', '', (string) $number);
        if (strlen($d) <= 3) {
            return $d;
        }

        return '••• ••• '.substr($d, -3);
    }
}
