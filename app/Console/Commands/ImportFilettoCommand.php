<?php

namespace App\Console\Commands;

use App\Services\Import\FilettoImporter;
use Illuminate\Console\Command;

/** Sets up Filetto BBQ (restaurant `filetto`) from its old ordering site's database, safe to run again. */
class ImportFilettoCommand extends Command
{
    protected $signature = 'egmenu:import-filetto
        {--source= : The old site\'s SQLite database (backend/database/database.sqlite), opened read-only}
        {--assets= : Folder with the old uploads (the old backend/public: uploads/menu/*.webp and the logo)}
        {--prices= : JSON price mapping (rows[].target + new_price) to apply over the old prices}
        {--owner-email= : The owner\'s sign-in email (default: the placeholder filetto@eghub.app, never mailed)}
        {--adopt : Import into an existing "filetto" restaurant this command did not create}
        {--dry-run : Show what would happen, write nothing}';

    protected $description = 'Create / update the Filetto BBQ restaurant from its old system (menu, brand, zones, guests, new prices)';

    public function handle(FilettoImporter $importer): int
    {
        $source = (string) $this->option('source');
        if ($source === '' || ! is_file($source)) {
            $this->error('Pass --source=path/to/database.sqlite (the old Filetto database).');

            return self::FAILURE;
        }
        $prices = null;
        if ($file = $this->option('prices')) {
            $prices = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            if (! is_array($prices)) {
                $this->error("Price file not found or not JSON: {$file}");

                return self::FAILURE;
            }
        }
        $dry = (bool) $this->option('dry-run');

        try {
            $report = $importer->import(realpath($source) ?: $source, $this->option('assets') ?: null, $prices, [
                'dry_run' => $dry, 'owner_email' => $this->option('owner-email'), 'adopt' => (bool) $this->option('adopt'),
            ]);
        } catch (\Throwable $e) {
            $this->error('Import failed, nothing was saved: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(($dry ? '[DRY RUN, nothing saved] ' : '').'Filetto BBQ → restaurant "'.FilettoImporter::TENANT.'"'
            .($report['tenant_created'] ? ' (created)' : ' (updated)'));
        $rows = [];
        foreach ($report['counts'] as $entity => $counts) {
            foreach ($counts as $what => $n) {
                $rows[] = [$entity, $what, $n];
            }
        }
        $this->table(['What', 'Result', 'Count'], $rows);
        foreach ($report['notes'] as $note) {
            $this->line('• '.$note);
        }
        $this->newLine();
        $this->line('Owner sign-in: '.$report['owner_email']);
        if ($report['password']) {
            $this->warn('Temporary password (shown once, the owner must change it at the first sign-in): '.$report['password']);
            $this->line('Welcome page: '.$report['welcome_url']);
        }

        return self::SUCCESS;
    }
}
