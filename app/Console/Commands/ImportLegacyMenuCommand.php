<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Import\LegacyMenuImporter;
use Illuminate\Console\Command;

/** Moves a legacy plain-PHP + SQLite client menu (menu.db) into an existing EgMenu restaurant. */
class ImportLegacyMenuCommand extends Command
{
    protected $signature = 'egmenu:import-legacy-menu
        {path : Folder with menu.db (and uploads/, assets/)}
        {tenant : Existing restaurant id (slug)}
        {--dry-run : Show what would happen, write nothing}
        {--replace : Delete the restaurant\'s current categories and items first}
        {--images-base-url= : Where relative image paths can be downloaded from when the files are not in the folder}
        {--logo= : A raster logo file to use instead of the legacy one (the image pipeline does not take SVG)}
        {--no-images : Do not import any picture}';

    protected $description = 'Import a legacy (Desoky-family) menu.db into an EgMenu restaurant';

    public function handle(LegacyMenuImporter $importer): int
    {
        $tenant = Tenant::query()->find($this->argument('tenant'));
        if (! $tenant) {
            $this->error("No restaurant with id \"{$this->argument('tenant')}\".");

            return self::FAILURE;
        }
        $logo = $this->option('logo');
        if ($logo && ! is_file($logo)) {
            $this->error("Logo file not found: $logo");

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');

        try {
            $report = $importer->import((string) $this->argument('path'), $tenant, [
                'dry_run' => $dry,
                'replace' => (bool) $this->option('replace'),
                'images' => ! $this->option('no-images'),
                'images_base_url' => $this->option('images-base-url'),
                'logo' => $logo ? (string) realpath($logo) : null,
            ]);
        } catch (\Throwable $e) {
            $this->error('Import failed, nothing was saved: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(($dry ? '[DRY RUN, nothing saved] ' : '')."Legacy menu → {$tenant->id}");
        $this->table(['Entity', $dry ? 'Would create' : 'Created', 'Skipped (exists)', 'Not imported'],
            collect($report['counts'])->map(fn ($c, $entity) => [$entity, $c['created'], $c['skipped'], $c['unmapped']])->values()->all());
        if ($report['unmapped_settings']) {
            $this->line('Legacy settings with no EgMenu equivalent:');
            $this->table(['Key', 'Value'], collect($report['unmapped_settings'])->map(fn ($v, $k) => [$k, $v])->values()->all());
        }
        foreach ($report['notes'] as $note) {
            $this->line('• '.$note);
        }

        return self::SUCCESS;
    }
}
