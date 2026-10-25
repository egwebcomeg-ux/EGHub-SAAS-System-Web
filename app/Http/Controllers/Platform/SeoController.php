<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\Seo\Directory;
use App\Support\SiteModules;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/** sitemap.xml and robots.txt of every host. */
class SeoController extends Controller
{
    /**
     * Main domain: landing pages, the directory and every listed restaurant's profile and menu (cached 1 hour, or
     * until the next scheduled blog article goes live when that comes sooner).
     * Places on an active custom domain are left out: their canonical pages live on that domain.
     */
    public function sitemap(): Response
    {
        // a scheduled article joins the sitemap when it goes live: the cache never outlives the next one due
        $nextPost = Post::query()->where('is_published', true)->where('published_at', '>', now())->min('published_at');
        $ttl = $nextPost ? (int) max(60, min(3600, ceil(now()->diffInSeconds(Carbon::parse($nextPost))) + 1)) : 3600;
        $xml = Cache::remember('seo.sitemap.'.platform_url(null, ''), $ttl, function () {
            $urls = [];
            foreach (['landing', 'features', 'apps', 'pricing', 'tools.qr', 'terms', 'privacy', 'refund'] as $name) {
                $urls[] = [route($name), null];
            }
            foreach (array_keys(SiteModules::all()) as $slug) {
                $urls[] = [route('features.show', $slug), null];
            }
            // blog: the index and every published article (lastmod = last edit)
            $posts = Post::query()->published()->get(['slug', 'updated_at', 'published_at']);
            if ($posts->isNotEmpty()) {
                $urls[] = [route('blog'), $posts->max('updated_at')?->toDateString()];
                foreach ($posts as $post) {
                    $urls[] = [route('blog.show', $post->slug), max($post->updated_at, $post->published_at)->toDateString()];
                }
            }
            if (directory_domain() === central_domain()) {
                $urls = [...$urls, ...$this->directoryUrls()];
            }
            $tenants = Directory::query()->orderBy('id')->with('domains')->get();
            $lastmod = Directory::lastModified($tenants->pluck('id')->all());
            foreach ($tenants as $tenant) {
                if ($tenant->customDomain()) {
                    continue;
                }
                $at = max((string) ($lastmod[$tenant->id] ?? ''), (string) $tenant->updated_at);
                $date = $at !== '' ? Carbon::parse($at)->toDateString() : null;
                $urls[] = [$tenant->url('about'), $date];
                $urls[] = [$tenant->url(), $date];
            }

            return $this->urlset($urls);
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** The directory's own host after a domain move (legacy_domains()): its home, cities and cuisines (cached 1 hour). */
    public function directorySitemap(): Response
    {
        $xml = Cache::remember('seo.sitemap.directory.'.directory_domain(), 3600, fn () => $this->urlset($this->directoryUrls()));

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function directoryRobots(): Response
    {
        return $this->text("User-agent: *\nAllow: /\n\nSitemap: ".route('directory.sitemap')."\n");
    }

    /** @return list<array{0: string, 1: ?string}> */
    private function directoryUrls(): array
    {
        $urls = [[route('directory'), null]];
        foreach (array_keys(Directory::cityCounts()) as $city) {
            $urls[] = [route('directory.city', $city), null];
            foreach (array_keys(Directory::cuisineCounts($city)) as $cuisine) {
                $urls[] = [route('directory.cuisine', [$city, $cuisine]), null];
            }
        }

        return $urls;
    }

    /** @param list<array{0: string, 1: ?string}> $urls */
    private function urlset(array $urls): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as [$loc, $date]) {
            $out .= '  <url><loc>'.htmlspecialchars($loc, ENT_XML1).'</loc>'.($date ? "<lastmod>$date</lastmod>" : '')."</url>\n";
        }

        return $out.'</urlset>'."\n";
    }

    /** Main domain: everything may be crawled; the sitemap lists the pages that matter. */
    public function robots(): Response
    {
        return $this->text("User-agent: *\nAllow: /\n\nSitemap: ".platform_url(null, 'sitemap.xml')."\n");
    }

    /** Dashboard and admin hosts: nothing to index. */
    public function robotsPrivate(): Response
    {
        return $this->text("User-agent: *\nDisallow: /\n");
    }

    /** Restaurant subdomains and custom domains: crawlable, the pages carry their canonical address. */
    public function robotsMenu(): Response
    {
        return $this->text("User-agent: *\nAllow: /\n");
    }

    private function text(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
