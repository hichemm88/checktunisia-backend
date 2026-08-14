<?php

namespace Tests\Unit;

use App\Support\WebauthnOrigins;
use PHPUnit\Framework\TestCase;

/**
 * Ces tests protègent une panne constatée en production : une passkey créée
 * sur www.qayed.tn était refusée à la vérification parce que la liste déduite
 * ne contenait que l'apex. L'utilisateur voyait « cette passkey n'a pas pu être
 * vérifiée » sans qu'aucune action de sa part n'y change quoi que ce soit.
 */
class WebauthnOriginsTest extends TestCase
{
    // ── Déduction depuis FRONTEND_URL ────────────────────────────────────────

    public function test_the_www_twin_is_accepted_alongside_the_apex(): void
    {
        $this->assertSame(
            ['https://qayed.tn', 'https://www.qayed.tn'],
            WebauthnOrigins::resolve(null, 'https://qayed.tn'),
        );
    }

    public function test_the_apex_is_accepted_alongside_the_www_form(): void
    {
        $this->assertSame(
            ['https://www.qayed.tn', 'https://qayed.tn'],
            WebauthnOrigins::resolve(null, 'https://www.qayed.tn'),
        );
    }

    public function test_the_path_of_the_frontend_url_is_dropped(): void
    {
        // Une origine WebAuthn n'a jamais de chemin : la laisser produirait une
        // entrée qui ne correspondrait à aucune réponse de navigateur.
        $this->assertSame(
            ['https://qayed.tn', 'https://www.qayed.tn'],
            WebauthnOrigins::resolve(null, 'https://qayed.tn/portail/connexion'),
        );
    }

    public function test_the_port_is_preserved(): void
    {
        $this->assertSame(['http://localhost:5173'], WebauthnOrigins::resolve(null, 'http://localhost:5173'));
    }

    public function test_no_twin_is_invented_for_a_host_that_has_none(): void
    {
        // localhost, adresse IP, sous-domaine déjà spécifique : « www. » n'y
        // désignerait aucune adresse réelle.
        $this->assertSame(['http://localhost'], WebauthnOrigins::resolve(null, 'http://localhost'));
        $this->assertSame(['http://127.0.0.1:8000'], WebauthnOrigins::resolve(null, 'http://127.0.0.1:8000'));
        $this->assertSame(['https://portail.qayed.tn'], WebauthnOrigins::resolve(null, 'https://portail.qayed.tn'));
    }

    public function test_an_unusable_frontend_url_yields_no_origin(): void
    {
        // Mieux vaut une liste vide — la bibliothèque retombe alors sur sa
        // vérification par RP ID — qu'une entrée bancale acceptée par erreur.
        $this->assertSame([], WebauthnOrigins::resolve(null, ''));
        $this->assertSame([], WebauthnOrigins::resolve(null, 'qayed.tn'));
    }

    // ── Valeur explicite ─────────────────────────────────────────────────────

    public function test_an_explicit_list_is_taken_exactly_as_written(): void
    {
        // Une liste écrite à la main est une décision : on n'y ajoute rien.
        $this->assertSame(
            ['https://qayed.tn', 'https://admin.qayed.tn'],
            WebauthnOrigins::resolve(' https://qayed.tn , https://admin.qayed.tn ', 'https://autre.example'),
        );
    }

    public function test_an_empty_explicit_value_falls_back(): void
    {
        $this->assertSame(
            ['https://qayed.tn', 'https://www.qayed.tn'],
            WebauthnOrigins::resolve('   ', 'https://qayed.tn'),
        );
    }

    // ── RP ID ────────────────────────────────────────────────────────────────

    public function test_the_rp_id_drops_the_www_prefix(): void
    {
        // Une passkey créée pour `qayed.tn` vaut sur `www.qayed.tn` ; l'inverse
        // est faux. En cas de doute, l'apex est donc le seul choix sûr.
        $this->assertSame('qayed.tn', WebauthnOrigins::resolveRpId(null, 'https://www.qayed.tn'));
        $this->assertSame('qayed.tn', WebauthnOrigins::resolveRpId(null, 'https://qayed.tn'));
    }

    public function test_an_explicit_rp_id_wins(): void
    {
        $this->assertSame('qayed.tn', WebauthnOrigins::resolveRpId('qayed.tn', 'https://preprod.example'));
    }

    public function test_localhost_stays_localhost(): void
    {
        $this->assertSame('localhost', WebauthnOrigins::resolveRpId(null, 'http://localhost:5173'));
    }
}
