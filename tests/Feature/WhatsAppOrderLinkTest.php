<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Models\DiningTable;
use App\Models\ItemOptionGroup;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Orders\OrderService;
use App\Services\Orders\WhatsAppOrderLink;
use App\Services\Settings;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** After confirming an order the guest is sent to the restaurant's WhatsApp with the saved order in the message. */
class WhatsAppOrderLinkTest extends TenantTestCase
{
    private const FORM = [
        'brand_name' => 'Cafe A', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#F26522',
        'color_secondary' => '#0A2540', 'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar',
    ];

    private function restaurantWith(string $slug, array $settings, string $plan = 'pro'): Tenant
    {
        $tenant = $this->restaurant($slug, $plan);
        $this->item($tenant, 'Latte', 60);
        $this->as($tenant, fn () => Settings::put($settings));

        return $tenant;
    }

    private function placeOnline(Tenant $tenant, array $extra = [], array $line = [])
    {
        $item = $this->as($tenant, fn () => MenuItem::query()->first());

        return $this->postJson($this->subUrl($tenant->id, 'order'), $extra + [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway',
            'items' => [$line + ['item_id' => $item->id, 'qty' => 2]],
            'customer_name' => 'Mona', 'customer_phone' => '01012345678',
        ])->assertOk();
    }

    private function latestOrder(Tenant $tenant): Order
    {
        return $this->as($tenant, fn () => Order::query()->latest('id')->first());
    }

    /** The decoded text of the wa.me link on a tracking page, after checking the number it opens. */
    private function messageOn(string $html, string $number): string
    {
        $this->assertMatchesRegularExpression('#href="https://wa\.me/'.$number.'\?text=([^"]+)"[^>]*data-whatsapp-order-link#', $html);
        preg_match('#href="https://wa\.me/'.$number.'\?text=([^"]+)"[^>]*data-whatsapp-order-link#', $html, $match);

        return rawurldecode(html_entity_decode($match[1]));
    }

    public function test_off_by_default_the_guest_gets_the_normal_confirmation(): void
    {
        $a = $this->restaurantWith('cafe-a', ['whatsapp' => '01099999999']);

        $url = $this->placeOnline($a)->json('url');

        $this->assertStringNotContainsString('wa=1', $url);
        $this->get($url)->assertOk()
            ->assertDontSee('data-whatsapp-order-link', false)
            ->assertDontSee('data-whatsapp-order-auto', false);
    }

    public function test_on_the_order_is_saved_and_the_page_opens_whatsapp_with_the_saved_order(): void
    {
        $a = $this->restaurantWith('cafe-a', ['whatsapp' => '01099999999', 'order_whatsapp_handoff' => '1', 'online_delivery' => '1']);
        [$large, $cheese, $zone] = $this->as($a, function () {
            $item = MenuItem::query()->first();
            $size = ItemOptionGroup::query()->create(['menu_item_id' => $item->id, 'name' => 'الحجم', 'type' => 'single']);
            $extras = ItemOptionGroup::query()->create(['menu_item_id' => $item->id, 'name' => 'إضافات', 'type' => 'multi']);

            return [
                $size->options()->create(['name' => 'كبير', 'price' => 10]),
                $extras->options()->create(['name' => 'جبنة', 'price' => 5]),
                DeliveryZone::query()->create(['branch_id' => Branch::query()->first()->id, 'name' => 'Maadi', 'fee' => 25, 'min_order' => 0]),
            ];
        });

        // a price sent by the page is never read: the total below is the server's
        $url = $this->placeOnline($a, ['type' => 'delivery', 'zone_id' => $zone->id, 'address' => '9 Road 233', 'total' => 1, 'notes' => 'بدون تأخير'], [
            'option_ids' => [$large->id, $cheese->id], 'notes' => 'سخن', 'unit_price' => 1,
        ])->json('url');
        $order = $this->latestOrder($a);

        $this->assertSame('online', $order->source);
        $this->assertSame('new', $order->status);
        $this->assertSame(175.0, $order->total); // (60 + 10 + 5) × 2 + 25
        $this->assertStringContainsString('/o/'.$order->tracking_token.'?wa=1', $url);

        $html = $this->get($url)->assertOk()->assertSee('data-whatsapp-order-auto', false)->assertSee('ابعت الطلب على واتساب')->getContent();
        $message = $this->messageOn($html, '201099999999');

        foreach (['طلب رقم '.$order->number, 'نوع الطلب: توصيل', '2× Latte', 'الحجم: كبير', 'إضافات: جبنة', 'ملاحظات: سخن', 'ملاحظات: بدون تأخير',
            'الإجمالي: 175 ج.م', 'الاسم: Mona', 'الموبايل: 01012345678', 'العنوان: Maadi - 9 Road 233'] as $line) {
            $this->assertStringContainsString($line, $message);
        }
        $this->assertStringNotContainsString('الدفع:', $message); // nothing is paid yet, so nothing to say

        // the page opened later (no ?wa=1) keeps the button but does not jump to WhatsApp again
        $this->get($this->subUrl('cafe-a', 'o/'.$order->tracking_token))->assertOk()
            ->assertSee('data-whatsapp-order-link', false)->assertDontSee('data-whatsapp-order-auto', false);
    }

