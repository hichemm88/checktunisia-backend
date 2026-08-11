<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payment\PaymentGatewayResolver;
use App\Services\Payment\PaymentSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Rappel serveur de Konnect.
 *
 * C'est le gain net sur Flouci : le règlement est constaté même si le client
 * ferme son navigateur avant de revenir. Sans lui, une facture réglée reste
 * impayée, part en relance, et finit par suspendre un client à jour — la seule
 * chose qui l'évitait jusqu'ici était que le client prenne la peine de revenir.
 *
 * Konnect NE SIGNE PAS ses webhooks. Cet appel est donc un réveil, jamais une
 * preuve. Quatre couches en tiennent lieu :
 *
 *   1. le jeton de chemin, comparé en temps constant — sur le modèle de
 *      VerifyWhatsappWorker. Vide en configuration ⇒ route fermée ;
 *   2. la référence doit déjà exister chez nous, sinon 404 sans effet ;
 *   3. la VÉRITÉ vient d'un appel sortant vers Konnect — rien de ce que porte
 *      la requête entrante n'est cru ;
 *   4. un limiteur de débit dédié.
 *
 * La réponse est toujours 200 une fois le paiement reconnu, y compris sur un
 * rejeu : un code d'erreur déclencherait des réessais en boucle chez Konnect.
 */
class KonnectWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayResolver $gateways,
        private readonly PaymentSettlement $settlement,
    ) {}

    public function __invoke(Request $request, string $token): JsonResponse
    {
        $expected = (string) config('konnect.webhook_token', '');

        // Un 404 plutôt qu'un 401 : une route de webhook n'a pas à confirmer
        // son existence à qui ne connaît pas son jeton.
        if ($expected === '' || ! hash_equals($expected, $token)) {
            throw new NotFoundHttpException();
        }

        $reference = $this->reference($request);

        if ($reference === '') {
            Log::warning('Konnect webhook: aucune référence de paiement dans la requête', [
                'query' => $request->query(),
            ]);
            throw new NotFoundHttpException();
        }

        $payment = Payment::where('provider', 'konnect')
            ->where('provider_payment_id', $reference)
            ->first();

        if ($payment === null) {
            Log::warning('Konnect webhook: référence inconnue', ['payment_ref' => $reference]);
            throw new NotFoundHttpException();
        }

        // Déjà tranché — le retour navigateur est arrivé le premier. Rien à
        // rejouer, et surtout pas d'erreur : le webhook a fait son travail.
        if (in_array($payment->status, ['completed', 'failed', 'expired'], true)) {
            return response()->json(['data' => ['status' => $payment->status]]);
        }

        $gateway = $this->gateways->forPayment($payment);

        if ($gateway === null) {
            throw new NotFoundHttpException();
        }

        try {
            $result = $gateway->verifyPayment($payment->provider_payment_id);
        } catch (\RuntimeException $e) {
            // 502 : c'est le seul cas où l'on VEUT que Konnect réessaie, la
            // panne étant chez nous ou sur la route entre les deux.
            Log::error('Konnect webhook: vérification impossible', [
                'payment_ref' => $reference,
                'message'     => $e->getMessage(),
            ]);

            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'PAYMENT_GATEWAY_ERROR', 'message' => 'Verification unavailable.', 'field' => null]],
            ], 502);
        }

        // Aucun acteur : ce n'est pas un utilisateur qui encaisse, c'est le
        // prestataire qui prévient. L'idempotence est en base, elle ne dépend
        // ni du chemin ni du processus.
        $status = $this->settlement->apply($payment, $result);

        Log::info('Konnect webhook: paiement traité', [
            'payment_ref' => $reference,
            'status'      => $status,
        ]);

        return response()->json(['data' => ['status' => $status]]);
    }

    /**
     * Référence portée par le rappel.
     *
     * Konnect documente `payment_ref` en paramètre de requête. On accepte
     * aussi la forme camel et le corps JSON : trois lignes qui évitent qu'un
     * détail de nommage fasse échouer silencieusement tous les encaissements.
     * Le nom retenu n'a aucune conséquence sur la sécurité — la référence est
     * ensuite confrontée à notre base puis à Konnect lui-même.
     */
    private function reference(Request $request): string
    {
        foreach (['payment_ref', 'paymentRef', 'payment_id', 'paymentId'] as $key) {
            $value = $request->input($key) ?? $request->query($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }
}
