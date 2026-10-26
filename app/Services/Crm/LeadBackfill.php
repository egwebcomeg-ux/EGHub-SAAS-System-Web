<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\DB;

/**
 * Makes a lead of every restaurant that has none (the CRM migration runs it once for the restaurants that signed up
 * before it; Tenant::created makes the lead of every new one). Query builder only, so the migration does not depend
 * on how the models look later. A restaurant that already paid a subscription invoice starts as won.
 */
final class LeadBackfill
{
    /** @return int how many leads were made */
    public function run(): int
    {
        $made = 0;
        DB::table('tenants')->whereNull('deleted_at')
            ->whereNotExists(fn ($q) => $q->from('crm_leads')->whereColumn('crm_leads.tenant_id', 'tenants.id'))
            ->select(['id', 'created_at'])
            ->chunkById(200, function ($tenants) use (&$made) {
                $paid = DB::table('invoices')->whereIn('tenant_id', $tenants->pluck('id'))->where('kind', 'subscription')
                    ->where('status', 'paid')->where('total', '>', 0)->groupBy('tenant_id')
                    ->selectRaw('tenant_id, min(paid_at) as first_paid')->pluck('first_paid', 'tenant_id');
                $now = now();
                $rows = $tenants->map(fn ($t) => [
                    'tenant_id' => $t->id, 'source' => 'signup',
                    'stage' => isset($paid[$t->id]) ? 'won' : 'new', 'won_at' => $paid[$t->id] ?? null,
                    'created_at' => $t->created_at ?? $now, 'updated_at' => $now,
                ])->all();
                DB::table('crm_leads')->insert($rows);
                $made += count($rows);
            }, 'id');

        return $made;
    }
}
