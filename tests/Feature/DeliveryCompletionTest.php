<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Orders\OrderService;
use App\Services\Orders\ShiftService;
use App\Services\Settings;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Completing the delivery module: the guest's pin on the map (inside a drawn zone), how the guest paid the driver at
 * the door (cash held by the driver, card / InstaPay / wallet paid at once), the manager's per-driver numbers and
 * trails, and the WhatsApp to the driver.
 */
class DeliveryCompletionTest extends TenantTestCase
{
    /** A square drawn around Maadi: lat 29.95–29.97, lng 31.24–31.26. */
    private const MAADI = [[29.95, 31.24], [29.97, 31.24], [29.97, 31.26], [29.95, 31.26]];

    private function zone(Tenant $tenant, ?array $polygon = self::MAADI, string $mode = 'map'): DeliveryZone
    {
        return $this->as($tenant, function () use ($polygon, $mode) {
            Settings::put(['online_delivery' => '1', 'delivery_zone_mode' => $mode]);

            return DeliveryZone::query()->create(['branch_id' => Branch::query()->first()->id, 'name' => 'Maadi', 'fee' => 20, 'polygon' => $polygon, 'is_active' => true]);
        });
    }

    /** An online delivery order from the menu link. */
    private function guestOrder(Tenant $tenant, DeliveryZone $zone, array $extra = [])
    {
        $item = $this->item($tenant, 'Pizza', 100);

        return $this->postJson($this->subUrl($tenant->id, 'order'), $extra + [
            'client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $item->id, 'qty' => 2]],
            'customer_name' => 'Mona', 'customer_phone' => '01012345678', 'type' => 'delivery', 'zone_id' => $zone->id, 'address' => '9 Road 233',
        ]);
    }

    /** A delivery order of $tenant from the POS (not paid), ready for the driver. */
    private function readyDelivery(Tenant $tenant, array $address = []): Order
    {
        $item = $this->item($tenant, 'Pizza', 100);

        return $this->as($tenant, function () use ($item, $address) {
            $branch = Branch::query()->first();
            $zone = DeliveryZone::query()->firstOrCreate(['branch_id' => $branch->id, 'name' => 'Maadi'], ['fee' => 20, 'is_active' => true]);
            $order = app(OrderService::class)->place([
                'items' => [['item_id' => $item->id, 'qty' => 2]], 'customer_name' => 'Mona Ali', 'customer_phone' => '01012345678',
                'source' => 'pos', 'type' => 'delivery', 'delivery_zone' => $zone,
                'delivery_address' => $address + ['zone' => 'Maadi', 'text' => '9 Road 233'],
            ], $branch);
            app(OrderService::class)->transition($order, 'preparing');
            app(OrderService::class)->transition($order, 'ready');

            return $order->fresh();
        });
    }

    private function driver(Tenant $tenant, string $email, string $phone = '01111111111'): User
    {
        $driver = $this->staff($tenant, 'driver', $email);
        $this->as($tenant, fn () => $driver->update(['phone' => $phone]));

        return $driver;
    }

    private function giveAndPickUp(Tenant $tenant, Order $order, User $driver): void
    {
        $this->actingAs($this->owner($tenant))->postJson($this->dashUrl("/orders/{$order->id}/driver"), ['driver_id' => $driver->id])->assertOk();
        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/picked-up"))->assertOk();
    }

    private function deliver(User $driver, Order $order, ?string $method = null)
    {
        return $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/delivered"), $method ? ['method' => $method] : []);
    }

