<?php

namespace App\Http\Controllers\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappSendLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * `GET /f/{token}` — cible stable du bouton « Consulter la fiche ».
 *
 * L'URL de base d'un bouton de modèle WhatsApp est FIGÉE à l'approbation
 * Meta. Y graver une route applicative (`/authority/guests/{id}`) aurait
 * signifié qu'aucune évolution de l'interface n'est possible sans soumettre
 * un nouveau modèle et attendre une nouvelle validation — pour une route qui,
 * elle, va changer : une page « fiche » consultable par jeton signé, sans
 * compte, est le besoin réel des destinataires.
 *
 * Cette route est donc une indirection délibérée. Elle ne fait rien d'autre
 * que rediriger, et c'est précisément ce qui la rend stable.
 *
 * Ce qu'elle ne fait PAS, et pourquoi :
 *
 *  - elle n'AUTORISE rien. Le jeton dit « voici de quelle fiche il s'agit »,
 *    pas « vous avez le droit de la voir ». La destination exige une session
 *    du portail autorité. Un jeton qui donnerait accès au contenu serait un
 *    lien vers des données personnelles envoyé en clair par WhatsApp, sans
 *    expiration ni révocation ;
 *  - elle n'affiche aucune donnée, même en cas d'erreur : un jeton inconnu
 *    donne un 404 nu, sans dire si la fiche a existé.
 */
class FicheLinkController extends Controller
{
    public function __invoke(string $token): RedirectResponse|Response
    {
        $job = WhatsappSendLog::where('public_token', $token)->first();

        if ($job === null) {
            return response('', 404);
        }

        $frontend = rtrim((string) env('FRONTEND_URL', 'https://qayed.tn'), '/');

        // Sans voyageur rattaché (fiche de test, ou voyageur supprimé depuis
        // l'envoi), le lien reste valide mais retombe sur le tableau de bord :
        // mieux vaut une page utile qu'une erreur chez un policier.
        $path = filled($job->guest_id)
            ? '/authority/guests/'.$job->guest_id
            : '/authority/dashboard';

        // 302 et non 301 : la destination CHANGERA (page fiche par jeton
        // signé). Un 301 serait mis en cache par les navigateurs et les
        // clients WhatsApp, et survivrait au changement.
        return redirect()->away($frontend.$path, 302);
    }
}
