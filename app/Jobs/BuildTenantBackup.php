<?php

namespace App\Jobs;

use App\Models\TenantBackup;
use App\Services\Backups\TenantBackups;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Builds a manual restaurant backup in the background (the owner pressed "Create a backup now"). Tenant context is
 * restored by stancl's QueueTenancyBootstrapper, so the row is found inside its own restaurant only.
 */
class BuildTenantBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $backupId) {}

    public function handle(TenantBackups $backups): void
    {
        $backup = TenantBackup::query()->find($this->backupId);
        if (! $backup || $backup->status !== TenantBackup::STATUS_BUILDING) {
            return;
        }
        $backups->build($backup);
    }
}
