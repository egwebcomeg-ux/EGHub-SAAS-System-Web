<?php

namespace App\Services;

use App\Models\Domain;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The LiteSpeed page cache (config/page_cache.php), steered by response headers only:
 *
 * - X-LiteSpeed-Cache-Control: public,max-age=N marks a page the web server may store and serve to the next visitors
 *   without running PHP; everything else is answered with "no-cache" (App\Http\Middleware\LiteSpeedCacheHeaders).
 * - X-LiteSpeed-Tag groups the stored pages: every restaurant page carries the restaurant's tag, the marketing
 *   pages the platform tag, the directory its own. Tags are exact names: a purge of one restaurant never touches
 *   another, and the server keeps one copy per host and address, so two restaurants never share a page.
 * - X-LiteSpeed-Vary: cookie=... keeps a separate copy per value of the cookies that change a page (the language of
 *   the marketing site) and sends visitors carrying a personal cookie (table, remembered guest, branch, language of a
 *   menu) to PHP, which never marks their answer cacheable.
 * - X-LiteSpeed-Purge drops tags: emitted on the response of the request that changed something and, because a
 *   header only reaches the virtual host that answered (the dashboard is app.<domain>, menus live on the main
 *   domain, the subdomains and the custom domains) and queue jobs and commands have no response at all, also sent
 *   as a signed call to /_lscache/purge on each host that may hold the pages (purge_over_http).
 *
 * What changes a page is found at the database: every insert, update or delete of a table the public pages read
 * (recordWrite(), fed by the QueryExecuted listener in AppServiceProvider) queues the purge of the current
 * restaurant, so bulk updates, imports, the POS and kitchen toggles and queue jobs are covered without a call in
 * each controller.
 */
final class PageCache
{
    /** Request attribute set by CachePublicPage: the profile of a page that may be cached. */
    public const CANDIDATE = 'page_cache';

    /** Request attribute set by CachePublicPage once the page passed every check: how to cache it. */
    public const DECISION = 'page_cache.decision';

    /** The language of the marketing site (?lang=en|ar), plain so the cache can keep one copy per value. */
    public const LANDING_LANG_COOKIE = 'eghub_lang';

    /** Set when a guest scanned a table (the table itself lives in the session): their menu is never the cached one. */
    public const TABLE_COOKIE_PREFIX = 'tbl_';

    /** Restaurant tables the public menu pages read: a write purges the current restaurant. */
    public const TENANT_TABLES = [
        'tenant_settings', 'categories', 'menu_items', 'item_option_groups', 'item_options', 'item_pairings',
        'combo_items', 'branch_items', 'branches', 'banners', 'offers', 'seasons', 'popups', 'delivery_zones',
        'menu_translations', 'loyalty_programs', 'loyalty_rewards', 'membership_tiers', 'kb_articles',
        'kb_categories', 'coupons', 'prepaid_packages',
        // central rows of the restaurant (also purged by their model events when no restaurant is current)
        'tenants', 'domains', 'subscriptions', 'subscription_addons',
    ];

    /** Counter columns of those tables that change on guest visits and orders without changing a page. */
    public const COUNTERS = ['banners' => ['views', 'clicks'], 'coupons' => ['uses']];

    /** Tenant tables that also show in the public directory (name, logo, city, cuisine). */
    public const DIRECTORY_TABLES = ['tenant_settings', 'tenants', 'branches'];

    /** Platform tables shown on the marketing pages only. */
    public const PLATFORM_TABLES = ['posts', 'testimonials'];

    /** Platform tables every page depends on (site settings, plans and their features, add-on prices). */
    public const GLOBAL_TABLES = ['platform_settings', 'plans', 'plan_features', 'addon_prices'];

    /** @var array<string, true> tags to purge, keyed by tag ('*' = everything) */
    private array $headerTags = [];

    /** @var array<string, true> */
    private array $httpTags = [];

    /** @var array<string, true> restaurants whose own hosts get the purge call */
    private array $httpTenants = [];

    private bool $flushRegistered = false;

    public static function enabled(): bool
    {
        return (bool) config('page_cache.enabled');
    }

    public function tag(string $name): string
    {
        return config('page_cache.tag_prefix', 'eg').'_'.$name;
    }

    public function tenantTag(string $tenantId): string
    {
        return $this->tag('t_'.$tenantId);
    }

