<?php

namespace Database\Seeders;

use App\Models\Prospection\MessageTemplate;
use Illuminate\Database\Seeder;

/**
 * Modèles de message initiaux, tutoiement (contact déjà connu) et
 * vouvoiement (contact plus formel). Éditables ensuite depuis l'app.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * RÈGLE MÉTIER — À NE JAMAIS ENFREINDRE DANS UN TEMPLATE DE PROSPECTION :
 *
 * Aucun message ne doit mentionner la transmission automatique des fiches
 * aux agents ou aux autorités. Qayed archive et permet d'exporter le
 * registre ; la retransmission reste un geste de l'exploitant. Le dire
 * autrement en prospection créerait une attente commerciale que le produit
 * ne tient pas, et risquerait de laisser croire à un engagement réglementaire
 * pris à la légère.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * firstOrCreate par nom : rejouable sans écraser une modification faite
 * depuis l'app.
 */
class ProspectionMessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        MessageTemplate::firstOrCreate(
            ['name' => 'Maison d\'hôtes — contact tiède (tutoiement)'],
            [
                'body' => "Salut {prenom}, j'espère que tu vas bien. Je te contacte parce qu'on a développé un outil qui nous simplifie pas mal les fiches de police : à l'arrivée du voyageur, ta réceptionniste scanne sa CIN ou son passeport avec son téléphone, directement dans le navigateur, rien à installer. La fiche est générée et archivée automatiquement, et tu peux imprimer ou exporter ton registre complet à tout moment en cas de contrôle. Ça s'appelle Qayed — on l'utilise tous les jours dans nos maisons d'hôtes. Si ça te dit, je passe te le montrer, 15 minutes suffisent — je suis à deux pas.",
                'segment' => 'maison_hotes',
                'active' => true,
            ],
        );

        MessageTemplate::firstOrCreate(
            ['name' => 'Maison d\'hôtes — contact tiède (vouvoiement)'],
            [
                'body' => "Bonjour {prenom}, j'espère que vous allez bien. Je vous contacte parce qu'on a développé un outil qui nous simplifie pas mal les fiches de police : à l'arrivée du voyageur, votre réceptionniste scanne sa CIN ou son passeport avec son téléphone, directement dans le navigateur, rien à installer. La fiche est générée et archivée automatiquement, et vous pouvez imprimer ou exporter votre registre complet à tout moment en cas de contrôle. Ça s'appelle Qayed — nous l'utilisons tous les jours dans nos maisons d'hôtes. Si cela vous intéresse, je passe volontiers vous le montrer, 15 minutes suffisent — je suis à deux pas.",
                'segment' => 'maison_hotes',
                'active' => true,
            ],
        );
    }
}
