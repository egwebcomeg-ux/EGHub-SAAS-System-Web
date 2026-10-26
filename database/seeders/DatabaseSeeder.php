<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Platform bootstrap data. Restaurants are created by signup or the super admin (TenantProvisioner). */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PlanSeeder::class, RoleSeeder::class, CallScriptSeeder::class]);

        if (app()->isLocal()) {
            $this->call(DemoSeeder::class);
        }
    }
}