    public function platformTag(): string
    {
        return $this->tag('platform');
    }

    public function directoryTag(): string
    {
        return $this->tag('directory');
    }

    public function purgeTenant(string $tenantId): void
    {
        if ($tenantId === '' || ! self::enabled()) {
            return;
        }
        $this->queue($this->tenantTag($tenantId));
        $this->httpTenants[$tenantId] = true;
    }

    public function purgePlatform(): void
    {
        if (self::enabled()) {
            $this->queue($this->platformTag());
        }
    }

    public function purgeDirectory(): void
    {
        if (self::enabled()) {
            $this->queue($this->directoryTag());
        }
    }

    public function purgeAll(): void
    {
        if (self::enabled()) {
            $this->queue('*');
        }
    }

    /**
     * A statement that ran on the database: a write to a table the public pages read queues its purge.
     * Selects return at the first character check, so the cost on reads is nil.
     */
    public function recordWrite(string $sql): void
    {
        $first = ltrim($sql)[0] ?? '';
        if ($first === 's' || $first === 'S' || ! self::enabled()) {
            return;
        }
        if (preg_match('/^\s*(?:insert(?:\s+or\s+\w+)?\s+into|replace\s+into|update|delete\s+from)\s+[`"\[]?([A-Za-z0-9_]+)/i', $sql, $m) !== 1) {
            return;
        }
        $table = strtolower($m[1]);
        if (isset(self::COUNTERS[$table]) && $this->onlyCounters($sql, self::COUNTERS[$table])) {
            return;
        }
        if (in_array($table, self::GLOBAL_TABLES, true)) {
            $this->purgeAll();
        } elseif (in_array($table, self::PLATFORM_TABLES, true)) {
            $this->purgePlatform();
        } elseif (in_array($table, self::TENANT_TABLES, true) && tenancy()->initialized) {
            $this->purgeTenant((string) tenant()->getTenantKey());
            if (in_array($table, self::DIRECTORY_TABLES, true)) {
                $this->purgeDirectory();
            }
        }
    }

    /**
     * Whether $sql is an update of counter columns only (the popup's views and taps from the guests, a coupon's uses
     * from each order): nothing a page shows, and it happens on every visit or order.
     *
     * @param  list<string>  $counters
     */
    private function onlyCounters(string $sql, array $counters): bool
    {
        if (preg_match('/^\s*update\s+\S+\s+set\s+(.+?)(?:\s+where\s|$)/is', $sql, $m) !== 1) {
            return false;
        }
        preg_match_all('/(?:^|,)\s*[`"\[]?([A-Za-z0-9_]+)[`"\]]?\s*=/', $m[1], $columns);

        return $columns[1] !== [] && array_diff($columns[1], [...$counters, 'updated_at']) === [];
    }

    /** The X-LiteSpeed-Purge value for the current response (the queued tags are then cleared), or null. */
    public function takePurgeHeader(): ?string
    {
        $tags = array_keys($this->headerTags);
        $this->headerTags = [];
        if ($tags === []) {
            return null;
        }

        return in_array('*', $tags, true) ? '*' : implode(', ', array_map(fn (string $t) => 'tag='.$t, $tags));
    }

    /** @return list<string> */
    public function pendingTags(): array
    {
        return array_keys($this->httpTags);
    }

    /** Drops everything queued (tests, and a request that only reads). */
    public function forget(): void
    {
        $this->headerTags = $this->httpTags = $this->httpTenants = [];
    }

    /**
     * Sends the queued purges as signed calls to /_lscache/purge on each host that may hold the pages. Runs after
     * the response went out (terminating), after each queue job, and at the end of an artisan command.
     *
     * @return array<string, string> host → tags sent
     */
    public function flushOverHttp(): array
    {
        $tags = array_keys($this->httpTags);
        $tenants = array_keys($this->httpTenants);
        $this->headerTags = $this->httpTags = $this->httpTenants = [];
        $this->flushRegistered = false;
        if ($tags === [] || ! self::enabled() || ! config('page_cache.purge_over_http')) {
            return [];
        }

        $sent = [];
        foreach ($this->hostsFor($tags, $tenants) as $host => $hostTags) {
            $value = in_array('*', $hostTags, true) ? '*' : implode(',', $hostTags);
            $sent[$host] = $value;
            $this->call($host, $value);
        }

        return $sent;
    }

