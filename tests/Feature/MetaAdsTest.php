<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Marketing\MetaConversions;
use App\Services\Orders\OrderService;
use App\Services\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** Meta ads beyond the browser pixel: the Conversions API from the server, and the menu as a catalog feed. */
class MetaAdsTest extends TenantTestCase
{
    private const PIXEL = '123456789012345';

    private const TOKEN = 'EAABtestTOKEN123';

    private const BASE = [
        'brand_name' => 'Kahwa', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#112233',
        'color_secondary' => '#445566', 'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar',
    ];

    private function connected(Tenant $tenant): void
    {
        $this->as($tenant, fn () => Settings::put(['meta_pixel_id' => self::PIXEL, 'meta_capi_token' => MetaConversions::encrypt(self::TOKEN)]));
    }

    private function place(Tenant $tenant, MenuItem $item, string $source): Order
    {
        return $this->as($tenant, fn () => app(OrderService::class)->place([
            'items' => [['item_id' => $item->id, 'qty' => 1]], 'customer_phone' => '01012345678', 'customer_name' => 'Mona', 'source' => $source, 'type' => 'takeaway',
        ], Branch::query()->first()));
    }

    public function test_a_menu_order_reaches_meta_once_with_hashed_contacts_the_browser_cookie_and_the_pixel_event_id(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        $a = $this->restaurant('cafe-a', 'pro');
        $this->connected($a);
        $latte = $this->item($a, 'Latte', 65);

        $this->withCredentials()->withUnencryptedCookie('_fbp', 'fb.1.1700000000000.987654321')
            ->postJson($this->pathUrl('cafe-a', 'order'), [
                'client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $latte->id, 'qty' => 2]],
                'customer_name' => 'Mona Ali', 'customer_phone' => '01012345678',
            ])->assertOk();
        $order = $this->as($a, fn () => Order::query()->sole());

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($order, $latte) {
            $event = $request['data'][0];

            return $request->url() === 'https://graph.facebook.com/'.config('services.meta_capi.version').'/'.self::PIXEL.'/events'
                && $request['access_token'] === self::TOKEN
                && ! str_contains($request->url(), self::TOKEN)
                && $event['event_name'] === 'Purchase' && $event['event_id'] === 'order-'.$order->id
                && $event['action_source'] === 'website'
                && $event['user_data']['ph'] === [hash('sha256', '201012345678')]
                && $event['user_data']['fn'] === [hash('sha256', 'mona')]
                && $event['user_data']['fbp'] === 'fb.1.1700000000000.987654321'
                && $event['custom_data']['value'] == 130 && $event['custom_data']['currency'] === 'EGP'
                && $event['custom_data']['content_ids'] === [(string) $latte->id]
                && ! str_contains($request->body(), '01012345678') && ! str_contains($request->body(), 'Mona');
        });

