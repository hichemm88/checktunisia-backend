<?php

namespace Tests;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Rôles et permissions, semés avant chaque test QUI TOUCHE LA BASE.
     *
     * La condition manquait, alors que l'intention était déjà écrite ici. Le
     * seeder partait pour tous les tests, y compris ceux qui n'utilisent pas
     * RefreshDatabase et n'ont donc jamais fait tourner les migrations.
     *
     * Personne ne l'a vu tant que ces tests-là étendaient la classe de PHPUnit
     * plutôt que celle-ci. Le premier à faire autrement — le test unitaire du
     * détourage — a rendu la suite dépendante de l'ORDRE d'exécution et de
     * l'état préalable de la base :
     *
     *   - la suite « Unit » tourne AVANT « Feature » ;
     *   - dans « Unit », les tests sont alphabétiques, et le premier à utiliser
     *     RefreshDatabase (WatchlistServiceTest) vient après le cropper ;
     *   - donc sur une base VIERGE, le seeder s'exécute avant toute migration
     *     et échoue sur « relation permissions does not exist ».
     *
     * En local la base survit d'une exécution à l'autre : les tables sont déjà
     * là, le seeder passe, la suite est verte. En CI la base est neuve à chaque
     * fois — et rouge. C'est exactement le genre d'écart qui fait déclarer
     * « vert » de bonne foi une suite qui ne l'est pas.
     *
     * Le contrôle porte sur le trait plutôt que sur une liste de classes : il
     * reste juste pour tout test écrit demain, sans que personne ait à y penser.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            return;
        }

        // Vide le cache de permissions de Spatie, pour qu'un rôle enregistré au
        // cours d'un test ne déborde pas sur le suivant.
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->seed(RolesAndPermissionsSeeder::class);
    }
}
