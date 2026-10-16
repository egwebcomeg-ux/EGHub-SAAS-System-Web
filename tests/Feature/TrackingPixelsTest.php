<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Orders\OrderService;
use App\Services\Settings;
use Tests\TenantTestCase;

/** Ad tracking pixels: injected into the public menu pages only, only when set and included in the plan. */
class TrackingPixelsTest extends TenantTestCase
{
    private const BASE = [
        'brand_name' => 'Kahwa', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#112233',
        'color_secondary' => '#445566', 'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar',
    ];

    private const IDS = ['meta_pixel_id' => '123456789012345', 'ga4_measurement_id' => 'G-ABC123XYZ', 'tiktok_pixel_id' => 'CABCDEF123456789ABCD'];

    private function pixels(Tenant $t, array $ids = self::IDS): void
    {
        $this->as($t, fn () => Settings::put($ids));
    }

    public function test_snippets_render_on_the_public_pages_only_when_set(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->item($a, 'Latte');

        $this->get($this->pathUrl('cafe-a'))->assertOk()->assertDontSee('data-egm-pixels', false)->assertDontSee('fbevents.js');

        $this->pixels($a);
        foreach ([$this->pathUrl('cafe-a'), $this->pathUrl('cafe-a', 'about'), $this->pathUrl('cafe-a', 'links'), $this->subUrl('cafe-a')] as $url) {
            $this->get($url)->assertOk()
                ->assertSee("fbq('init', '123456789012345')", false)->assertSee("fbq('track', 'PageView')", false)
                ->assertSee('gtag/js?id=G-ABC123XYZ', false)->assertSee("ttq.load('CABCDEF123456789ABCD')", false)
                ->assertSee('window.egmTrack = function', false);
        }

        // only Meta set: the others stay out
        $this->pixels($a, ['ga4_measurement_id' => '', 'tiktok_pixel_id' => '']);
        $this->get($this->pathUrl('cafe-a'))->assertOk()->assertSee('fbevents.js')->assertDontSee('googletagmanager')->assertDontSee('ttq.load');

        // never in the dashboard
        $this->actingAs($this->owner($a))->get($this->dashUrl('/settings'))->assertOk()->assertDontSee('fbevents.js')->assertDontSee('data-egm-pixels', false);
        $this->actingAs($this->owner($a))->get($this->dashUrl('/'))->assertOk()->assertDontSee('fbevents.js');
    }

    public function test_pixels_need_a_paid_plan_with_branding_or_offers(): void
    {
        $starter = $this->restaurant('cafe-s', 'starter');
        $this->item($starter, 'Latte');
        $this->pixels($starter);
        $this->get($this->pathUrl('cafe-s'))->assertOk()->assertDontSee('fbevents.js')->assertDontSee('data-egm-pixels', false);

        $owner = $this->owner($starter);
        $this->actingAs($owner)->get($this->dashUrl('/settings'))->assertOk()->assertSee('تتبع الإعلانات')->assertDontSee('name="meta_pixel_id"', false);
        // ids posted without the feature are ignored
        $this->as($starter, fn () => Settings::put(['meta_pixel_id' => '']));
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::BASE + ['pixels_sent' => 1, 'meta_pixel_id' => '123456789012345'])->assertSessionHasNoErrors();
        $this->assertSame('', $this->as($starter, fn () => Settings::get('meta_pixel_id')));

        // Basic (full branding) is enough
        $basic = $this->restaurant('cafe-b', 'basic');
        $this->item($basic, 'Latte');
        $this->pixels($basic);
        $this->get($this->pathUrl('cafe-b'))->assertOk()->assertSee('fbevents.js');
    }

    public function test_the_settings_page_validates_the_ids_and_links_the_privacy_policy(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $owner = $this->owner($a);
        $this->actingAs($owner)->get($this->dashUrl('/settings'))->assertOk()->assertSee('تتبع الإعلانات')->assertSee('name="meta_pixel_id"', false)->assertSee('/privacy');

        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::BASE + ['pixels_sent' => 1, 'meta_pixel_id' => 'abc'])->assertSessionHasErrors('meta_pixel_id');
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::BASE + ['pixels_sent' => 1, 'ga4_measurement_id' => 'UA-1234'])->assertSessionHasErrors('ga4_measurement_id');
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::BASE + ['pixels_sent' => 1, 'tiktok_pixel_id' => '<script>'])->assertSessionHasErrors('tiktok_pixel_id');

        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::BASE + ['pixels_sent' => 1, 'meta_pixel_id' => '123456789012345', 'ga4_measurement_id' => 'G-abc123xyz', 'tiktok_pixel_id' => ''])
            ->assertSessionHasNoErrors();
        $saved = $this->as($a, fn () => Settings::all());
        $this->assertSame(['123456789012345', 'G-ABC123XYZ', ''], [$saved['meta_pixel_id'], $saved['ga4_measurement_id'], $saved['tiktok_pixel_id']]);
    }

    public function test_the_order_page_fires_purchase_with_the_order_value_in_egp(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->pixels($a, ['meta_pixel_id' => '123456789012345']);
        $item = $this->item($a, 'Latte', 65);
        $order = $this->as($a, fn () => app(OrderService::class)->place([
            'items' => [['item_id' => $item->id, 'qty' => 2]], 'customer_phone' => '01012345678', 'source' => 'pos', 'type' => 'takeaway',
        ], Branch::query()->first()));

        $html = $this->get($this->pathUrl('cafe-a', 'o/'.$order->tracking_token))->assertOk()
            ->assertSee('data-egm-purchase', false)->assertSee("egmTrack('Purchase'", false)->getContent();
        preg_match('/var p = (\{.*?\});/s', $html, $m);
        $p = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($order->number, $p['id']);
        $this->assertSame(130.0, (float) $p['value']);
        $this->assertSame($item->id, $p['items'][0]['id']);
        $this->assertStringContainsString("currency: 'EGP'", $html);

        // a cancelled order is not a purchase; without a pixel nothing is emitted
        $this->as($a, fn () => Order::query()->whereKey($order->id)->update(['status' => 'cancelled']));
        $this->get($this->pathUrl('cafe-a', 'o/'.$order->tracking_token))->assertOk()->assertDontSee('data-egm-purchase', false);
        $this->pixels($a, ['meta_pixel_id' => '']);
        $this->as($a, fn () => Order::query()->whereKey($order->id)->update(['status' => 'new']));
        $this->get($this->pathUrl('cafe-a', 'o/'.$order->tracking_token))->assertOk()->assertDontSee('egmTrack', false);
    }
}
