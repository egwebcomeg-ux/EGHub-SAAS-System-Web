<?php

namespace App\Console\Commands;

use Database\Seeders\BlogSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Draws the covers of the calendar articles in database/seeders/BlogSeeder.php (coverCards()) into
 * database/seeders/blog-covers/{slug}.webp with a local headless Chrome, the same way as `egmenu:share-images`
 * (resources/js/tools/share-cards.mjs). Run it on a developer machine and commit the files: the server has no Chrome.
 * BlogSeeder copies a drawn cover to the public disk when it creates the article.
 */
class BlogCoversCommand extends Command
{
    protected $signature = 'egmenu:blog-covers
        {--only=* : Article slugs to draw (all calendar covers when empty)}
        {--domain=eghub.app : Domain shown on the covers}
        {--chrome= : Path to Chrome or Chromium (found automatically when empty)}';

    protected $description = 'Draw the blog article covers with headless Chrome';

    public const WIDTH = 1600;

    public const HEIGHT = 900;

    private const CHROME_PATHS = [
        'C:/Program Files/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
    ];

    public function handle(): int
    {
        $chrome = $this->option('chrome') ?: collect(self::CHROME_PATHS)->first(fn (string $path) => is_file($path));
        if (! $chrome || ! is_file($chrome)) {
            $this->error('Chrome not found: pass --chrome=/path/to/chrome');

            return self::FAILURE;
        }

        $cards = (new BlogSeeder)->coverCards();
        if ($this->option('only') !== []) {
            $cards = array_intersect_key($cards, array_flip($this->option('only')));
        }
        if ($cards === []) {
            $this->warn('No cover matches.');

            return self::FAILURE;
        }

        $work = storage_path('framework/blog-covers');
        $out = database_path('seeders/blog-covers');
        File::ensureDirectoryExists($work);
        File::ensureDirectoryExists($out);
        $previousLocale = app()->getLocale();
        app()->setLocale('ar');
        $jobs = [];
        foreach ($cards as $slug => $card) {
            $html = "$work/$slug.html";
            File::put($html, view('share-cards.blog-cover', ['card' => $card, 'domain' => $this->option('domain')])->render());
            $jobs[] = ['slug' => $slug, 'html' => 'file:///'.str_replace('\\', '/', $html), 'png' => "$work/$slug.png"];
        }
        app()->setLocale($previousLocale);

        File::put("$work/jobs.json", json_encode([
            'width' => self::WIDTH, 'height' => self::HEIGHT, 'profile' => "$work/profile",
            'jobs' => array_map(fn (array $job) => ['html' => $job['html'], 'png' => $job['png']], $jobs),
        ], JSON_UNESCAPED_SLASHES));
        $this->line('Drawing '.count($jobs).' covers…');
        $run = Process::timeout(60 + 20 * count($jobs))->run(['node', resource_path('js/tools/share-cards.mjs'), $chrome, "$work/jobs.json"]);
        if ($run->errorOutput() !== '') {
            $this->warn(trim($run->errorOutput()));
        }

        $failed = 0;
        foreach ($jobs as $job) {
            $image = is_file($job['png']) ? @imagecreatefrompng($job['png']) : false;
            if ($image === false || imagesx($image) !== self::WIDTH || imagesy($image) !== self::HEIGHT) {
                $failed++;
                $this->error("  ✗ {$job['slug']}");

                continue;
            }
            imagewebp($image, "$out/{$job['slug']}.webp", 84);
            $this->line(sprintf('  ✓ %s (%d KB)', $job['slug'], round(filesize("$out/{$job['slug']}.webp") / 1024)));
        }
        File::deleteDirectory($work);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