    public function test_the_message_follows_the_guests_language(): void
    {
        $a = $this->restaurantWith('cafe-a', ['whatsapp' => '01099999999', 'order_whatsapp_handoff' => '1', 'english_enabled' => '1', 'currency_en' => 'EGP']);
        $this->placeOnline($a);
        $order = $this->latestOrder($a);

        $html = $this->get($this->subUrl('cafe-a', 'o/'.$order->tracking_token.'?lang=en'))->assertOk()->getContent();
        $message = $this->messageOn($html, '201099999999');

        $this->assertStringContainsString('Order type: Takeaway', $message);
        $this->assertStringContainsString('Total: 120 EGP', $message);
    }

    public function test_a_table_order_names_the_table_and_a_hidden_total_stays_hidden(): void
    {
        $a = $this->restaurantWith('cafe-a', ['whatsapp' => '01099999999', 'order_whatsapp_handoff' => '1', 'dine_in_hide_total' => '1']);
        $item = $this->as($a, fn () => MenuItem::query()->first());
        $table = $this->as($a, fn () => DiningTable::query()->first());
        $this->get($this->subUrl('cafe-a', 't/'.$table->qr_token))->assertRedirect();

        $url = $this->postJson($this->subUrl('cafe-a', 'order'), [
            'client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $item->id, 'qty' => 1]],
        ])->assertOk()->json('url');

        $message = $this->messageOn($this->get($url)->assertOk()->getContent(), '201099999999');
        $this->assertStringContainsString('نوع الطلب: صالة - ترابيزة '.$table->name, $message);
        $this->assertStringNotContainsString('الإجمالي', $message);
    }

    public function test_a_branch_number_wins_and_a_pickup_with_a_pager_still_lands_on_the_button(): void
    {
        $a = $this->restaurantWith('cafe-a', ['whatsapp' => '01099999999', 'order_whatsapp_handoff' => '1'], 'full');
        $this->as($a, fn () => Branch::query()->first()->update(['whatsapp' => '01188888888']));

        $url = $this->placeOnline($a)->json('url');

        $this->assertStringContainsString('/o/', $url);
        $html = $this->get($url)->assertOk()->assertSee('data-pager-link', false)->getContent();
        $this->messageOn($html, '201188888888');
    }

    public function test_without_a_number_the_guest_gets_the_normal_confirmation_and_the_owner_is_told(): void
    {
        $a = $this->restaurantWith('cafe-a', ['whatsapp' => '', 'phone' => '0223456789', 'order_whatsapp_handoff' => '1']);

        $url = $this->placeOnline($a)->json('url');

        $this->assertStringNotContainsString('wa=1', $url);
        $this->get($url)->assertOk()->assertSee('data-tracking', false)->assertDontSee('data-whatsapp-order', false);

        $this->actingAs($this->owner($a))->get($this->dashUrl('/settings?tab=orders'))->assertOk()
            ->assertSee('data-whatsapp-handoff-no-number', false);
    }

