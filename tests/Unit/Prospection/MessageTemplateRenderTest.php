<?php

namespace Tests\Unit\Prospection;

use App\Models\Prospection\MessageTemplate;
use PHPUnit\Framework\TestCase;

class MessageTemplateRenderTest extends TestCase
{
    public function test_it_replaces_known_variables(): void
    {
        $template = new MessageTemplate([
            'body' => 'Salut {prenom}, contacte {etablissement}.',
        ]);

        $rendered = $template->render(['prenom' => 'Amine', 'etablissement' => "Dar L'Ktifa"]);

        $this->assertSame("Salut Amine, contacte Dar L'Ktifa.", $rendered);
    }

    public function test_it_leaves_unknown_variables_untouched_rather_than_stripping_them(): void
    {
        $template = new MessageTemplate(['body' => 'Salut {prenomm}, ça va ?']);

        $rendered = $template->render(['prenom' => 'Amine']);

        $this->assertSame('Salut {prenomm}, ça va ?', $rendered);
    }

    public function test_accents_and_apostrophes_survive_rendering(): void
    {
        $template = new MessageTemplate([
            'body' => "Bonjour {prenom}, l'établissement {etablissement} vous intéresse ?",
        ]);

        $rendered = $template->render([
            'prenom' => 'Zoé',
            'etablissement' => "L'Étoile d'Été",
        ]);

        $this->assertSame(
            "Bonjour Zoé, l'établissement L'Étoile d'Été vous intéresse ?",
            $rendered,
        );
    }
}
