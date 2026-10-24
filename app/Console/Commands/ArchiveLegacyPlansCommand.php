<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Services\Entitlements;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Command;

/**
 * Archives the older plans (PlanSeeder::LEGACY: starter, basic, pro, business) so only Free, Complete and
 * Enterprise are ever offered or assigned. Refuses the whole run, changing nothing, while any of them still has a
 * live subscription or an open invoice (and names them). Already archived plans are skipped, so it is safe to re-run.
 */
class ArchiveLegacyPlansCommand extends Command
{
    protected $signature = 'egmenu:archive-legacy-plans {--dry-run : Only report what would be archived}';

    protected $description = 'Archive the legacy plans (starter, basic, pro, business) once nobody uses them';

    public function handle(): int
    {
        $plans = Plan::query()->whereIn('code', PlanSeeder::LEGACY)->orderBy('sort_order')->get();
        $toArchive = [];
        $blocked = false;

        foreach ($plans as $plan) {
            if ($plan->isArchived()) {
                $this->line("  {$plan->code}: already archived ({$plan->archived_at->toDateString()})");

                continue;
            }
            $blockers = $plan->archiveBlockers();
            if ($blockers['tenants']->isNotEmpty() || $blockers['open_invoices'] > 0) {
                $blocked = true;
                $this->error("  {$plan->code}: still in use: restaurants [".$blockers['tenants']->join(', ')."], open invoices {$blockers['open_invoices']}");

                continue;
            }
            $toArchive[] = $plan;
            $this->line("  {$plan->code}: no live subscription or open invoice, ".($this->option('dry-run') ? 'would be archived' : 'archiving'));
        }

        if ($blocked) {
            $this->error('Nothing archived: move those restaurants to another plan (or settle the invoices) and run again.');

            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->info('Dry run: '.count($toArchive).' plan(s) would be archived. Nothing changed.');

            return self::SUCCESS;
        }
        foreach ($toArchive as $plan) {
            $plan->archive();
            PlatformAuditLog::record(null, 'plan.archived', $plan->code, ['via' => 'egmenu:archive-legacy-plans']);
        }
        Entitlements::flush();
        $this->info(count($toArchive).' plan(s) archived.');

        return self::SUCCESS;
    }
}
