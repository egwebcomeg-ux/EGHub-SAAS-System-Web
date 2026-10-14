<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Seo\Directory;
use App\Support\SiteModules;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/** sitemap.xml and robots.txt of every host. */
class SeoController extends Controller
{
    /**
     * Main domain: landing pages, the directory and every listed restaurant's profile and menu (cached 1 hour).
     * Places on an active custom domain are left out: their canonical pages live on that domain.
     */
    public function sitemap(): Response
    {
        $xml = Cache::remember('seo.sitemap.'.platform_url(null, ''), 3600, function () {
            $urls = [];
            foreach (['landing', 'features', 'pricing', 'directory'] as $name) {
                $urls[] = [route($name), null];
            }
            foreach (array_keys(SiteModules::all()) as $slug) {
                $urls[] = [route('features.show', $slug), null];
            }
            foreach (array_keys(Directory::cityCounts()) as $city) {
                $urls[] = [route('directory.city', $city), null];
                foreach (array_keys(Directory::cuisineCounts($city)) as $cuisine) {
                    $urls[] = [route('directory.cuisine', [$city, $cuisine]), null];
                }
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

            $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
            foreach ($urls as [$loc, $date]) {
                $out .= '  <url><loc>'.htmlspecialchars($loc, ENT_XML1).'</loc>'.($date ? "<lastmod>$date</lastmod>" : '')."</url>\n";
            }

            return $out.'</urlset>'."\n";
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
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
