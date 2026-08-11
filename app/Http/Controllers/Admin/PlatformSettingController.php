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
        // Les identifiants ne repartent jamais en clair vers le navigateur,
        // même pour un platform_admin — seulement leurs extrémités, pour que
        // l'écran puisse dire « une clé est enregistrée, et c'est celle-ci »
        // au lieu d'un champ vide indistinguable d'une absence.
        return response()->json(['data' => $s->toAdminArray()]);
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
            'konnect_enabled'      => ['sometimes', 'boolean'],
            'konnect_environment'  => ['sometimes', 'string', 'in:sandbox,production'],
            'konnect_api_key'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'konnect_wallet_id'    => ['sometimes', 'nullable', 'string', 'max:100'],
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
        if ($error = $this->environmentSwitchedWithoutCredentials($s, $v)) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'PAYMENT_CHANNEL_INCOMPLETE', 'message' => $error[0], 'field' => $error[1]]],
            ], 422);
        }

        if ($error = $this->incompleteChannel($s, $v)) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'PAYMENT_CHANNEL_INCOMPLETE', 'message' => $error[0], 'field' => $error[1]]],
            ], 422);
        }

        $s->update($v);

        // Same reasoning as show(): les identifiants de passerelle (Flouci
        // comme Konnect) ne repartent jamais en clair vers le navigateur.
        return response()->json(['data' => $s->fresh()->toAdminArray()]);
    }

    /**
     * Change-t-on d'environnement en gardant les identifiants de l'autre ?
     *
     * Simulation et production sont deux COMPTES distincts chez Konnect, avec
     * chacun leur clé et leur portefeuille. Or les champs d'identifiants sont
     * en écriture seule et se laissent vides pour « ne pas changer » : passer
     * la liste déroulante en production sans les ressaisir conservait donc la
     * clé de simulation, envoyée à l'API de production qui la rejette.
     *
     * Le symptôme est trompeur — « Service de paiement indisponible,
     * réessayez dans quelques instants » — et l'écran, lui, affiche fièrement
     * « Production ». Rien ne relie la panne à sa cause.
     *
     * Changer d'environnement sans fournir les identifiants correspondants
     * n'a aucun usage légitime : on le refuse plutôt que de laisser un canal
     * ouvert dont chaque règlement échoue.
     *
     * @param  array<string, mixed>  $changes
     * @return array{0: string, 1: string}|null  [message, champ fautif]
     */
    private function environmentSwitchedWithoutCredentials(PlatformSetting $current, array $changes): ?array
    {
        if (! array_key_exists('konnect_environment', $changes)) {
            return null;
        }

        if ($changes['konnect_environment'] === $current->konnect_environment) {
            return null;
        }

        if (filled($changes['konnect_api_key'] ?? null) && filled($changes['konnect_wallet_id'] ?? null)) {
            return null;
        }

        return [
            'Changer d\'environnement Konnect exige de ressaisir la clé d\'API ET l\'identifiant de portefeuille : '
            .'la simulation et la production sont deux comptes distincts, et les identifiants de l\'un sont rejetés par l\'autre.',
            'konnect_api_key',
        ];
    }

    /**
     * Le réglage RÉSULTANT ouvre-t-il un canal incomplet — et est-ce CETTE
     * requête qui l'a rendu tel ?
     *
     * On raisonne sur l'état après fusion, pas sur la seule requête : une
     * modification partielle (le taux de TVA, le nom de la banque) ne doit
     * pas exiger de renvoyer une configuration déjà en place. Fermer un canal
     * n'exige évidemment rien.
     *
     * ET on ne refuse pas un enregistrement pour un défaut qu'il n'introduit
     * pas. Le garde interdisait toute écriture tant qu'UN canal, quel qu'il
     * soit, était ouvert sans être praticable. Or l'écran envoie les trois
     * canaux à chaque enregistrement : la ligne de réglages livrée par défaut
     * — virement ouvert, bénéficiaire renseigné, mais NI IBAN NI RIB —
     * suffisait donc à verrouiller l'écran entier. L'exploitant qui venait
     * configurer sa passerelle de paiement voyait sa saisie refusée en
     * accusant un champ de virement auquel il n'avait pas touché, et rien ne
     * s'enregistrait jamais — pas même le virement qu'on lui demandait de
     * corriger, puisque le refus est global.
     *
     * Un écran de configuration ne doit pas être une prison : on empêche
     * d'OUVRIR un canal muet, on n'empêche pas de réparer quoi que ce soit.
     *
     * @param  array<string, mixed>  $changes
     * @return array{0: string, 1: string}|null  [message, champ fautif]
     */
    private function incompleteChannel(PlatformSetting $current, array $changes): ?array
    {
        $resulting = (clone $current)->fill($changes);

        $channels = [
            [
                fn (PlatformSetting $s) => $s->konnect_enabled && ! $s->konnectReady(),
                "Renseignez la clé d'API et l'identifiant de portefeuille Konnect avant d'ouvrir le paiement en ligne : sans eux, chaque règlement échouerait.",
                'konnect_api_key',
            ],
            [
                fn (PlatformSetting $s) => $s->flouci_enabled && ! $s->flouciReady(),
                "Renseignez l'App Token et l'App Secret Flouci avant d'ouvrir le paiement en ligne : sans eux, chaque règlement échouerait.",
                'flouci_app_token',
            ],
            [
                fn (PlatformSetting $s) => $s->virement_enabled && ! $s->virementReady(),
                'Renseignez le bénéficiaire et un IBAN ou RIB avant d\'ouvrir le virement : sans eux, le client ne sait pas où envoyer son paiement.',
                'virement_beneficiary',
            ],
        ];

        foreach ($channels as [$isBroken, $message, $field]) {
            // Sain à l'arrivée : rien à dire.
            if (! $isBroken($resulting)) {
                continue;
            }

            // Déjà dans cet état AVANT la requête : le défaut préexiste, cet
            // enregistrement ne l'introduit pas. Le bloquer n'y changerait
            // rien et empêcherait toute correction.
            if ($isBroken($current)) {
                continue;
            }

            return [$message, $field];
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
