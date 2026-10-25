<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\PageCache;
use Illuminate\Console\Command;

/**
 * Drops pages from the LiteSpeed page cache (App\Services\PageCache) with signed calls to /_lscache/purge on every
 * host that may hold them: everything by default (after a deploy: the stored pages point at the old build files;
 * `php artisan optimize` runs this by itself), or one or more restaurants, or the marketing pages.
 */
class PageCachePurgeCommand extends Command
{
    protected $signature = 'egmenu:page-cache-purge
        {--tenant=* : Restaurant slug(s) to purge (their menu, item and place pages on every host)}
        {--platform : Only the marketing pages and the directory}';

    protected $description = 'Purge the LiteSpeed page cache (everything, a restaurant, or the marketing pages)';

    public function handle(PageCache $cache): int
    {
        if (! PageCache::enabled()) {
            $this->components->info('Page cache is off (PAGE_CACHE=false): nothing to purge.');

            return self::SUCCESS;
        }

        $tenants = array_values(array_filter((array) $this->option('tenant')));
        foreach ($tenants as $slug) {
            if (! Tenant::query()->whereKey($slug)->exists()) {
                $this->components->error("No restaurant \"$slug\".");

                return self::FAILURE;
            }
            $cache->purgeTenant($slug);
        }
        if ($this->option('platform')) {
            $cache->purgePlatform();
            $cache->purgeDirectory();
        }
        if ($tenants === [] && ! $this->option('platform')) {
            $cache->purgeAll();
        }

        $sent = $cache->flushOverHttp();
        if ($sent === []) {
            $this->components->warn('Nothing sent: PAGE_CACHE_PURGE_HTTP is off.');

            return self::SUCCESS;
        }
        foreach ($sent as $host => $tags) {
            $this->components->twoColumnDetail($host, $tags);
        }

        return self::SUCCESS;
    }
}
