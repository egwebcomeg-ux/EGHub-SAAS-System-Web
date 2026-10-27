<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Branch;
use App\Models\Category;
use App\Models\ItemOptionGroup;
use App\Models\MenuItem;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Menu\BestSellers;
use App\Services\Settings;
use App\Support\MenuThemes;
use Illuminate\Support\Facades\Cache;
use Tests\TenantTestCase;

/**
 * The guest menu ideas: "الأكثر طلباً" from real sales, banners on a real offer, the round category row, bold cards,
 * the clearer cart, "اطلب تاني" and favourites.
 */
class MenuGuestExtrasTest extends TenantTestCase
{
    private const SETTINGS = ['brand_name' => 'X', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#111111',
        'color_secondary' => '#222222', 'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar'];

    private int $number = 0;

    /** An order of $tenant in $branch with one line of $item, placed $daysAgo days ago. */
    private function sold(Tenant $tenant, MenuItem $item, ?int $branchId = null, int $daysAgo = 1, string $status = 'served', array $options = [], int $qty = 1): Order
    {
        return $this->as($tenant, function () use ($tenant, $item, $branchId, $daysAgo, $status, $options, $qty) {
            $order = Order::query()->create([
                'branch_id' => $branchId ?? Branch::query()->value('id'), 'number' => ++$this->number, 'status' => $status,
                'type' => 'takeaway', 'source' => 'online', 'subtotal' => $item->price, 'total' => $item->price,
                'tracking_token' => 'tok'.$this->number.$tenant->id,
            ]);
            $order->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
            $order->items()->create(['menu_item_id' => $item->id, 'name' => $item->name, 'unit_price' => $item->price,
                'quantity' => $qty, 'line_total' => $item->price * $qty, 'options' => $options]);

            return $order;
        });
    }

