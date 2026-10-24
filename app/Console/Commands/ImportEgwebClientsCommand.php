<?php

namespace App\Console\Commands;

use App\Models\PlatformSetting;
use App\Services\Seo\EgwebClients;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Seeds admin › branding › EgWeb client logos from a folder of logo files (e.g. public/img/egweb-clients). Each file
 * becomes one logo keyed by its file name; a known name (EgwebClients::DEFAULTS) brings its brand name, link and
 * shown/hidden default, any other file is added hidden. Safe to run again: a key already in the list is skipped, so
 * what the admin edited stays as it is.
 */
class ImportEgwebClientsCommand extends Command
{
    protected $signature = 'egmenu:import-egweb-clients {dir : Folder with the logo files (png, webp, jpg, svg)}';

    protected $description = 'Import EgWeb client logos into the home page strip (idempotent)';

    private const EXTENSIONS = ['png', 'webp', 'jpg', 'jpeg', 'svg'];

    public function handle(EgwebClients $clients): int
    {
        $dir = rtrim((string) $this->argument('dir'), '/\\');
        if (! is_dir($dir)) {
            $this->error("Not a folder: $dir");

            return self::FAILURE;
        }

        $files = [];
        foreach (scandir($dir) ?: [] as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $key = Str::slug(pathinfo($file, PATHINFO_FILENAME));
            if (in_array($ext, self::EXTENSIONS, true) && $key !== '' && is_file("$dir/$file")) {
                $files[$key] ??= "$dir/$file";
            }
        }
        // the known logos in their default order (food and hospitality first), then any other file by name
        $known = array_keys(EgwebClients::DEFAULTS);
        uksort($files, fn (string $a, string $b) => [self::rank($known, $a), $a] <=> [self::rank($known, $b), $b]);

        // never saved yet: start from an empty list rather than from the bundled defaults, so the files get stored
        $list = PlatformSetting::get(EgwebClients::SETTING_KEY) === null ? [] : EgwebClients::all();
        $have = array_column($list, 'key');
        $added = 0;
        foreach ($files as $key => $path) {
            if (in_array($key, $have, true)) {
                $this->line("  skip  $key (already in the list)");

                continue;
            }
            try {
                $image = $clients->storeImage(new UploadedFile($path, basename($path), null, null, true), $key);
            } catch (ValidationException $e) {
                $this->warn("  fail  $key: ".collect($e->errors())->flatten()->first());

                continue;
            }
            [$name, $url, $active] = EgwebClients::DEFAULTS[$key] ?? [Str::headline($key), '', false];
            $list[] = ['key' => $key, 'name' => $name, 'url' => $url, 'active' => $active] + $image;
            $have[] = $key;
            $added++;
            $this->line('  add   '.$key.($active ? ' (shown)' : ' (hidden)'));
        }
        EgwebClients::save($list);

        $this->info("$added logo(s) added, ".count($list).' in the list, '.count(array_filter($list, fn ($c) => $c['active'])).' shown.');

        return self::SUCCESS;
    }

    /** @param  list<string>  $known */
    private static function rank(array $known, string $key): int
    {
        $i = array_search($key, $known, true);

        return $i === false ? PHP_INT_MAX : $i;
    }
}
