<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\AddonPrice;
use App\Models\Tenant;
use App\Services\Entitlements;
use App\Services\Settings;
use Tests\TenantTestCase;

/** The featured_listing add-on: paying places first in the directory (max 5 per page, taking turns), with a badge. */
class FeaturedListingTest extends TenantTestCase
{
    private function place(string $slug, array $settings = [], bool $featured = false, string $plan = 'pro'): Tenant
    {
        $tenant = $this->restaurant($slug, $plan);
        for ($i = 1; $i <= 3; $i++) {
            $this->item($tenant, "Item $i of $slug", 10 * $i);
        }
        $this->as($tenant, fn () => Settings::put($settings + ['brand_name' => "Brand $slug", 'city' => 'cairo', 'cuisine' => 'coffee']));
        if ($featured) {
            app(Billing::class)->setAddon($tenant, 'featured_listing', 1);
        }

        return $tenant;
    }

    /** @return list<string> slugs of the cards in page order */
    private function order(string $path): array
    {
        preg_match_all('#data-place="([a-z0-9-]+)"#', $this->get($this->menuUrl($path))->assertOk()->getContent(), $m);

        return $m[1];
    }

    /** @return list<string> */
    private function badged(string $path): array
    {
        preg_match_all('#<li class="relative"\s+data-featured\s*>\s*<a href="[^"]+" class="[^"]*" data-place="([a-z0-9-]+)"#', $this->get($this->menuUrl($path))->getContent(), $m);

        return $m[1];
    }

    public function test_the_add_on_is_sold_as_a_feature_pack_and_not_in_business(): void
    {
        $addon = AddonPrice::query()->where('key', 'featured_listing')->firstOrFail();
        $this->assertSame(['featured_listing'], $addon->features);
        $this->assertSame(199.0, $addon->monthly_price);
        $this->assertStringContainsString('دليل', $addon->description);
        $this->assertFalse(Entitlements::for($this->restaurant('big', 'business'))->allows('featured_listing'));
    }

    public function test_featured_places_come_first_with_a_badge_on_every_directory_page(): void
    {
        $this->place('aaa-plain');
        $this->place('bbb-plain');
        $this->place('zzz-featured', [], true);

        foreach (['restaurants', 'restaurants/cairo', 'restaurants/cairo/coffee'] as $path) {
            $this->assertSame(['zzz-featured', 'aaa-plain', 'bbb-plain'], $this->order($path), $path);
            $this->assertSame(['zzz-featured'], $this->badged($path), $path);
        }
        $this->get($this->menuUrl('restaurants'))->assertSee('★ مميز');
    }

    public function test_at_most_five_featured_per_page_then_the_rest_by_name(): void
    {
        foreach (['f1', 'f2', 'f3', 'f4', 'f5', 'f6', 'f7'] as $slug) {
            $this->place("cafe-$slug", [], true);
        }
        $this->place('aaa-plain');

        $order = $this->order('restaurants/cairo');
        $this->assertCount(8, $order);
        $top = array_slice($order, 0, 5);
        $this->assertSame($top, $this->badged('restaurants/cairo'));
        $this->assertEmpty(array_diff($top, array_map(fn ($n) => "cafe-f$n", range(1, 7))));
        // the two featured places without a slot today are ranked like everyone else, by name
        $rest = array_slice($order, 5);
        $sorted = $rest;
        sort($sorted);
        $this->assertSame($sorted, $rest);
        $this->assertContains('aaa-plain', $rest);
    }

    public function test_only_listed_places_of_the_page_are_featured(): void
    {
        $this->place('plain');
        $this->place('hidden-featured', ['listed_in_directory' => '0'], true);
        $this->place('alex-featured', ['city' => 'alexandria'], true);

        $this->assertSame(['plain'], $this->order('restaurants/cairo'));
        $this->assertSame([], $this->badged('restaurants/cairo'));
        $this->assertSame(['alex-featured'], $this->badged('restaurants/alexandria'));
        $this->get($this->menuUrl('restaurants'))->assertDontSee('Brand hidden-featured');
    }

    public function test_order_online_link_when_online_ordering_is_on(): void
    {
        $this->place('online-cafe', ['online_takeaway' => '1']);
        $this->place('table-only', ['online_takeaway' => '0'], false, 'basic');

        $html = $this->get($this->menuUrl('restaurants/cairo'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-order-online'));
        $this->assertStringContainsString('href="http://egmenu.test/online-cafe" class="absolute', $html);
        $this->assertStringContainsString('اطلب أونلاين', $html);
    }
}