    public function test_the_guest_pin_must_fall_inside_the_drawn_zone_and_goes_with_the_address(): void
    {
        $a = $this->restaurant('cafe-a');
        $zone = $this->zone($a);

        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-pin-open', false)->assertSee('leaflet@1.9.4', false);

        $this->guestOrder($a, $zone, ['lat' => 30.05, 'lng' => 31.23])->assertStatus(422)->assertJsonPath('message', 'المكان المحدد على الخريطة برّه Maadi.');
        $this->guestOrder($a, $zone, ['lat' => 29.96])->assertStatus(422); // half a point
        $this->guestOrder($a, $zone, ['lat' => 29.9612345678, 'lng' => 31.25])->assertOk();
        $this->guestOrder($a, $zone)->assertOk(); // the pin stays optional

        $orders = $this->as($a, fn () => Order::query()->orderBy('id')->get());
        $this->assertSame(['zone' => 'Maadi', 'text' => '9 Road 233', 'lat' => 29.961235, 'lng' => 31.25], $orders[0]->delivery_address);
        $this->assertArrayNotHasKey('lat', $orders[1]->delivery_address);

        // the driver navigates to the exact point, and the guest's tracking page carries it for the map
        $driver = $this->driver($a, 'karim@cafe-a.test');
        $this->actingAs($this->owner($a))->postJson($this->dashUrl("/orders/{$orders[0]->id}/driver"), ['driver_id' => $driver->id])->assertOk();
        $card = $this->actingAs($driver)->getJson($this->dashUrl('/driver/orders'))->assertOk()->json('orders.0');
        $this->assertTrue($card['pinned']);
        $this->assertStringContainsString('destination=29.961235,31.25', $card['maps']);
        $this->get($this->subUrl('cafe-a', "o/{$orders[0]->tracking_token}"))->assertOk()->assertSee('[29.961235,31.25]', false);
    }

    public function test_a_sub_area_without_its_own_drawing_uses_its_parents_and_manual_zones_take_any_pin(): void
    {
        $a = $this->restaurant('cafe-a');
        $parent = $this->zone($a);
        $street = $this->as($a, fn () => DeliveryZone::query()->create(['branch_id' => $parent->branch_id, 'parent_id' => $parent->id, 'name' => 'Road 9', 'is_active' => true]));
        $this->guestOrder($a, $street, ['lat' => 30.05, 'lng' => 31.23])->assertStatus(422);
        $this->guestOrder($a, $street, ['lat' => 29.96, 'lng' => 31.25])->assertOk();

        $b = $this->restaurant('cafe-b');
        $manual = $this->zone($b, null, 'manual');
        $this->guestOrder($b, $manual, ['lat' => 30.05, 'lng' => 31.23])->assertOk();
        $this->assertSame(30.05, $this->as($b, fn () => Order::query()->first()->delivery_address['lat']));
    }

    public function test_the_driver_says_how_the_guest_paid_and_only_cash_waits_for_the_settlement(): void
    {
        $a = $this->restaurant('cafe-a');
        $driver = $this->driver($a, 'karim@cafe-a.test');
        $manager = $this->staff($a, 'manager', 'm@cafe-a.test');
        [$cash, $card, $instapay, $prepaid] = [$this->readyDelivery($a), $this->readyDelivery($a), $this->readyDelivery($a), $this->readyDelivery($a)];
        foreach ([$cash, $card, $instapay, $prepaid] as $order) {
            $this->giveAndPickUp($a, $order, $driver);
        }
        $this->as($a, fn () => app(OrderService::class)->pay($prepaid, 'card', $manager, null));

        $this->deliver($driver, $card, 'cheque')->assertStatus(422);
        $this->deliver($driver, $card, 'card')->assertOk()->assertJsonPath('order.paid', true)->assertJsonPath('order.collect', 0);
        $this->deliver($driver, $instapay, 'instapay')->assertOk();
        $this->deliver($driver, $cash)->assertOk(); // no method: cash, as before
        $this->deliver($driver, $prepaid, 'wallet')->assertOk(); // paid before: the method is ignored

        $card->refresh();
        $this->assertSame(['paid', 'card', 'card', 0.0, 'delivered'], [$card->payment_status, $card->payment_method, $card->delivery_payment_method, $card->cash_collected, $card->delivery_status]);
        $this->assertNull($card->shift_id);
        $instapay->refresh();
        $this->assertSame(['paid', 'instapay', 0.0], [$instapay->payment_status, $instapay->payment_method, $instapay->cash_collected]);
        $cash->refresh();
        $this->assertSame(['unpaid', 'cash', 220.0], [$cash->payment_status, $cash->delivery_payment_method, $cash->cash_collected]);
        $prepaid->refresh();
        $this->assertSame([null, 0.0, 'card'], [$prepaid->delivery_payment_method, $prepaid->cash_collected, $prepaid->payment_method]);

        // the manager sees the cash only as "held"; the card / InstaPay money shows apart, and settling takes the cash alone
        $row = $this->actingAs($manager)->getJson($this->dashUrl('/drivers/feed'))->assertOk()->json('drivers.0');
        $this->assertSame([220, 1, 4, 220, 440], [(int) $row['holding'], $row['holding_orders'], $row['delivered_today'], (int) $row['collected_today'], (int) $row['other_today']]);
        $this->actingAs($manager)->postJson($this->dashUrl("/drivers/{$driver->id}/settle"))->assertOk()->assertJsonPath('orders', 1)->assertJsonPath('amount', 220);
        $this->assertSame(['paid', 'cash'], [$cash->fresh()->payment_status, $cash->fresh()->payment_method]);
        $this->assertNull($card->fresh()->cash_settled_at);
        $this->assertSame(0, (int) $this->actingAs($manager)->getJson($this->dashUrl('/drivers/feed'))->json('drivers.0.holding'));
    }

