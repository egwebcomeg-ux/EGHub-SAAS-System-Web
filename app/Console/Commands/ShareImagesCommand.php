<?php

namespace App\Console\Commands;

use App\Support\ShareCards;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Draws the share images of the marketing pages (App\Support\ShareCards) into public/og/{ar|en}/{key}.png with a
 * local headless Chrome (driven by resources/js/tools/share-cards.mjs, plain Node). Run it on a developer machine after a page's text changes and commit the PNGs: the server
 * has no Chrome, and GD cannot shape Arabic. A changed file gets a new ?v= stamp, so WhatsApp and Facebook refetch it.
 */
class ShareImagesCommand extends Command
{
    protected $signature = 'egmenu:share-images
        {--only=* : Card keys to draw, wildcards allowed (features/*, directory/cairo); all cards when empty}
        {--locale=* : ar and/or en (both when empty)}
        {--domain=eghub.app : Main domain shown on the cards}
        {--directory-domain=egmenu.app : Domain of the restaurant directory}
        {--chrome= : Path to Chrome or Chromium (found automatically when empty)}';

    protected $description = 'Draw the share images (Open Graph) of the marketing pages with headless Chrome';

    private const CHROME_PATHS = [
        'C:/Program Files/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
    ];

    /** the largest file we accept: WhatsApp skips big previews */
    private const MAX_BYTES = 300 * 1024;

    public function handle(): int
    {
        $chrome = $this->option('chrome') ?: collect(self::CHROME_PATHS)->first(fn (string $path) => is_file($path));
        if (! $chrome || ! is_file($chrome)) {
            $this->error('Chrome not found: pass --chrome=/path/to/chrome');

            return self::FAILURE;
        }

        $locales = $this->option('locale') ?: ShareCards::LOCALES;
        $patterns = $this->option('only');
        $work = storage_path('framework/share-cards');
        File::ensureDirectoryExists($work);
        $previousLocale = app()->getLocale();

        $jobs = [];
        foreach ($locales as $locale) {
            if (! in_array($locale, ShareCards::LOCALES, true)) {
                $this->error("Unknown locale $locale");

                return self::FAILURE;
            }
            app()->setLocale($locale);
            $backgrounds = [
                'navy' => $this->background('navy', "$work/background-navy.png"),
                'white' => $this->background('white', "$work/background-white.png"),
            ];
            foreach (ShareCards::all() as $key => $card) {
                if ($patterns !== [] && ! collect($patterns)->contains(fn (string $pattern) => fnmatch($pattern, $key))) {
                    continue;
                }
                $domain = $card['site'] === 'directory' ? $this->option('directory-domain') : $this->option('domain');
                $html = "$work/$locale-".str_replace('/', '--', $key).'.html';
                File::put($html, view('share-cards.card', [
                    'card' => $card,
                    'domain' => $domain,
                    'background' => 'file:///'.str_replace('\\', '/', $backgrounds[$card['theme']]),
                    'pageUrl' => 'https://'.$domain.'/'.$this->pagePath($key),
                ])->render());
                $jobs[] = ['key' => $key, 'locale' => $locale, 'html' => $html, 'png' => public_path(ShareCards::path($key, $locale))];
            }
        }
        app()->setLocale($previousLocale);

        if ($jobs === []) {
            $this->warn('No card matches.');

            return self::FAILURE;
        }

        foreach ($jobs as $job) {
            File::ensureDirectoryExists(dirname($job['png']));
            @unlink($job['png'].'.raw.png');
        }
        File::put("$work/jobs.json", json_encode([
            'width' => ShareCards::WIDTH, 'height' => ShareCards::HEIGHT, 'profile' => "$work/profile",
            'jobs' => array_map(fn (array $job) => ['html' => 'file:///'.str_replace('\\', '/', $job['html']), 'png' => $job['png'].'.raw.png'], $jobs),
        ], JSON_UNESCAPED_SLASHES));
        $this->line('Drawing '.count($jobs).' cards…');
        $chromeRun = Process::timeout(60 + 20 * count($jobs))
            ->run(['node', resource_path('js/tools/share-cards.mjs'), $chrome, "$work/jobs.json"]);
        if ($chromeRun->errorOutput() !== '') {
            $this->warn(trim($chromeRun->errorOutput()));
        }

        $failed = 0;
        foreach ($jobs as $job) {
            $error = $this->optimize($job['png'].'.raw.png', $job['png']);
            $label = $job['locale'].'/'.$job['key'];
            if ($error !== null) {
                $failed++;
                $this->error("  ✗ $label: $error");
            } else {
                $this->line(sprintf('  ✓ %s (%d KB)', $label, round(filesize($job['png']) / 1024)));
            }
        }

        File::deleteDirectory($work);
        $this->info(count($jobs) - $failed.' of '.count($jobs).' share images written to public/og.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Re-encodes Chrome's screenshot at the highest PNG compression; returns why it failed, or null. */
    private function optimize(string $raw, string $png): ?string
    {
        if (! is_file($raw)) {
            return 'Chrome wrote no screenshot';
        }
        $image = @imagecreatefrompng($raw);
        @unlink($raw);
        if ($image === false) {
            return 'unreadable screenshot';
        }
        if (imagesx($image) !== ShareCards::WIDTH || imagesy($image) !== ShareCards::HEIGHT) {
            return 'wrong size '.imagesx($image).'×'.imagesy($image);
        }
        $fresh = $png.'.new';
        imagepng($image, $fresh, 9, PNG_ALL_FILTERS);
        if (filesize($fresh) > self::MAX_BYTES) {
            // too heavy (has not happened yet): 256 colours keep it small, at the cost of some banding
            imagetruecolortopalette($image, false, 256);
            imagepng($image, $fresh, 9, PNG_ALL_FILTERS);
        }
        // an unchanged card keeps its file (and so its ?v= stamp): WhatsApp and Facebook keep their cached copy
        if (is_file($png) && md5_file($png) === md5_file($fresh)) {
            unlink($fresh);
        } else {
            rename($fresh, $png);
        }

        return null;
    }

    /**
     * The card background as a PNG drawn here with GD: a solid colour (navy, or white for the light cards) with a flat
     * pattern of faint dots. No gradients or glows (the brand rule); a CSS dot pattern would also be dithered by Chrome.
     *
     * @param  'navy'|'white'  $theme
     */
    private function background(string $theme, string $file): string
    {
        [$width, $height] = [ShareCards::WIDTH, ShareCards::HEIGHT];
        // [background, dot colour, dot strength]
        [$base, $dot, $strength] = $theme === 'white'
            ? [[255, 255, 255], [6, 26, 46], 0.06]
            : [[6, 26, 46], [255, 255, 255], 0.075];
        $image = imagecreatetruecolor($width, $height);
        $baseColor = ($base[0] << 16) | ($base[1] << 8) | $base[2];
        $dotColor = ((int) round($base[0] + ($dot[0] - $base[0]) * $strength) << 16)
            | ((int) round($base[1] + ($dot[1] - $base[1]) * $strength) << 8)
            | (int) round($base[2] + ($dot[2] - $base[2]) * $strength);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $baseColor);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ($x % 28 >= 13 && $x % 28 <= 16 && $y % 28 >= 13 && $y % 28 <= 16
                    && ($x % 28 - 14.5) ** 2 + ($y % 28 - 14.5) ** 2 <= 2.9) {
                    imagesetpixel($image, $x, $y, $dotColor);
                }
            }
        }
        imagepng($image, $file, 1);

        return $file;
    }

    /** Address of the page a card belongs to, for the QR codes drawn on some cards. */
    private function pagePath(string $key): string
    {
        return match (true) {
            $key === 'home', $key === 'directory' => '',
            str_starts_with($key, 'directory/') => 'restaurants/'.substr($key, strlen('directory/')),
            default => $key,
        };
    }
}
