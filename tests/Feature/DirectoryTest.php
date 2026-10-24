<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Feedback;
use App\Models\MenuItem;
use App\Models\Tenant;
use App\Services\Settings;
use Tests\TenantTestCase;

/** eghub.app/restaurants (who is listed, filters, isolation), sitemap.xml and robots.txt of every host. */
class DirectoryTest extends TenantTestCase
{
    /** A place with $items active menu items and the given settings. */
    private function place(string $slug, array $settings = [], int $items = 3, string $plan = 'pro'): Tenant
    {
        $tenant = $this->restaurant($slug, $plan);
        for ($i = 1; $i <= $items; $i++) {
            $this->item($tenant, "Item $i of $slug", 10 * $i);
        }
        $this->as($tenant, fn () => Settings::put($settings + ['brand_name' => "Brand $slug"]));

        return $tenant;
    }

    public function test_only_listed_active_places_with_enough_items_are_shown(): void
    {
        $this->place('listed-cafe', ['city' => 'cairo', 'area' => 'المعادي', 'cuisine' => 'coffee']);
        $this->place('opted-out', ['listed_in_directory' => '0']);
        $this->place('too-small', [], 2);
        $this->place('suspended-cafe')->update(['status' => 'suspended']);
        $this->place('deleted-cafe')->delete();
        $hidden = $this->place('hidden-items');
        $this->as($hidden, fn () => MenuItem::query()->limit(1)->update(['is_active' => false]));

        $this->get($this->menuUrl('restaurants'))->assertOk()
            ->assertSee('Brand listed-cafe')->assertSee('المعادي، القاهرة')->assertSee('http://egmenu.test/listed-cafe/about')
            ->assertSee('<link rel="canonical" href="http://egmenu.test/restaurants">', false)
            ->assertDontSee('Brand opted-out')->assertDontSee('Brand too-small')->assertDontSee('Brand suspended-cafe')
            ->assertDontSee('Brand deleted-cafe')->assertDontSee('Brand hidden-items');
    }

    public function test_city_and_cuisine_pages_filter_by_the_profile(): void
    {
        $this->place('cairo-coffee', ['city' => 'cairo', 'cuisine' => 'coffee,desserts']);
        $this->place('cairo-grill', ['city' => 'cairo', 'cuisine' => 'grills', 'business_type' => 'restaurant']);
        $this->place('alex-coffee', ['city' => 'alexandria', 'cuisine' => 'desserts,coffee']);

        $this->get($this->menuUrl('restaurants/cairo'))->assertOk()->assertSee('مطاعم وكافيهات في القاهرة')
            ->assertSee(['Brand cairo-coffee', 'Brand cairo-grill'])->assertDontSee('Brand alex-coffee')
            ->assertSee('http://egmenu.test/restaurants/cairo/grills');
        $this->get($this->menuUrl('restaurants/cairo/coffee'))->assertOk()->assertSee('قهوة في القاهرة')
            ->assertSee('Brand cairo-coffee')->assertDontSee('Brand cairo-grill')->assertDontSee('Brand alex-coffee');
        $this->get($this->menuUrl('restaurants/alexandria/coffee'))->assertOk()->assertSee('Brand alex-coffee');
        $this->get($this->menuUrl('restaurants/alexandria/grills'))->assertOk()->assertSee('لسه مفيش أماكن متسجلة هنا.');

        $this->get($this->menuUrl('restaurants/paris'))->assertNotFound();
        $this->get($this->menuUrl('restaurants/cairo/sushi'))->assertNotFound();
    }

    public function test_search_by_name_or_area_and_honest_empty_state(): void
    {
        $this->get($this->menuUrl('restaurants'))->assertOk()->assertSee('الدليل لسه بيبدأ');

        $this->place('rosto', ['brand_name' => 'روستو', 'area' => 'الزمالك']);
        $this->place('sofra', ['brand_name' => 'سفرة', 'area' => 'المهندسين']);

        $this->get($this->menuUrl('restaurants?q=الزمالك'))->assertOk()->assertSee('روستو')->assertDontSee('سفرة')
            ->assertSee('<meta name="robots" content="noindex">', false);
        $this->get($this->menuUrl('restaurants?q=سفرة'))->assertOk()->assertSee('سفرة')->assertDontSee('روستو');
        $this->get($this->menuUrl('restaurants?q=nothing-here'))->assertOk()->assertSee('مفيش مكان بيطابق');
    }

