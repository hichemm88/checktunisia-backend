<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Check-in
            'check_in.view', 'check_in.create', 'check_in.update', 'check_in.cancel',
            // Guests
            'guest.view', 'guest.create', 'guest.update', 'guest.delete',
            // Scans
            'scan.upload', 'scan.view',
            // Users
            'user.view', 'user.create', 'user.update', 'user.delete',
            // Rooms
            'room.view', 'room.create', 'room.update', 'room.delete',
            // Subscription
            'subscription.view',
            // Authority
            'authority.search', 'authority.view_guest', 'authority.view_hotel',
            // Admin
            'admin.hotel.manage', 'admin.subscription.manage', 'admin.audit.view',
            'admin.authority_user.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        // ── Roles ─────────────────────────────────────────────────────────

        $platformAdmin = Role::firstOrCreate(['name' => 'platform_admin', 'guard_name' => 'api']);
        $platformAdmin->syncPermissions(Permission::all());

        $hotelAdmin = Role::firstOrCreate(['name' => 'hotel_admin', 'guard_name' => 'api']);
        $hotelAdmin->syncPermissions([
            'check_in.view', 'check_in.create', 'check_in.update', 'check_in.cancel',
            'guest.view', 'guest.create', 'guest.update', 'guest.delete',
            'scan.upload', 'scan.view',
            'user.view', 'user.create', 'user.update', 'user.delete',
            'room.view', 'room.create', 'room.update', 'room.delete',
            'subscription.view',
        ]);

        $receptionist = Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'api']);
        $receptionist->syncPermissions([
            'check_in.view', 'check_in.create', 'check_in.update',
            'guest.view', 'guest.create', 'guest.update',
            'scan.upload', 'scan.view',
            'room.view',
        ]);

        $authority = Role::firstOrCreate(['name' => 'authority_user', 'guard_name' => 'api']);
        $authority->syncPermissions([
            'authority.search', 'authority.view_guest', 'authority.view_hotel',
        ]);

        $this->command->info('Roles and permissions seeded.');
    }
}