    /** @return array<string, mixed> the menu-data JSON of a menu page */
    private function menuData(string $html): array
    {
        preg_match('#<script type="application/json" id="menu-data">(.*?)</script>#s', $html, $m);

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_most_ordered_counts_real_orders_of_this_branch_over_the_threshold(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte');
        $mocha = $this->item($a, 'Mocha');
        $tea = $this->item($a, 'Tea');
        $cake = $this->item($a, 'Cake');
        $second = $this->as($a, fn () => Branch::query()->create(['name' => 'Second', 'slug' => 'second', 'is_active' => true]));

        foreach (range(1, 4) as $i) {
            $this->sold($a, $latte);
        }
        foreach (range(1, 3) as $i) {
            $this->sold($a, $mocha);
        }
        // under the threshold, cancelled, too old, or another branch: none of them counts
        $this->sold($a, $tea);
        $this->sold($a, $tea);
        $this->sold($a, $tea, status: 'cancelled');
        $this->sold($a, $tea, daysAgo: BestSellers::DAYS + 1);
        foreach (range(1, 5) as $i) {
            $this->sold($a, $cake, $second->id);
        }

        $main = $this->as($a, fn () => Branch::query()->orderBy('id')->first());
        $this->assertSame([$latte->id => 4, $mocha->id => 3], $this->as($a, fn () => BestSellers::ranking($main)));
        $this->assertSame([$cake->id => 5], $this->as($a, fn () => BestSellers::ranking($second->fresh())));

        $html = $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee('data-best-sellers', false)->assertDontSee('data-featured', false)
            ->assertSee(__('Most ordered'))->getContent();
        preg_match('#data-best-sellers.*?</section>#s', $html, $row);
        $this->assertSame(1, preg_match_all('/data-item="'.$latte->id.'"/', $row[0]));
        $this->assertLessThan(strpos($row[0], 'data-item="'.$mocha->id.'"'), strpos($row[0], 'data-item="'.$latte->id.'"'));
        $this->assertStringNotContainsString('data-item="'.$tea->id.'"', $row[0]);
        $this->assertStringNotContainsString('data-item="'.$cake->id.'"', $row[0]);
        // no count is shown to guests; the item page gets the badge list
        $this->assertSame([$latte->id, $mocha->id], $this->menuData($html)['best']);

        // another restaurant never sees these sales
        $b = $this->restaurant('cafe-b');
        $this->item($b, 'Latte');
        $this->get($this->subUrl('cafe-b'))->assertOk()->assertDontSee('data-best-sellers', false);
    }

    public function test_without_enough_sales_the_featured_row_stays_and_the_owner_can_turn_it_off(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte');
        $mocha = $this->item($a, 'Mocha');
        $this->as($a, fn () => $mocha->update(['is_featured' => true]));
        foreach (range(1, 3) as $i) {
            $this->sold($a, $latte);
        }

        // one item over the threshold is not enough for a row: the owner's featured items show
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-featured', false)->assertDontSee('data-best-sellers', false)
            ->assertSee(__('Recommended'));

        // the ranking is cached: new sales show after the cache time
        foreach (range(1, 3) as $i) {
            $this->sold($a, $mocha);
        }
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-best-sellers', false);
        $main = $this->as($a, fn () => Branch::query()->orderBy('id')->first());
        $this->as($a, fn () => Cache::forget(BestSellers::cacheKey($main)));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-best-sellers', false);

        // a sold-out item leaves the row (checked on every view, not cached)
        $this->as($a, fn () => $mocha->update(['is_sold_out' => true]));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-best-sellers', false);
        $this->as($a, fn () => $mocha->update(['is_sold_out' => false]));

        $this->as($a, fn () => Settings::put(['menu_best_sellers' => '0']));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-best-sellers', false)->assertSee('data-featured', false);

        $owner = $this->owner($a);
        $this->actingAs($owner)->get($this->dashUrl('/settings'))->assertOk()->assertSee('data-best-sellers-toggle', false);
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::SETTINGS + ['look_sent' => '1', 'menu_best_sellers' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('1', $this->as($a, fn () => Settings::get('menu_best_sellers')));
    }

    public function test_a_banner_on_an_item_shows_the_struck_price_only_with_a_real_offer(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte', 100);
        $tea = $this->as($a, function () {
            $juices = Category::query()->create(['name' => 'Juices']);

            return MenuItem::query()->create(['category_id' => $juices->id, 'name' => 'Orange', 'price' => 40]);
        });
        $this->as($a, function () use ($latte, $tea) {
            Offer::query()->create(['title' => 'Happy hour', 'discount_percent' => 20, 'category_id' => $latte->category_id]);
            Banner::query()->create(['image' => 'tenants/x/banners/1.webp', 'title' => 'Latte deal', 'menu_item_id' => $latte->id, 'sort_order' => 1]);
            Banner::query()->create(['image' => 'tenants/x/banners/2.webp', 'title' => 'Fresh orange', 'menu_item_id' => $tea->id, 'sort_order' => 2]);
        });

        $html = $this->get($this->subUrl('cafe-a'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-banner-offer='));
        preg_match('#data-banner-offer="(\d+)".*?data-offer-order="\d+"[^>]*>([^<]*)<#s', $html, $offer);
        $this->assertSame((string) $latte->id, $offer[1]);
        $this->assertSame(__('Order now'), trim($offer[2]));
        preg_match('#data-offer-prices>(.*?)</span>#s', $html, $prices);
        $this->assertStringContainsString('80', $prices[1]);
        $this->assertMatchesRegularExpression('#<s[^>]*>\s*100#', $prices[1]);
        // the banner on an item without an offer stays a plain picture (never a made-up "was" price)
        $this->assertSame(1, substr_count($html, 'data-offer-prices'));
        $this->assertStringContainsString('data-item="'.$tea->id.'" data-banner=', $html);

        // the offer over: the banner is a plain picture again
        $this->as($a, fn () => Offer::query()->update(['is_active' => false]));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-banner-offer', false)->assertDontSee('data-offer-order', false);
    }

    public function test_the_category_row_is_chips_by_default_and_circles_by_setting(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte');
        $pizza = $this->as($a, function () {
            $pizzas = Category::query()->create(['name' => 'بيتزا', 'image' => 'tenants/x/categories/pizza.webp']);

            return MenuItem::query()->create(['category_id' => $pizzas->id, 'name' => 'Margherita', 'price' => 150]);
        });

        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-cat-style="chips"', false)->assertDontSee('data-cat-circle', false)
            ->assertSee('data-cat-count', false)->assertSee('data-search', false);

        $this->as($a, fn () => Settings::put(['menu_category_style' => 'circles']));
        $html = $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-cat-style="circles"', false)->getContent();
        // the photo of the category, else its icon (from the name, or the owner's pick)
        $this->assertMatchesRegularExpression('#data-cat-link="cat-'.$pizza->category_id.'"[^>]*data-cat-circle>\s*<span class="m-cat-ring">\s*<img src="/storage/tenants/x/categories/pizza.webp"#', $html);
        $this->assertMatchesRegularExpression('#data-cat-link="cat-'.$latte->category_id.'"[^>]*data-cat-circle>\s*<span class="m-cat-ring">\s*<svg#', $html);
        $this->assertSame(2, substr_count($html, 'data-cat-count'));

        // the owner picks the icon of a category; a wrong value is refused
        $owner = $this->owner($a);
        $this->actingAs($owner)->get($this->dashUrl('/menu'))->assertOk()->assertSee('data-category-icon', false);
        $this->actingAs($owner)->post($this->dashUrl("/menu/categories/{$latte->category_id}"), ['name' => 'Coffee', 'icon' => 'dessert', 'is_active' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('dessert', $this->as($a, fn () => Category::query()->find($latte->category_id)->icon));
        $this->actingAs($owner)->post($this->dashUrl("/menu/categories/{$latte->category_id}"), ['name' => 'Coffee', 'icon' => 'rocket'])->assertSessionHasErrors('icon');
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-placeholder="dessert"', false);

        // the setting is saved from the design section and validated
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::SETTINGS + ['menu_category_style' => 'hexagons'])->assertSessionHasErrors('menu_category_style');
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::SETTINGS + ['menu_category_style' => 'chips'])->assertSessionHasNoErrors();
        $this->assertSame('chips', $this->as($a, fn () => Settings::get('menu_category_style')));
    }

    public function test_bold_cards_render_on_every_theme_with_placeholders_the_price_pill_and_the_round_plus(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte');
        $photo = $this->item($a, 'Mocha');
        $this->as($a, fn () => $photo->update(['image' => 'tenants/x/items/mocha.webp']));
        $this->as($a, fn () => Settings::put(['menu_layout' => 'bold', 'online_takeaway' => '1']));

        foreach (MenuThemes::keys() as $theme) {
            $this->as($a, fn () => Settings::put(['menu_theme' => $theme]));
            $html = $this->get($this->subUrl('cafe-a'))->assertOk()->getContent();
            $this->assertStringContainsString('data-layout="bold"', $html, $theme);
            $this->assertSame(2, substr_count($html, 'class="m-bold-price"'), $theme);
            $this->assertStringContainsString('data-placeholder="cup"', $html, $theme);
            $this->assertStringContainsString('src="/storage/tenants/x/items/mocha.webp"', $html, $theme);
            $this->assertMatchesRegularExpression('#class="m-qa m-primary absolute grid place-items-center rounded-full bottom-3 start-3 size-11" data-quick-add="add" data-quick-item="'.$latte->id.'"#', $html, $theme);
            $this->assertStringContainsString('m-bold-room', $html, $theme);
        }
    }

    public function test_the_cart_shows_line_photos_the_bill_the_time_contact_and_what_is_missing(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a, 'Latte');
        $this->as($a, fn () => Settings::put(['online_takeaway' => '1', 'online_prep_minutes' => '25', 'phone' => '0222222222', 'whatsapp' => '01011112222']));

        $html = $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee('data-bill', false)->assertSee('data-cart-eta', false)->assertSee('data-send-help', false)
            ->assertSee('href="tel:0222222222"', false)->assertSee('href="https://wa.me/201011112222"', false)
            ->assertSee('class="m-label"', false)->getContent();
        $data = $this->menuData($html);
        $this->assertSame(25, $data['online']['prep']);
        $this->assertTrue($data['placeholders']);
        $this->assertSame('cup', collect($data['items'])->first()['icon']);
        preg_match('#<script type="application/json" id="menu-i18n">(.*?)</script>#s', $html, $m);
        $i18n = json_decode($m[1], true);
        foreach (['missing', 'missingName', 'missingPhone', 'missingZone', 'missingAddress', 'missingMin', 'etaPickup', 'etaDelivery'] as $key) {
            $this->assertNotSame('', $i18n[$key] ?? '', $key);
        }
        $this->assertSame('جاهز للاستلام في حوالي __N__ دقيقة', $i18n['etaPickup']);
    }

    public function test_order_again_from_this_phone_and_from_an_order_link_of_this_restaurant_only(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte');
        $tea = $this->item($a, 'Tea', 20);
        $pizza = $this->item($a, 'Pizza', 150);
        [$size, $large] = $this->as($a, function () use ($pizza) {
            $group = ItemOptionGroup::query()->create(['menu_item_id' => $pizza->id, 'name' => 'Size', 'type' => 'single', 'is_required' => true]);

            return [$group, $group->options()->create(['name' => 'Large', 'price' => 30])];
        });
        $this->as($a, fn () => Settings::put(['online_takeaway' => '1']));
        $order = $this->sold($a, $latte, qty: 2);
        $this->as($a, function () use ($order, $tea, $pizza, $large) {
            $order->items()->create(['menu_item_id' => $tea->id, 'name' => 'Tea', 'unit_price' => 20, 'quantity' => 1, 'line_total' => 20]);
            $order->items()->create(['menu_item_id' => $pizza->id, 'name' => 'Pizza', 'unit_price' => 180, 'quantity' => 1, 'line_total' => 180,
                'options' => [['id' => $large->id, 'group' => 'Size', 'name' => 'Large', 'price' => 30]]]);
            $tea->update(['is_sold_out' => true]);
        });

        // the "your last order" card is on the page (filled from this phone's storage)
        $html = $this->get($this->subUrl('cafe-a', '?reorder='.$order->tracking_token))->assertOk()
            ->assertSee('data-last-order', false)->assertSee('data-last-order-again', false)->getContent();
        $this->assertSame([
            'lines' => [
                ['item_id' => $latte->id, 'option_ids' => [], 'qty' => 2, 'notes' => ''],
                ['item_id' => $pizza->id, 'option_ids' => [$large->id], 'qty' => 1, 'notes' => ''],
            ],
            'skipped' => 1,
            // what was skipped, by name (the returning guest's card says it)
            'skippedNames' => ['Tea'],
        ], $this->menuData($html)['reorder']);

        // a size that is gone: that line is skipped instead of guessing
        $this->as($a, fn () => $large->update(['is_sold_out' => true]));
        $reorder = $this->menuData($this->get($this->subUrl('cafe-a', '?reorder='.$order->tracking_token))->getContent())['reorder'];
        $this->assertSame([$latte->id], array_column($reorder['lines'], 'item_id'));
        $this->assertSame(2, $reorder['skipped']);

        // another restaurant's menu never reads this order, and a made-up token gives nothing
        $b = $this->restaurant('cafe-b');
        $this->item($b, 'Latte');
        $this->as($b, fn () => Settings::put(['online_takeaway' => '1']));
        $this->assertNull($this->menuData($this->get($this->subUrl('cafe-b', '?reorder='.$order->tracking_token))->getContent())['reorder']);
        $this->assertNull($this->menuData($this->get($this->subUrl('cafe-a', '?reorder=nope'))->getContent())['reorder']);
        $this->assertNull($this->menuData($this->get($this->subUrl('cafe-a'))->getContent())['reorder']);
        $this->assertNotNull($size);
    }

    public function test_favourites_hearts_on_cards_and_in_the_sheet(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte');
        $this->as($a, fn () => Settings::put(['menu_layout' => 'grid']));

        $html = $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee('data-favs', false)->assertSee('data-fav-chip', false)->assertSee('data-sheet-fav', false)->getContent();
        $this->assertMatchesRegularExpression('#class="m-fav absolute grid size-8 place-items-center rounded-full top-2 start-2" data-fav="'.$latte->id.'" aria-pressed="false"#', $html);

        // a price list has no photo corner: the heart is only in the item sheet
        $this->as($a, fn () => Settings::put(['menu_layout' => 'text']));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-fav="', false)->assertSee('data-sheet-fav', false);
    }
}
