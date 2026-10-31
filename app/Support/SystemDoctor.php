<?php

namespace App\Support;

use App\Services\Backups\TenantBackups;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The post-deploy checklist, shared by `egmenu:doctor` (App\Console\Commands\DoctorCommand) and the admin's
 * «صحة النظام» page. A failed check without `warn` is a real problem; a warning is something to look at. Hints never
 * carry a secret (keys and passwords are only checked for being set).
 */
final class SystemDoctor
{
    /** @return list<array{label: string, ok: bool, hint: string, warn: bool}> */
    public function checks(): array
    {
        $checks = [];
        $check = function (string $label, bool $ok, string $hint = '', bool $warn = false) use (&$checks): void {
            $checks[] = ['label' => $label, 'ok' => $ok, 'hint' => $hint, 'warn' => $warn];
        };

        $check('APP_KEY set', filled(config('app.key')));
        $check('APP_DEBUG is false', ! config('app.debug'), 'set APP_DEBUG=false in production', warn: ! app()->isProduction());
        $check('APP_ENV is production', app()->isProduction(), 'APP_ENV='.config('app.env'), warn: true);

        $dbOk = false;
        try {
            DB::connection()->getPdo();
            $dbOk = true;
        } catch (\Throwable $e) {
            $check('Database connection', false, $e->getMessage());
        }
        if ($dbOk) {
            $check('Database connection', true, config('database.default'));
            $check('Migrations ran (plans table)', Schema::hasTable('plans'), 'run php artisan migrate --force');
            foreach (['pos_terminals', 'driver_locations', 'corporate_vouchers', 'kb_articles'] as $table) {
                $check("Migrations ran ($table table)", Schema::hasTable($table), 'run php artisan migrate --force');
            }
            $check('Queue table reachable', $this->queueReachable(), 'run php artisan migrate --force (the jobs table is missing)');
            $check('Migrations ran (tenant_backups table)', Schema::hasTable('tenant_backups'), 'run php artisan migrate --force (the restaurant backups need it)', warn: true);
            $check('Roles seeded (driver role)', $this->roleExists('driver'), 'run php artisan db:seed --class=RoleSeeder --force');
            $missing = $this->missingPermissions();
            $check('Permissions seeded ('.count(Permissions::ALL).' keys)', $missing === [],
                $missing === [] ? '' : 'missing '.implode(', ', $missing).': run php artisan db:seed --class=RoleSeeder --force');
        }

        $check('Google sign-in configured', filled(config('services.google.client_id')), 'GOOGLE_CLIENT_ID is empty: the Google button stays hidden', warn: true);

        $link = public_path('storage');
        $check('storage link exists', is_link($link) || is_dir($link), 'run php artisan storage:link');
        $check('storage/app/public writable', is_writable(storage_path('app/public')));
        $check('storage/logs writable', is_writable(storage_path('logs')));

        $mailer = config('mail.default');
        $check('Mail configured (not log)', ! in_array($mailer, ['log', 'array'], true), "MAIL_MAILER=$mailer");

        $check('Scheduler ran in the last 10 minutes', $this->schedulerFresh(), 'is the cron line installed?', warn: true);

        $last = $this->lastBackupAt();
        $check('Backup within 36 hours', $last && $last > now()->subHours(36)->timestamp,
            $last ? 'last '.now()->setTimestamp($last)->diffForHumans() : 'no backup recorded yet', warn: true);

        $lastTenants = $this->lastTenantBackupsAt();
        $check('Restaurant backups ran within 8 days', $lastTenants && $lastTenants > now()->subDays(8)->timestamp,
            $lastTenants ? 'last '.now()->setTimestamp($lastTenants)->diffForHumans() : 'no weekly run recorded yet (egmenu:tenant-backups, Sunday 03:30)', warn: true);

        return $checks;
    }

    /** Unix time of the last platform backup (egmenu:backup), null when none was recorded. */
    public function lastBackupAt(): ?int
    {
        $at = cache()->get('egmenu:last_backup_at');

        return $at ? (int) $at : null;
    }

    /** Unix time of the last weekly restaurant backups run (egmenu:tenant-backups), null when none was recorded. */
    public function lastTenantBackupsAt(): ?int
    {
        $at = cache()->get(TenantBackups::LAST_RUN_KEY);

        return $at ? (int) $at : null;
    }

    /** Unix time the scheduler last ran (touched every minute, see routes/console.php), null when never. */
    public function schedulerAt(): ?int
    {
        $at = cache()->get('egmenu:scheduler_at');

        return $at ? (int) $at : null;
    }

    private function queueReachable(): bool
    {
        try {
            if (config('queue.default') !== 'database') {
                return true;
            }
            DB::table(config('queue.connections.database.table', 'jobs'))->count();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function roleExists(string $role): bool
    {
        try {
            return Schema::hasTable('roles') && DB::table('roles')->where('name', $role)->where('guard_name', 'web')->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<string> permission keys of App\Support\Permissions::ALL missing from the permissions table */
    private function missingPermissions(): array
    {
        try {
            if (! Schema::hasTable('permissions')) {
                return array_keys(Permissions::ALL);
            }
            $have = DB::table('permissions')->where('guard_name', 'web')->pluck('name')->all();

            return array_values(array_diff(array_keys(Permissions::ALL), $have));
        } catch (\Throwable) {
            return array_keys(Permissions::ALL);
        }
    }

    private function schedulerFresh(): bool
    {
        $at = $this->schedulerAt();

        return $at && $at > now()->subMinutes(10)->timestamp;
    }
}