    public function test_a_card_payment_at_the_door_lands_in_the_branch_open_shift(): void
    {
        $a = $this->restaurant('cafe-a');
        $driver = $this->driver($a, 'karim@cafe-a.test');
        $cashier = $this->staff($a, 'cashier', 'c@cafe-a.test');
        $order = $this->readyDelivery($a);
        $this->giveAndPickUp($a, $order, $driver);
        $shift = $this->as($a, fn () => app(ShiftService::class)->open($cashier, Branch::query()->first(), 100));

        $this->deliver($driver, $order, 'card')->assertOk();
        $this->assertSame($shift->id, $order->fresh()->shift_id);
        $this->assertSame(220.0, round((float) $this->as($a, fn () => app(ShiftService::class)->summary($shift->fresh())['by_method']['card'] ?? 0), 2));
    }

    public function test_per_driver_numbers_and_the_reports_delivery_section_stay_inside_the_restaurant(): void
    {
        // midday, so the minutes travelled below never cross into tomorrow's "today" numbers
        $this->travelTo(now(local_timezone())->setTime(12, 0));
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $karim = $this->driver($a, 'karim@cafe-a.test');
        $this->as($a, fn () => $karim->update(['name' => 'Karim Hassan']));
        $samy = $this->driver($b, 'samy@cafe-b.test', '01122222222');
        $this->as($b, fn () => $samy->update(['name' => 'Samy Fawzy']));

        $failed = $this->readyDelivery($a);
        $this->giveAndPickUp($a, $failed, $karim);
        $this->actingAs($karim)->postJson($this->dashUrl("/driver/orders/{$failed->id}/failed"), ['reason' => 'No answer'])->assertOk();
        foreach ([$this->readyDelivery($a), $this->readyDelivery($a)] as $order) {
            $this->giveAndPickUp($a, $order, $karim);
            $this->travel(10)->minutes();
            $this->deliver($karim, $order, 'cash')->assertOk();
        }
        $other = $this->readyDelivery($b);
        $this->giveAndPickUp($b, $other, $samy);
        $this->deliver($samy, $other, 'card')->assertOk();

        $rows = $this->actingAs($this->owner($a))->getJson($this->dashUrl('/drivers/feed'))->assertOk()->json('drivers');
        $this->assertSame([$karim->id], array_column($rows, 'id'));
        $this->assertSame([2, 1, 10], [$rows[0]['delivered_today'], $rows[0]['failed_today'], $rows[0]['avg_minutes']]);
        $rowsB = $this->actingAs($this->owner($b))->getJson($this->dashUrl('/drivers/feed'))->assertOk()->json('drivers');
        $this->assertSame([1, 0], [$rowsB[0]['delivered_today'], $rowsB[0]['failed_today']]);
        $this->assertSame(220.0, (float) $rowsB[0]['other_today']);

        // the drivers page carries the last-7-days table, the reports page a delivery section with the range's totals
        $this->actingAs($this->owner($a))->get($this->dashUrl('/drivers'))->assertOk()->assertSee('Karim Hassan')->assertDontSee('Samy Fawzy');
        $this->actingAs($this->owner($a))->get($this->dashUrl('/reports'))->assertOk()->assertSee('id="delivery"', false)->assertSee('Karim Hassan')->assertDontSee('Samy Fawzy');
        $this->actingAs($this->owner($b))->get($this->dashUrl('/reports'))->assertOk()->assertSee('Samy Fawzy')->assertDontSee('Karim Hassan');
    }

