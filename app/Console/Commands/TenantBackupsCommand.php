<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Backups\TenantBackups;
use App\Services\Entitlements;
use App\Services\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The weekly per-restaurant backups: one zip for every active restaurant whose plan includes the `backups` feature,
 * then the retention (also for restaurants that lost the feature, so their old zips do not pile up). One restaurant
 * failing is logged and marked failed; the loop goes on.
 */
class TenantBackupsCommand extends Command
{
    protected $signature = 'egmenu:tenant-backups {--tenant= : only this restaurant (its slug)}';

    protected $description = 'Build the weekly backup zip of every active restaurant whose plan includes backups, and prune the old ones';

    public function handle(TenantBackups $backups): int
    {
        if (! Schema::hasTable('tenant_backups')) {
            $this->error('tenant_backups table missing: run php artisan migrate --force');

            return self::FAILURE;
        }

        $built = $failed = $skipped = $pruned = 0;
        $tenants = Tenant::query()->where('status', 'active')
            ->when($this->option('tenant'), fn ($q, $slug) => $q->whereKey($slug))->orderBy('id')->get();

        foreach ($tenants as $tenant) {
            try {
                tenancy()->initialize($tenant);
                if (Entitlements::for($tenant)->allows('backups')) {
                    $backup = $backups->runScheduled();
                    if ($backup->isReady()) {
                        $built++;
                        $this->line("  <fg=green>OK</>    {$tenant->id} ({$backup->sizeLabel()})");
                    } else {
                        $failed++;
                        $this->line("  <fg=red>FAIL</>  {$tenant->id} - {$backup->error}");
                    }
                } else {
                    $skipped++;
                }
                $pruned += $backups->prune();
            } catch (\Throwable $e) {
                $failed++;
                Log::error('tenant backup run failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
                $this->line("  <fg=red>FAIL</>  {$tenant->id} - {$e->getMessage()}");
            } finally {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
                Entitlements::flush();
                Settings::flush();
            }
        }

        cache()->forever(TenantBackups::LAST_RUN_KEY, now()->timestamp);
        $this->info("built: $built · failed: $failed · not in plan: $skipped · pruned: $pruned");

        return self::SUCCESS;
    }
}