    public function test_the_owner_switches_it_from_the_orders_settings(): void
    {
        $a = $this->restaurantWith('cafe-a', ['whatsapp' => '01099999999']);
        $owner = $this->owner($a);

        $this->actingAs($owner)->get($this->dashUrl('/settings?tab=orders'))->assertOk()
            ->assertSee('name="order_whatsapp_handoff"', false)->assertDontSee('data-whatsapp-handoff-no-number', false);

        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::FORM + ['whatsapp_handoff_sent' => '1', 'order_whatsapp_handoff' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('1', $this->as($a, fn () => Settings::get('order_whatsapp_handoff')));

        // a save that does not carry the section keeps it
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::FORM)->assertSessionHasNoErrors();
        $this->assertSame('1', $this->as($a, fn () => Settings::get('order_whatsapp_handoff')));

        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::FORM + ['whatsapp_handoff_sent' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('0', $this->as($a, fn () => Settings::get('order_whatsapp_handoff')));
    }

    public function test_another_restaurants_order_or_a_wrong_token_gets_nothing(): void
    {
        $a = $this->restaurantWith('cafe-a', ['whatsapp' => '01099999999', 'order_whatsapp_handoff' => '1']);
        $b = $this->restaurantWith('cafe-b', ['whatsapp' => '01077777777', 'order_whatsapp_handoff' => '1']);
        $this->placeOnline($a);
        $order = $this->latestOrder($a);

        $this->get($this->subUrl('cafe-a', 'o/'.$order->tracking_token))->assertOk()->assertSee('data-whatsapp-order-link', false);

        // the same token on the other restaurant's address, and an unknown token on its own
        $this->get($this->subUrl('cafe-b', 'o/'.$order->tracking_token.'?wa=1'))->assertNotFound();
        $this->get($this->subUrl('cafe-a', 'o/'.str_repeat('0', 24).'?wa=1'))->assertNotFound();

        // even handed the model directly, the link is only built inside the order's own restaurant
        $this->as($b, fn () => $this->assertNull(WhatsAppOrderLink::url($order)));
    }

    public function test_only_the_guests_own_open_orders_get_the_link(): void
    {
        $a = $this->restaurantWith('cafe-a', ['whatsapp' => '01099999999', 'order_whatsapp_handoff' => '1']);
        $item = $this->as($a, fn () => MenuItem::query()->first());

        // a cashier's order has a tracking page too, but no guest is sending anything
        $pos = $this->as($a, fn () => app(OrderService::class)->place([
            'items' => [['item_id' => $item->id, 'qty' => 1]], 'source' => 'pos', 'type' => 'takeaway',
        ], Branch::query()->first())->fresh());
        $this->get($this->subUrl('cafe-a', 'o/'.$pos->tracking_token))->assertOk()->assertDontSee('data-whatsapp-order', false);

        // a cancelled order is not sent to the restaurant
        $this->placeOnline($a);
        $order = $this->latestOrder($a);
        $this->as($a, fn () => app(OrderService::class)->transition(Order::query()->find($order->id), 'cancelled'));
        $this->get($this->subUrl('cafe-a', 'o/'.$order->tracking_token))->assertOk()->assertDontSee('data-whatsapp-order', false);
    }

    public function test_a_very_long_order_keeps_a_short_enough_link(): void
    {
        $a = $this->restaurantWith('cafe-a', ['whatsapp' => '01099999999', 'order_whatsapp_handoff' => '1']);
        $item = $this->as($a, fn () => MenuItem::query()->first());
        $lines = array_fill(0, 40, ['item_id' => $item->id, 'qty' => 1, 'notes' => str_repeat('ملاحظة ', 28)]);

        $url = $this->postJson($this->subUrl('cafe-a', 'order'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => $lines,
            'customer_name' => 'Mona', 'customer_phone' => '01012345678',
        ])->assertOk()->json('url');

        $message = $this->messageOn($this->get($url)->assertOk()->getContent(), '201099999999');
        $this->assertStringContainsString('باقي التفاصيل موجودة مع الطلب عند المطعم.', $message);
        $this->assertStringNotContainsString('ملاحظة', $message);
        $this->assertStringContainsString('الإجمالي: 2,400 ج.م', $message);
    }
}
