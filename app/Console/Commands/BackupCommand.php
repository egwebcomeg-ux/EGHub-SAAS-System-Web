<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/** Database dump + storage/app/public into one zip, pruned locally and optionally copied to a second disk. */
class BackupCommand extends Command
{
    protected $signature = 'egmenu:backup';

    protected $description = 'Back up the database and uploaded files into storage/app/backups (and BACKUP_DISK if set)';

    public function handle(): int
    {
        $dir = config('backup.path');
        File::ensureDirectoryExists($dir);

        $tmp = tempnam(sys_get_temp_dir(), 'egbk');
        $archive = $dir.'/egmenu-'.now()->format('Y-m-d-Hi').'.zip';

        try {
            $dbName = $this->dumpDatabase($tmp);
            $this->zip($archive, $tmp, $dbName);
        } catch (\Throwable $e) {
            @unlink($archive);
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($tmp);
        }

        $this->info('Archive: '.$archive.' ('.round(filesize($archive) / 1024).' KB)');

        $this->prune($dir);

        if ($disk = config('backup.disk')) {
            try {
                $stream = fopen($archive, 'r');
                Storage::disk($disk)->put(trim(config('backup.remote_dir'), '/').'/'.basename($archive), $stream);
                is_resource($stream) && fclose($stream);
                $this->info("Copied to disk [$disk]");
            } catch (\Throwable $e) {
                $this->error("Copy to disk [$disk] failed: ".$e->getMessage());

                return self::FAILURE;
            }
        }

        cache()->forever('egmenu:last_backup_at', now()->timestamp);

        return self::SUCCESS;
    }

    /** Writes the dump to $target and returns the file name to use inside the zip. */
    private function dumpDatabase(string $target): string
    {
        $conn = config('database.default');
        $cfg = config("database.connections.$conn");

        if (($cfg['driver'] ?? null) === 'sqlite') {
            $db = $cfg['database'];
            if ($db === ':memory:' || ! is_file($db)) {
                throw new RuntimeException('SQLite database file not found');
            }
            copy($db, $target);

            return 'database.sqlite';
        }

        if (! in_array($cfg['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Unsupported database driver: '.($cfg['driver'] ?? '?'));
        }

        $args = [config('backup.mysqldump'), '--single-transaction', '--quick', '--routines', '--no-tablespaces',
            '--default-character-set='.($cfg['charset'] ?? 'utf8mb4'), '--result-file='.$target,
            '--user='.$cfg['username']];
        if (! empty($cfg['unix_socket'])) {
            $args[] = '--socket='.$cfg['unix_socket'];
        } else {
            $args[] = '--host='.$cfg['host'];
            $args[] = '--port='.$cfg['port'];
        }
        $args[] = $cfg['database'];

        // password travels in the environment, never on the command line
        $result = Process::timeout(600)->env(['MYSQL_PWD' => (string) ($cfg['password'] ?? '')])->run($args);
        if ($result->failed()) {
            throw new RuntimeException('mysqldump failed: '.trim($result->errorOutput()));
        }

        return 'database.sql';
    }

    private function zip(string $archive, string $dbFile, string $dbName): void
    {
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create zip');
        }
        $zip->addFile($dbFile, $dbName);

        $root = storage_path('app/public');
        if (is_dir($root)) {
            foreach (File::allFiles($root, true) as $file) {
                $zip->addFile($file->getPathname(), 'public/'.str_replace('\\', '/', $file->getRelativePathname()));
            }
        }
        if (! $zip->close()) {
            throw new RuntimeException('Cannot write zip');
        }
    }

    private function prune(string $dir): void
    {
        $cutoff = now()->subDays(max(1, (int) config('backup.keep_days')))->timestamp;
        $removed = 0;
        foreach (glob($dir.'/egmenu-*.zip') ?: [] as $file) {
            if (filemtime($file) < $cutoff && @unlink($file)) {
                $removed++;
            }
        }
        if ($removed) {
            $this->line("Pruned $removed old archive(s)");
        }
    }
}