        // the browser pixel's Purchase carries the same event id, so Meta keeps one
        $this->get($this->pathUrl('cafe-a', 'o/'.$order->tracking_token))->assertOk()->assertSee('"event_id":"order-'.$order->id.'"', false);
        // the owner sees that Meta took it
        $this->actingAs($this->owner($a))->get($this->dashUrl('/settings'))->assertOk()->assertSee('Meta استلمت آخر طلب');
    }

    public function test_call_center_and_whatsapp_orders_go_as_a_call_and_a_chat_and_walk_in_sales_are_not_sent(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        $a = $this->restaurant('cafe-a', 'pro');
        $this->connected($a);
        $latte = $this->item($a, 'Latte', 65);

        foreach (['callcenter', 'whatsapp', 'pos', 'kiosk'] as $source) {
            $this->place($a, $latte, $source);
        }

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r['data'][0]['action_source'] === 'phone_call' && ! isset($r['data'][0]['user_data']['client_user_agent']));
        Http::assertSent(fn (Request $r) => $r['data'][0]['action_source'] === 'chat');
    }

    public function test_nothing_is_sent_without_the_token_or_a_plan_with_the_pixels_and_a_refusal_is_shown(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 400)]);
        $starter = $this->restaurant('cafe-s', 'starter');
        $this->connected($starter);
        $this->place($starter, $this->item($starter, 'Latte'), 'callcenter');

        $a = $this->restaurant('cafe-a', 'pro');
        $this->as($a, fn () => Settings::put(['meta_pixel_id' => self::PIXEL]));
        $latte = $this->item($a, 'Latte');
        $this->place($a, $latte, 'callcenter');
        Http::assertNothingSent();

        $this->connected($a);
        $this->place($a, $latte, 'callcenter');
        $this->actingAs($this->owner($a))->get($this->dashUrl('/settings'))->assertOk()->assertSee('Meta رفضت آخر طلب: Invalid OAuth access token');
    }

    public function test_the_token_is_stored_encrypted_kept_when_left_empty_and_never_shown(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $owner = $this->owner($a);
        $form = self::BASE + ['pixels_sent' => 1, 'meta_pixel_id' => self::PIXEL];

        $this->actingAs($owner)->post($this->dashUrl('/settings'), $form + ['meta_capi_token' => '<script>'])->assertSessionHasErrors('meta_capi_token');
        $this->actingAs($owner)->post($this->dashUrl('/settings'), $form + ['meta_capi_token' => self::TOKEN, 'meta_test_event_code' => 'TEST123'])->assertSessionHasNoErrors();
        $stored = $this->as($a, fn () => Settings::get('meta_capi_token'));
        $this->assertNotSame(self::TOKEN, $stored);
        $this->assertSame(self::TOKEN, Crypt::decryptString($stored));
        $this->assertSame('TEST123', $this->as($a, fn () => Settings::get('meta_test_event_code')));

        $this->actingAs($owner)->get($this->dashUrl('/settings'))->assertOk()->assertSee('name="meta_capi_token"', false)->assertDontSee(self::TOKEN)->assertDontSee($stored);

        // saving again without typing it keeps it; the remove box clears it
        $this->actingAs($owner)->post($this->dashUrl('/settings'), $form)->assertSessionHasNoErrors();
        $this->assertSame(self::TOKEN, $this->as($a, fn () => MetaConversions::token()));
        $this->actingAs($owner)->post($this->dashUrl('/settings'), $form + ['remove_meta_capi_token' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($this->as($a, fn () => MetaConversions::token()));
    }

    public function test_the_catalog_feed_lists_this_restaurants_dishes_with_a_photo_and_a_price(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $latte = $this->item($a, 'Latte', 65);
        $mocha = $this->item($a, 'Mocha', 80);
        $this->item($a, 'No photo', 50);
        $this->as($a, function () use ($latte, $mocha) {
            MenuItem::query()->whereKey($latte->id)->update(['image' => 'items/latte.jpg', 'description' => 'Espresso, milk']);
            MenuItem::query()->whereKey($mocha->id)->update(['image' => 'items/mocha.jpg', 'is_sold_out' => true]);
        });
        $b = $this->restaurant('cafe-b', 'pro');
        $other = $this->item($b, 'Other restaurant tea', 30);
        $this->as($b, fn () => MenuItem::query()->whereKey($other->id)->update(['image' => 'items/tea.jpg']));

        $response = $this->get($this->pathUrl('cafe-a', 'catalog/meta.csv'))->assertOk();
        $rows = array_map('str_getcsv', array_filter(explode("\n", $response->streamedContent())));
        $this->assertSame(['id', 'title', 'description', 'availability', 'condition', 'price', 'sale_price', 'link', 'image_link', 'brand', 'product_type'], $rows[0]);
        $byId = collect(array_slice($rows, 1))->keyBy(0);
        $this->assertEqualsCanonicalizing([$latte->id, $mocha->id], $byId->keys()->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['Latte', 'Espresso, milk', 'in stock', 'new', '65.00 EGP'], array_slice($byId[(string) $latte->id], 1, 5));
        $this->assertSame('out of stock', $byId[(string) $mocha->id][3]);
        $this->assertStringEndsWith('/cafe-a/item/'.$latte->id, $byId[(string) $latte->id][7]);
        $this->assertStringEndsWith('/cafe-a/storage/items/latte.jpg', $byId[(string) $latte->id][8]);
        $this->assertStringNotContainsString('Other restaurant tea', $response->streamedContent());

        // the owner copies the link from the settings page; a plan without the pixels has no feed
        $this->actingAs($this->owner($a))->get($this->dashUrl('/settings'))->assertOk()->assertSee('/cafe-a/catalog/meta.csv');
        $this->restaurant('cafe-s', 'starter');
        $this->get($this->pathUrl('cafe-s', 'catalog/meta.csv'))->assertNotFound();
    }
}