    public function test_cards_show_each_place_its_own_data_only(): void
    {
        $a = $this->place('cafe-a', ['city' => 'giza', 'online_delivery' => '1']);
        $b = $this->place('cafe-b', ['city' => 'giza', 'ordering_enabled' => '0'], 3, 'free');
        $this->as($a, fn () => Feedback::query()->insert(['tenant_id' => 'cafe-a', 'rating' => 4, 'created_at' => now()]));
        $this->as($b, fn () => Feedback::query()->insert(['tenant_id' => 'cafe-b', 'rating' => 1, 'created_at' => now()]));

        $html = $this->get($this->menuUrl('restaurants/giza'))->assertOk()->getContent();
        preg_match_all('#<a href="([^"]+)" class="[^"]*" data-place="([a-z0-9-]+)">(.*?)</a>#s', $html, $m, PREG_SET_ORDER);
        $cards = collect($m)->keyBy(2);
        $this->assertSame(['cafe-a', 'cafe-b'], $cards->keys()->sort()->values()->all());

        $this->assertSame('http://egmenu.test/cafe-a/about', $cards['cafe-a'][1]);
        $this->assertStringContainsString('★ 4.0 (1)', $cards['cafe-a'][3]);
        $this->assertStringContainsString('طلب من الترابيزة', $cards['cafe-a'][3]);
        // delivery needs a delivery zone, so it is not claimed
        $this->assertStringNotContainsString('توصيل', $cards['cafe-a'][3]);
        $this->assertStringNotContainsString('Brand cafe-a', $cards['cafe-b'][3]);
        // B is on the free plan: no ratings feature, ordering switched off
        $this->assertStringNotContainsString('★', $cards['cafe-b'][3]);
        $this->assertStringNotContainsString('طلب من الترابيزة', $cards['cafe-b'][3]);
    }

    public function test_sitemap_lists_listed_places_and_is_valid_xml(): void
    {
        $this->place('listed-cafe', ['city' => 'cairo', 'cuisine' => 'coffee']);
        $this->place('opted-out', ['listed_in_directory' => '0']);
        $custom = $this->place('custom-cafe', [], 3, 'business');
        Domain::query()->create(['domain' => 'custom-cafe.com', 'tenant_id' => $custom->id, 'source' => 'own', 'status' => 'active', 'verified_at' => now()]);

        $res = $this->get($this->menuUrl('sitemap.xml'))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string($res->getContent());
        $this->assertNotFalse($xml);
        $lastmod = [];
        foreach ($xml->url as $u) {
            $lastmod[(string) $u->loc] = (string) $u->lastmod;
        }
        $locs = array_keys($lastmod);

        foreach (['http://egmenu.test', 'http://egmenu.test/pricing', 'http://egmenu.test/features', 'http://egmenu.test/features/qr-menu',
            'http://egmenu.test/restaurants', 'http://egmenu.test/restaurants/cairo', 'http://egmenu.test/restaurants/cairo/coffee',
            'http://egmenu.test/listed-cafe/about', 'http://egmenu.test/listed-cafe'] as $url) {
            $this->assertContains($url, $locs);
        }
        $this->assertSame(today()->toDateString(), $lastmod['http://egmenu.test/listed-cafe']);
        $this->assertEmpty(array_filter($locs, fn ($l) => str_contains($l, 'opted-out') || str_contains($l, 'custom-cafe')));

        // not served as a sitemap on other hosts
        $this->get($this->subUrl('listed-cafe', 'sitemap.xml'))->assertNotFound();
    }

    public function test_robots_per_host(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        Domain::query()->create(['domain' => 'cafe-a.com', 'tenant_id' => $a->id, 'source' => 'own', 'status' => 'active', 'verified_at' => now()]);

        $this->get($this->menuUrl('robots.txt'))->assertOk()->assertSee('Allow: /')->assertSee('Sitemap: http://egmenu.test/sitemap.xml');
        $this->get($this->dashUrl('/robots.txt'))->assertOk()->assertSee('Disallow: /')->assertDontSee('Allow: /');
        $this->get($this->adminUrl('/robots.txt'))->assertOk()->assertSee('Disallow: /');
        $this->get($this->subUrl('cafe-a', 'robots.txt'))->assertOk()->assertSee('Allow: /');
        $this->get('http://cafe-a.com/robots.txt')->assertOk()->assertSee('Allow: /');
        $this->assertFileDoesNotExist(public_path('robots.txt'));
    }

    public function test_directory_paths_are_reserved(): void
    {
        foreach (['restaurants', 'sitemap', 'robots', 'directory'] as $slug) {
            $this->assertNotNull(Tenant::slugError($slug));
        }
        $this->get($this->menuUrl('restaurants'))->assertOk()->assertSee('دليل المطاعم');
    }
}
