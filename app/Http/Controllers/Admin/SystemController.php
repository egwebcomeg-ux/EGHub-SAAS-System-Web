<?php

namespace App\Http\Controllers\Admin;

use App\Billing\OnlinePayments;
use App\Http\Controllers\Controller;
use App\Services\Push\FcmClient;
use App\Support\SystemDoctor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * «صحة النظام» (settings.manage): the egmenu:doctor checklist, the background jobs (waiting and failed), the last
 * backups and scheduler run, which outside services are set up (yes / no only, never a key) and the end of the
 * automatic deploy log.
 */
class SystemController extends Controller
{
    private const DEPLOY_LOG_LINES = 30;

    public function index(SystemDoctor $doctor): View
    {
        $checks = $doctor->checks();

        return view('admin.system', [
            'checks' => $checks,
            'failing' => collect($checks)->where('ok', false)->where('warn', false)->count(),
            'warnings' => collect($checks)->where('ok', false)->where('warn', true)->count(),
            'jobs' => [
                'waiting' => $this->count(config('queue.connections.database.table', 'jobs')),
                'failed' => $this->count('failed_jobs'),
            ],
            'failedJobs' => $this->failedJobs(),
            'times' => [
                'backup' => $doctor->lastBackupAt(),
                'tenant_backups' => $doctor->lastTenantBackupsAt(),
                'scheduler' => $doctor->schedulerAt(),
            ],
            'latestBackup' => $this->latestBackup(),
            'services' => $this->services(),
            'deployLog' => $this->deployLog(),
            'versions' => ['php' => PHP_VERSION, 'laravel' => app()->version(), 'env' => (string) config('app.env')],
        ]);
    }

    private function count(string $table): ?int
    {
        try {
            return DB::table($table)->count();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<array{queue: string, job: string, error: string, at: string}> the last failed jobs, the first line of each error only */
    private function failedJobs(): array
    {
        try {
            return DB::table('failed_jobs')->latest('failed_at')->limit(5)->get()->map(fn ($row) => [
                'queue' => (string) $row->queue,
                'job' => (string) (json_decode((string) $row->payload, true)['displayName'] ?? ''),
                'error' => Str::limit(strtok((string) $row->exception, "\n") ?: '', 200),
                'at' => (string) $row->failed_at,
            ])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{name: string, size: int, at: int}|null the newest file in storage/app/backups */
    private function latestBackup(): ?array
    {
        $files = glob(storage_path('app/backups').'/*') ?: [];
        $files = array_filter($files, 'is_file');
        if ($files === []) {
            return null;
        }
        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        return ['name' => basename($files[0]), 'size' => (int) filesize($files[0]), 'at' => (int) filemtime($files[0])];
    }

    /** @return list<array{0: string, 1: bool, 2: string}> [label, set up, note]: what is configured, never the values */
    private function services(): array
    {
        $online = rescue(fn () => app(OnlinePayments::class)->enabled(), false, false);
        $fcm = rescue(fn () => app(FcmClient::class)->configured(), false, false);
        $whatsapp = (string) config('whatsapp.driver');
        $mailer = (string) config('mail.default');

        return [
            [__('Online payments (Kashier)'), $online, $online ? '' : __('Switched off in Payment settings or keys missing in .env')],
            [__('App notifications (Firebase)'), $fcm, $fcm ? '' : __('FCM project or service account missing')],
            [__('WhatsApp messages'), ! in_array($whatsapp, ['', 'log'], true), 'WHATSAPP_DRIVER='.($whatsapp ?: '—')],
            [__('Email'), ! in_array($mailer, ['log', 'array'], true), 'MAIL_MAILER='.$mailer],
            [__('Google sign-in'), filled(config('services.google.client_id')), ''],
        ];
    }

    /** @return list<string> the last lines of storage/logs/deploy.log (the automatic deploys), empty when there is none */
    private function deployLog(): array
    {
        $path = storage_path('logs/deploy.log');
        if (! is_file($path)) {
            return [];
        }
        // only the end of the file is read: the log grows with every deploy
        $handle = fopen($path, 'rb');
        $size = (int) filesize($path);
        fseek($handle, max(0, $size - 16384));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $tail)), fn (string $l) => trim($l) !== ''));
        if ($size > 16384) {
            array_shift($lines); // the first line was cut in the middle
        }

        return array_slice($lines, -self::DEPLOY_LOG_LINES);
    }
}
