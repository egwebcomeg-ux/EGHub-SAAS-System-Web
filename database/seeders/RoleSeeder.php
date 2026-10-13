<?php

namespace Database\Seeders;

use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles are global definitions (owner, manager, cashier, kitchen, waiter); assignments belong to users, and every
 * user belongs to exactly one restaurant — so roles never leak across tenants.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (array_keys(Permissions::ALL) as $p) {
            Permission::findOrCreate($p, 'web');
        }
        foreach (array_keys(Permissions::ROLES) as $role) {
            Role::findOrCreate($role, 'web')->syncPermissions(Permissions::of($role));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
