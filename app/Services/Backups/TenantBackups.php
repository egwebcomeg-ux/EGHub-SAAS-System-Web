<?php

namespace App\Services\Backups;

use App\Jobs\BuildTenantBackup;
use App\Models\TenantBackup;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The life of a restaurant's backups (rows of tenant_backups, zips on the private disk), inside the tenant context:
 * start one (manual from the dashboard: queued; weekly from egmenu:tenant-backups: built in place), build it, prune
 * the old ones. The zip itself is written by TenantBackupBuilder.
 *
 * Retention: the last `keep_scheduled` weekly backups stay; a manual one is deleted `keep_manual_days` after it was
 * made (its expires_at). A failed row goes after a day, a row still "building" after a day is marked failed.
 */
final class TenantBackups
{
    /** Cache key the weekly command stamps when a run finished (read by egmenu:doctor). */
    public const LAST_RUN_KEY = 'egmenu:tenant_backups_at';

    public function __construct(private TenantBackupBuilder $builder) {}

    /**
     * Why a manual backup cannot start right now (one is still building, or the last one is too recent); null when
     * it may.
     */
    public function manualBlockedReason(): ?string
    {
        if (TenantBackup::query()->where('status', TenantBackup::STATUS_BUILDING)->where('created_at', '>=', now()->subDay())->exists()) {
            return __('A backup is being prepared right now. Wait for it to finish.');
        }
        $every = max(1, (int) config('backup.tenant.manual_every_minutes'));
        $last = TenantBackup::query()->where('kind', TenantBackup::KIND_MANUAL)->where('created_at', '>=', now()->subMinutes($every))->latest('id')->first();
        if ($last) {
            $wait = max(1, (int) ceil(now()->diffInSeconds($last->created_at->addMinutes($every), true) / 60));

            return __('You can take one backup per :minutes minutes. Try again in :wait minutes.', ['minutes' => $every, 'wait' => $wait]);
        }

        return null;
    }

    /** A manual backup asked for from the dashboard: the row now, the zip from the queue (in place when the queue is sync). */
    public function startManual(User $by): TenantBackup
    {
        $backup = $this->row(TenantBackup::KIND_MANUAL, $by->id);
        BuildTenantBackup::dispatch($backup->id);

        return $backup->fresh();
    }

    /** A weekly backup: built right away by the command. */
    public function runScheduled(): TenantBackup
    {
        $backup = $this->row(TenantBackup::KIND_SCHEDULED, null);
        $this->build($backup);

        return $backup;
    }

    /** Builds the zip and marks the row ready, or failed with the reason (never throws: the caller moves on). */
    public function build(TenantBackup $backup): void
    {
        try {
            $summary = $this->builder->build($backup);
            $backup->update(['status' => TenantBackup::STATUS_READY, 'size' => $summary['size'], 'summary' => $summary, 'error' => null]);
        } catch (\Throwable $e) {
            Log::error('tenant backup failed', ['tenant' => $backup->tenant_id, 'backup' => $backup->id, 'error' => $e->getMessage()]);
            Storage::disk('local')->delete($backup->storagePath());
            $backup->update(['status' => TenantBackup::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    /** Removes the zip and the row. */
    public function delete(TenantBackup $backup): void
    {
        Storage::disk('local')->delete($backup->storagePath());
        $backup->delete();
    }

    /** Applies the retention to the current restaurant and returns how many backups went. */
    public function prune(): int
    {
        $gone = 0;
        $dayAgo = now()->subDay();

        $expired = TenantBackup::query()->where(fn ($q) => $q->where('expires_at', '<', now())
            ->orWhere(fn ($q) => $q->where('status', TenantBackup::STATUS_FAILED)->where('created_at', '<', $dayAgo)))->get();
        $keep = max(1, (int) config('backup.tenant.keep_scheduled'));
        $surplus = TenantBackup::query()->where('kind', TenantBackup::KIND_SCHEDULED)->where('status', TenantBackup::STATUS_READY)
            ->latest('id')->skip($keep)->take(1000)->get();

        foreach ($expired->concat($surplus)->unique('id') as $backup) {
            $this->delete($backup);
            $gone++;
        }

        // a build that never finished (worker killed): shown as failed, and removed by the next prune
        TenantBackup::query()->where('status', TenantBackup::STATUS_BUILDING)->where('created_at', '<', $dayAgo)
            ->update(['status' => TenantBackup::STATUS_FAILED, 'error' => 'timed out']);

        return $gone;
    }

    private function row(string $kind, ?int $userId): TenantBackup
    {
        $keepDays = max(1, (int) config('backup.tenant.keep_manual_days'));

        return TenantBackup::query()->create([
            'filename' => now()->format('Y-m-d-His').'-'.$kind.'.zip',
            'kind' => $kind,
            'status' => TenantBackup::STATUS_BUILDING,
            'created_by' => $userId,
            'expires_at' => $kind === TenantBackup::KIND_MANUAL ? now()->addDays($keepDays) : null,
        ]);
    }
}
