<?php

namespace App\Console\Commands;

use App\Services\Backups\TenantBackups;
use App\Support\Permissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Post-deploy checklist. Exit code 1 when any check fails; warnings do not fail. */
class DoctorCommand extends Command
{
    protected $signature = 'egmenu:doctor';

    protected $description = 'Check the things a human needs to verify after a deploy';

    private int $failures = 0;

    public function handle(): int
    {
        $this->failures = 0; // the command instance is reused when called twice in one process (tests)
        $this->check('APP_KEY set', filled(config('app.key')));
        $this->check('APP_DEBUG is false', ! config('app.debug'), 'set APP_DEBUG=false in production', warn: ! app()->isProduction());
        $this->check('APP_ENV is production', app()->isProduction(), 'APP_ENV='.config('app.env'), warn: true);

        $dbOk = false;
        try {
            DB::connection()->getPdo();
            $dbOk = true;
        } catch (\Throwable $e) {
            $this->check('Database connection', false, $e->getMessage());
        }
        if ($dbOk) {
            $this->check('Database connection', true, config('database.default'));
            $this->check('Migrations ran (plans table)', Schema::hasTable('plans'), 'run php artisan migrate --force');
            foreach (['pos_terminals', 'driver_locations', 'corporate_vouchers', 'kb_articles'] as $table) {
                $this->check("Migrations ran ($table table)", Schema::hasTable($table), 'run php artisan migrate --force');
            }
            $this->check('Queue table reachable', $this->queueReachable(), 'run php artisan migrate --force (the jobs table is missing)');
            $this->check('Migrations ran (tenant_backups table)', Schema::hasTable('tenant_backups'), 'run php artisan migrate --force (the restaurant backups need it)', warn: true);
            $this->check('Roles seeded (driver role)', $this->roleExists('driver'), 'run php artisan db:seed --class=RoleSeeder --force');
            $missing = $this->missingPermissions();
            $this->check('Permissions seeded ('.count(Permissions::ALL).' keys)', $missing === [],
                $missing === [] ? '' : 'missing '.implode(', ', $missing).': run php artisan db:seed --class=RoleSeeder --force');
        }

        $this->check('Google sign-in configured', filled(config('services.google.client_id')), 'GOOGLE_CLIENT_ID is empty: the Google button stays hidden', warn: true);

        $link = public_path('storage');
        $this->check('storage link exists', is_link($link) || is_dir($link), 'run php artisan storage:link');
        $this->check('storage/app/public writable', is_writable(storage_path('app/public')));
        $this->check('storage/logs writable', is_writable(storage_path('logs')));

        $mailer = config('mail.default');
        $this->check('Mail configured (not log)', ! in_array($mailer, ['log', 'array'], true), "MAIL_MAILER=$mailer");

        $this->check('Scheduler ran in the last 10 minutes', $this->schedulerFresh(), 'is the cron line installed?', warn: true);

        $last = cache()->get('egmenu:last_backup_at');
        $this->check('Backup within 36 hours', $last && $last > now()->subHours(36)->timestamp,
            $last ? 'last '.now()->setTimestamp($last)->diffForHumans() : 'no backup recorded yet', warn: true);

        $lastTenants = cache()->get(TenantBackups::LAST_RUN_KEY);
        $this->check('Restaurant backups ran within 8 days', $lastTenants && $lastTenants > now()->subDays(8)->timestamp,
            $lastTenants ? 'last '.now()->setTimestamp($lastTenants)->diffForHumans() : 'no weekly run recorded yet (egmenu:tenant-backups, Sunday 03:30)', warn: true);

        $this->newLine();
        $this->line($this->failures ? "<fg=red>$this->failures check(s) failed</>" : '<fg=green>All good</>');

        return $this->failures ? self::FAILURE : self::SUCCESS;
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

    /** The queue worker runs every minute from the scheduler and touches this key (see routes/console.php). */
    private function schedulerFresh(): bool
    {
        $at = cache()->get('egmenu:scheduler_at');

        return $at && $at > now()->subMinutes(10)->timestamp;
    }

    private function check(string $label, bool $ok, string $hint = '', bool $warn = false): void
    {
        if ($ok) {
            $this->line("  <fg=green>OK</>    $label".($hint && ! str_starts_with($hint, 'run') ? " ($hint)" : ''));

            return;
        }
        if ($warn) {
            $this->line("  <fg=yellow>WARN</>  $label".($hint ? " - $hint" : ''));

            return;
        }
        $this->failures++;
        $this->line("  <fg=red>FAIL</>  $label".($hint ? " - $hint" : ''));
    }
}