    public function test_the_trail_of_a_delivered_order_is_only_for_its_own_restaurant_and_branch(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $driver = $this->driver($a, 'karim@cafe-a.test');
        $order = $this->readyDelivery($a, ['lat' => 29.96, 'lng' => 31.25]);
        $this->giveAndPickUp($a, $order, $driver);
        $this->actingAs($driver)->postJson($this->dashUrl('/driver/location'), ['lat' => 29.97, 'lng' => 31.24, 'accuracy' => 10])->assertOk();
        $this->travel(10)->seconds();
        $this->actingAs($driver)->postJson($this->dashUrl('/driver/location'), ['lat' => 29.965, 'lng' => 31.245, 'accuracy' => 10])->assertOk();
        $this->deliver($driver, $order, 'cash')->assertOk();

        $trail = $this->actingAs($this->owner($a))->getJson($this->dashUrl("/drivers/orders/{$order->id}/trail"))->assertOk()->json();
        $this->assertSame([[29.97, 31.24], [29.965, 31.245]], array_map(fn ($p) => [$p['lat'], $p['lng']], $trail['points']));
        $this->assertSame(['lat' => 29.96, 'lng' => 31.25], $trail['destination']);
        $this->assertSame($order->id, $this->actingAs($this->owner($a))->getJson($this->dashUrl('/drivers/feed'))->json('recent.0.id'));

        $this->actingAs($this->owner($b))->getJson($this->dashUrl("/drivers/orders/{$order->id}/trail"))->assertNotFound();
        $this->actingAs($driver)->getJson($this->dashUrl("/drivers/orders/{$order->id}/trail"))->assertForbidden();
        $zamalek = $this->as($a, fn () => Branch::query()->create(['name' => 'Zamalek', 'slug' => 'zamalek', 'is_active' => true]));
        $tied = $this->staff($a, 'manager', 'z@cafe-a.test');
        $this->as($a, fn () => $tied->update(['branch_id' => $zamalek->id]));
        $this->actingAs($tied)->getJson($this->dashUrl("/drivers/orders/{$order->id}/trail"))->assertNotFound();
        $takeaway = $this->readyDelivery($a);
        $this->actingAs($this->owner($a))->getJson($this->dashUrl("/drivers/orders/{$takeaway->id}/trail"))->assertNotFound(); // no driver yet
    }

    public function test_the_driver_gets_a_whatsapp_when_switched_on_and_the_guest_on_the_way_message_carries_the_tracking_link(): void
    {
        $a = $this->restaurant('cafe-a');
        $driver = $this->driver($a, 'karim@cafe-a.test');
        $order = $this->readyDelivery($a, ['lat' => 29.96, 'lng' => 31.25]);
        $this->actingAs($this->owner($a))->postJson($this->dashUrl("/orders/{$order->id}/driver"), ['driver_id' => $driver->id])->assertOk();
        $this->assertSame(0, $this->as($a, fn () => WhatsAppMessage::query()->where('template', 'driver_assigned')->count())); // off by default

        $this->as($a, fn () => Settings::put(['wa_driver_assigned' => '1', 'wa_order_on_way' => '1']));
        $this->actingAs($this->owner($a))->postJson($this->dashUrl("/orders/{$order->id}/driver"), ['driver_id' => $driver->id])->assertOk();
        $this->as($a, function () use ($order) {
            $message = WhatsAppMessage::query()->where('template', 'driver_assigned')->firstOrFail();
            $this->assertSame(whatsapp_number('01111111111'), $message->to);
            $this->assertStringContainsString("#{$order->number}", $message->body);
            $this->assertStringContainsString('9 Road 233', $message->body);
            $this->assertStringContainsString('destination=29.96,31.25', $message->body);
            $this->assertStringContainsString('220', $message->body);
        });

        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/picked-up"))->assertOk();
        $this->as($a, function () use ($order) {
            $message = WhatsAppMessage::query()->where('template', 'order_on_way')->firstOrFail();
            $this->assertStringContainsString('o/'.$order->tracking_token, $message->body);
        });

        // the owner switches it on from the settings page like the other messages
        $this->actingAs($this->owner($a))->get($this->dashUrl('/settings'))->assertOk()->assertSee('wa_driver_assigned', false);
    }
}
