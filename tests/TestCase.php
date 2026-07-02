<?php

namespace Tests;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed roles + permissions before every test that uses RefreshDatabase.
     * Spatie permission uses a cache — flush it between tests too.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Clear Spatie permission cache so roles registered mid-test don't
        // bleed across test isolation boundaries.
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->seed(RolesAndPermissionsSeeder::class);
    }
}
