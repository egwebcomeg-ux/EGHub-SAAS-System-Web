<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a ready-to-use restaurant: tenant (slug), owner account, 14-day Pro trial, default settings,
 * a main branch and its first tables. The menu itself is added by the owner (or imported).
 */
final class TenantProvisioner
{
    public const TRIAL_DAYS = 14;

    public const TRIAL_PLAN = 'pro';

    public const STARTER_TABLES = 5;

    /**
     * @param array{slug:string,name:string,owner_name:string,email:string,password:string,phone?:string,plan?:string,trial?:bool} $d
     */
    public function create(array $d): Tenant
    {
        return DB::transaction(function () use ($d) {
            $tenant = Tenant::query()->create(['id' => $d['slug'], 'name' => $d['name'], 'status' => 'active']);

            $trial = $d['trial'] ?? true;
            $plan = Plan::query()->where('code', $d['plan'] ?? ($trial ? self::TRIAL_PLAN : Entitlements::FALLBACK_PLAN))->firstOrFail();
            Subscription::query()->create([
                'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => $trial ? 'trialing' : 'active', 'cycle_months' => 1,
                'trial_ends_at' => $trial ? now()->addDays(self::TRIAL_DAYS) : null,
                'current_period_start' => now(),
                'current_period_end' => $trial ? now()->addDays(self::TRIAL_DAYS) : now()->addMonth(),
            ]);

            tenancy()->initialize($tenant);
            try {
                Settings::put(['brand_name' => $d['name'], 'phone' => $d['phone'] ?? '', 'whatsapp' => $d['phone'] ?? '']);

                $owner = User::query()->create([
                    'name' => $d['owner_name'], 'email' => strtolower($d['email']), 'phone' => $d['phone'] ?? null,
                    'password' => $d['password'], 'is_active' => true,
                ]);
                $owner->assignRole('owner');

                $branch = Branch::query()->create(['name' => 'الفرع الرئيسي', 'name_en' => 'Main branch', 'slug' => 'main', 'phone' => $d['phone'] ?? '']);
                for ($i = 1; $i <= self::STARTER_TABLES; $i++) {
                    DiningTable::query()->create(['branch_id' => $branch->id, 'name' => (string) $i, 'sort_order' => $i]);
                }
            } finally {
                tenancy()->end();
                Settings::flush();
            }
            Entitlements::flush();
            return $tenant;
        });
    }
}
