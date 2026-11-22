<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiPartner;
use App\Services\PartnerApi\PartnerTestDataPurgeService;
use App\Services\PartnerApi\PurgeConfirmationMismatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Admin → Partenaires API → détail → « Purger les données de test ».
 *
 * Deux temps, sur le même endpoint :
 *  1. `dry_run: true` → aperçu, aucun effet : comptes par type + jeton de
 *     confirmation lié à ces comptes exacts ;
 *  2. sans dry_run → exige ce jeton. Absent : 422. Ne correspond plus aux
 *     comptes du moment (jeton erroné, ou données de test apparues/disparues
 *     depuis l'aperçu) : 409 avec les comptes à jour, rien n'est supprimé.
 *
 * La règle de sélection vit dans PartnerTestDataPurgeService (partagée avec
 * la commande partner:purge-test-data).
 */
class PartnerTestDataPurgeController extends Controller
{
    public function __construct(private PartnerTestDataPurgeService $purge) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $partner = ApiPartner::findOrFail($id);

        $v = $request->validate([
            'dry_run' => ['sometimes', 'boolean'],
            'confirmation_token' => ['nullable', 'string', 'max:128'],
        ]);

        if ($request->boolean('dry_run')) {
            $counts = $this->purge->preview($partner);

            return response()->json(['data' => $this->payload($counts) + [
                'confirmation_token' => $this->purge->confirmationToken($partner, $counts),
            ]]);
        }

        if (empty($v['confirmation_token'])) {
            throw ValidationException::withMessages([
                'confirmation_token' => "Confirmation requise : affichez d'abord l'aperçu (dry_run) puis renvoyez son jeton.",
            ]);
        }

        try {
            $done = $this->purge->purge($partner, $request->user(), 'admin', (string) $v['confirmation_token']);
        } catch (PurgeConfirmationMismatch $e) {
            return response()->json([
                'data' => $this->payload($e->currentCounts) + [
                    'confirmation_token' => $this->purge->confirmationToken($partner, $e->currentCounts),
                ],
                'errors' => [[
                    'code' => 'PURGE_CONFIRMATION_MISMATCH',
                    'message' => 'La confirmation ne correspond pas aux données actuelles. Vérifiez les nouveaux comptes puis confirmez à nouveau.',
                    'field' => 'confirmation_token',
                ]],
            ], 409);
        }

        return response()->json(['data' => $this->payload($done) + ['purged' => true]]);
    }

    /** @param  array<string,int>  $counts */
    private function payload(array $counts): array
    {
        return [
            'counts' => $counts,
            'total' => PartnerTestDataPurgeService::deletableTotal($counts),
        ];
    }
}