    /** The signature of a purge call: only this server (it knows APP_KEY) can ask for one. */
    public static function signature(string $host, string $tags, int $time): string
    {
        return hash_hmac('sha256', strtolower($host).'|'.$tags.'|'.$time, (string) config('app.key'));
    }

    /** A purge value from a call: '*' or tags, each a plain name (letters, digits, - _ .). */
    public static function validTags(string $tags): bool
    {
        return $tags === '*' || preg_match('/^[A-Za-z0-9_.\-]{1,120}(,[A-Za-z0-9_.\-]{1,120}){0,50}$/', $tags) === 1;
    }

    /** The X-LiteSpeed-Purge value for a call's tags. */
    public static function purgeHeaderFor(string $tags): string
    {
        return $tags === '*' ? '*' : implode(', ', array_map(fn (string $t) => 'tag='.$t, explode(',', $tags)));
    }

    /**
     * Marks $response as served to everybody for $maxAge seconds with $tags, keeping a separate copy per value of
     * $varyCookies.
     *
     * @param  list<string>  $tags
     * @param  list<string>  $varyCookies
     */
    public static function markPublic(Response $response, int $maxAge, array $tags, array $varyCookies): void
    {
        $response->headers->set('X-LiteSpeed-Cache-Control', 'public,max-age='.max(1, $maxAge));
        $response->headers->set('X-LiteSpeed-Tag', implode(', ', $tags));
        if ($varyCookies !== []) {
            $response->headers->set('X-LiteSpeed-Vary', implode(',', array_map(fn (string $c) => 'cookie='.$c, $varyCookies)));
        }
    }

    private function queue(string $tag): void
    {
        $this->headerTags[$tag] = true;
        $this->httpTags[$tag] = true;
        if (! $this->flushRegistered) {
            $this->flushRegistered = true;
            // an HTTP request: after the response went out; a command: when it ends (queue jobs: AppServiceProvider)
            app()->terminating(fn () => $this->flushOverHttp());
        }
    }

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $tenants
     * @return array<string, list<string>>
     */
    private function hostsFor(array $tags, array $tenants): array
    {
        $main = central_domain();
        $platformHosts = array_values(array_unique(array_merge([$main], legacy_domains(), (array) config('page_cache.purge_hosts'))));
        $hosts = [];
        $add = function (string $host, string $tag) use (&$hosts) {
            $hosts[strtolower($host)][$tag] = true;
        };

        if (in_array('*', $tags, true)) {
            // every virtual host that may hold pages: the main domain (and its aliases), the restaurant subdomains
            // (any name reaches their host) and the custom domains
            $everything = array_merge($platformHosts, ['purge.'.$main],
                Domain::query()->where('status', 'active')->whereNotNull('verified_at')->pluck('domain')->all());
            foreach ($everything as $host) {
                $add($host, '*');
            }

            return array_map(fn (array $t) => array_keys($t), $hosts);
        }

        $tenantTags = [];
        foreach ($tenants as $tenantId) {
            $tenantTags[$this->tenantTag($tenantId)] = $tenantId;
        }
        foreach ($tags as $tag) {
            if (isset($tenantTags[$tag])) {
                $tenantId = $tenantTags[$tag];
                $add($main, $tag);
                $add($tenantId.'.'.$main, $tag);
                foreach (Domain::query()->where('tenant_id', $tenantId)->pluck('domain') as $domain) {
                    $add((string) $domain, $tag);
                }
            } else {
                foreach ($platformHosts as $host) {
                    $add($host, $tag);
                }
            }
        }

        return array_map(fn (array $t) => array_keys($t), $hosts);
    }

    private function call(string $host, string $tags): void
    {
        $time = time();
        $options = [];
        $ip = (string) config('page_cache.origin_ip');
        if ($ip !== '' && defined('CURLOPT_RESOLVE')) {
            $port = scheme() === 'https' ? 443 : 80;
            $options['curl'] = [CURLOPT_RESOLVE => ["$host:$port:$ip"]];
        }
        try {
            Http::timeout(4)->connectTimeout(2)->withOptions($options)->asForm()
                ->post(scheme().'://'.$host.port_suffix().'/_lscache/purge', [
                    'tags' => $tags, 'time' => $time, 'signature' => self::signature($host, $tags, $time),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Page cache purge call failed', ['host' => $host, 'tags' => $tags, 'error' => $e->getMessage()]);
        }
    }
}
