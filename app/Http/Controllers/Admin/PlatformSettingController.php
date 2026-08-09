<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformSettingController extends Controller
{
    // ── Platform settings ────────────────────────────────────────────────────

    public function show(): JsonResponse
    {
        $s = PlatformSetting::get();
        // toPublicArray() hides flouci_app_token/flouci_app_secret — never round-trip API
        // credentials to the browser, even for platform_admin.
        return response()->json(['data' => $s->toPublicArray()]);
    }

    public function update(Request $request): JsonResponse
    {
        $v = $request->validate([
            'company_name'         => ['sometimes', 'nullable', 'string', 'max:150'],
            'company_mf'           => ['sometimes', 'nullable', 'string', 'max:50'],
            'company_rc'           => ['sometimes', 'nullable', 'string', 'max:50'],
            'company_address'      => ['sometimes', 'nullable', 'string'],
            'flouci_enabled'       => ['sometimes', 'boolean'],
            'flouci_app_token'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'flouci_app_secret'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'virement_enabled'     => ['sometimes', 'boolean'],
            'virement_rib'         => ['sometimes', 'nullable', 'string', 'max:50'],
            'virement_iban'        => ['sometimes', 'nullable', 'string', 'max:34'],
            'virement_bank_name'   => ['sometimes', 'nullable', 'string', 'max:100'],
            'virement_beneficiary' => ['sometimes', 'nullable', 'string', 'max:150'],
            'virement_details'     => ['sometimes', 'nullable', 'string'],
            'tax_rate'             => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'timbre_fiscal'        => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ]);

        $s = PlatformSetting::get();

        // On n'ouvre pas un canal de règlement qu'on n'a pas configuré.
        //
        // Le drapeau `*_enabled` était acceptable seul : on pouvait annoncer
        // le paiement en ligne sans identifiants (chaque règlement échouait),
        // ou le virement sans bénéficiaire ni compte (le client recevait un
        // formulaire de déclaration sans savoir où envoyer l'argent). Le
        // virement étant le seul canal ouvert en production, ce n'est pas une
        // question d'ergonomie : c'est un client qui ne peut pas payer.
        if ($error = $this->incompleteChannel($s, $v)) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'PAYMENT_CHANNEL_INCOMPLETE', 'message' => $error[0], 'field' => $error[1]]],
            ], 422);
        }

        $s->update($v);

        // Same reasoning as show(): never round-trip flouci_app_token/flouci_app_secret.
        return response()->json(['data' => $s->fresh()->toPublicArray()]);
    }

    /**
     * Le réglage RÉSULTANT ouvre-t-il un canal incomplet ?
     *
     * On raisonne sur l'état après fusion, pas sur la seule requête : une
     * modification partielle (le taux de TVA, le nom de la banque) ne doit
     * pas exiger de renvoyer une configuration déjà en place. Fermer un canal
     * n'exige évidemment rien.
     *
     * @param  array<string, mixed>  $changes
     * @return array{0: string, 1: string}|null  [message, champ fautif]
     */
    private function incompleteChannel(PlatformSetting $current, array $changes): ?array
    {
        $resulting = (clone $current)->fill($changes);

        if ($resulting->flouci_enabled && ! $resulting->flouciReady()) {
            return [
                "Renseignez l'App Token et l'App Secret Flouci avant d'ouvrir le paiement en ligne : sans eux, chaque règlement échouerait.",
                'flouci_app_token',
            ];
        }

        if ($resulting->virement_enabled && ! $resulting->virementReady()) {
            return [
                'Renseignez le bénéficiaire et un IBAN ou RIB avant d\'ouvrir le virement : sans eux, le client ne sait pas où envoyer son paiement.',
                'virement_beneficiary',
            ];
        }

        return null;
    }

    // ── Payments (read-only ledger) ──────────────────────────────────────────

    public function payments(Request $request): JsonResponse
    {
        $query = \App\Models\Payment::with(['hotel', 'invoice.subscription.organization'])->orderByDesc('created_at');

        if ($request->filled('status'))   $query->where('status', $request->status);
        if ($request->filled('provider')) $query->where('provider', $request->provider);
        if ($request->filled('hotel_id')) $query->where('hotel_id', $request->hotel_id);

        $payments = $query->paginate($request->integer('per_page', 30));

        return response()->json([
            'data' => $payments->map(fn($p) => [
                'id'                 => $p->id,
                'provider'           => $p->provider,
                'status'             => $p->status,
                'amount'             => $p->amount,
                'currency'           => $p->currency,
                'hotel_name'         => $p->hotel?->name ?? $p->invoice?->subscription?->organization?->name,
                'invoice_number'     => $p->invoice?->invoice_number,
                'declared_reference' => $p->declared_reference,
                'declared_at'        => $p->declared_at,
                'completed_at'       => $p->completed_at,
                'created_at'         => $p->created_at,
            ]),
            'meta' => ['total' => $payments->total(), 'current_page' => $payments->currentPage(), 'per_page' => $payments->perPage()],
        ]);
    }
}
