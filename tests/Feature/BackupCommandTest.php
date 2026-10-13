<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class BackupCommandTest extends TestCase
{
    private string $dir;

    private string $dbFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/egbk-test-'.uniqid();
        $this->dbFile = $this->dir.'.sqlite';
        File::ensureDirectoryExists($this->dir);
        file_put_contents($this->dbFile, 'sqlite-bytes');
        config(['backup.path' => $this->dir, 'backup.keep_days' => 7, 'backup.disk' => null,
            'database.connections.sqlite.database' => $this->dbFile]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        @unlink($this->dbFile);
        parent::tearDown();
    }

    public function test_creates_archive_with_database_and_prunes_old_ones(): void
    {
        $old = $this->dir.'/egmenu-2020-01-01-0230.zip';
        $recent = $this->dir.'/egmenu-recent.zip';
        touch($old, now()->subDays(10)->timestamp);
        touch($recent, now()->subDays(2)->timestamp);

        $this->artisan('egmenu:backup')->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
        $zips = array_values(array_filter(glob($this->dir.'/egmenu-*.zip'), fn ($f) => $f !== $recent));
        $this->assertCount(1, $zips);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zips[0]));
        $this->assertSame('sqlite-bytes', $zip->getFromName('database.sqlite'));
        $zip->close();
    }

    public function test_copies_to_second_disk_when_configured(): void
    {
        Storage::fake('offsite');
        config(['backup.disk' => 'offsite']);

        $this->artisan('egmenu:backup')->assertSuccessful();

        $this->assertCount(1, Storage::disk('offsite')->files('egmenu-backups'));
    }

    public function test_fails_when_database_missing(): void
    {
        config(['database.connections.sqlite.database' => $this->dir.'/nope.sqlite']);

        $this->artisan('egmenu:backup')->assertFailed();
    }
}
