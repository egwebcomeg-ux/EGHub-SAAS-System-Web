<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DeliveryTrackPoint;
use App\Models\DeliveryZone;
use App\Models\DriverLocation;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Orders\OrderService;
use Tests\TenantTestCase;

/** Delivery drivers: giving orders to drivers, the driver's screen and steps, live location, guest map and cash. */
class DeliveryDriversTest extends TenantTestCase
{
    /** A delivery order of $tenant (placed from the POS, not paid), moved through $statuses. */
    private function delivery(Tenant $tenant, string ...$statuses): Order
    {
        $item = $this->item($tenant, 'Pizza', 100);

        return $this->as($tenant, function () use ($item, $statuses) {
            $branch = Branch::query()->first();
            $zone = DeliveryZone::query()->firstOrCreate(['branch_id' => $branch->id, 'name' => 'Maadi'], ['fee' => 20, 'is_active' => true]);
            $order = app(OrderService::class)->place([
                'items' => [['item_id' => $item->id, 'qty' => 2]], 'customer_name' => 'Mona Ali', 'customer_phone' => '01012345678',
                'source' => 'pos', 'type' => 'delivery', 'delivery_zone' => $zone,
                'delivery_address' => ['zone' => 'Maadi', 'text' => '9 Road 233', 'lat' => 29.96, 'lng' => 31.25],
            ], $branch);
            foreach ($statuses as $status) {
                app(OrderService::class)->transition($order, $status);
            }

            return $order->fresh();
        });
    }

    private function driver(Tenant $tenant, string $email, ?int $branchId = null): User
    {
        $driver = $this->staff($tenant, 'driver', $email);
        if ($branchId) {
            $this->as($tenant, fn () => $driver->update(['branch_id' => $branchId]));
        }

        return $driver;
    }

    private function assign(User $by, Order $order, ?User $driver)
    {
        return $this->actingAs($by)->postJson($this->dashUrl("/orders/{$order->id}/driver"), ['driver_id' => $driver?->id]);
    }

    private function ping(User $driver, float $lat = 29.97, float $lng = 31.24)
    {
        return $this->actingAs($driver)->postJson($this->dashUrl('/driver/location'), ['lat' => $lat, 'lng' => $lng, 'accuracy' => 12]);
    }

    private function trackingStatus(Order $order, string $slug = 'cafe-a'): array
    {
        return $this->getJson($this->subUrl($slug, "o/{$order->tracking_token}/status"))->assertOk()->json();
    }

    public function test_the_owner_adds_a_driver_as_staff_and_only_staff_who_may_assign_give_orders_to_drivers(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->actingAs($this->owner($a))->post($this->dashUrl('/users'), [
            'name' => 'Karim Driver', 'phone' => '01111111111', 'role' => 'driver', 'password' => 'Secret-pass-123',
        ])->assertSessionHasNoErrors();
        $driver = $this->as($a, fn () => User::query()->where('phone', '01111111111')->firstOrFail());
        $this->assertTrue($driver->hasRole('driver'));
        $this->assertTrue($driver->can('delivery.drive'));
        $this->assertFalse($driver->can('orders.view'));

        $order = $this->delivery($a);
        $this->assign($this->staff($a, 'kitchen', 'k@cafe-a.test'), $order, $driver)->assertForbidden();
        $this->assign($this->staff($a, 'cashier', 'c@cafe-a.test'), $order, $driver)->assertOk()->assertJsonPath('delivery_status', 'assigned');
        $order->refresh();
        $this->assertSame($driver->id, $order->driver_id);
        $this->assertNotNull($order->driver_assigned_at);

        // a driver of another branch, a disabled driver, a non-driver and a takeaway order are refused
        $other = $this->as($a, fn () => Branch::query()->create(['name' => 'Zamalek', 'slug' => 'zamalek', 'is_active' => true]));
        $manager = $this->staff($a, 'manager', 'm@cafe-a.test');
        $this->assign($manager, $order, $this->driver($a, 'd2@cafe-a.test', $other->id))->assertStatus(422);
        $off = $this->driver($a, 'd3@cafe-a.test');
        $this->as($a, fn () => $off->update(['is_active' => false]));
        $this->assign($manager, $order, $off)->assertStatus(422);
        $this->assign($manager, $order, $this->staff($a, 'waiter', 'w@cafe-a.test'))->assertStatus(422);
        $takeaway = $this->delivery($a);
        $this->as($a, fn () => $takeaway->update(['type' => 'takeaway']));
        $this->assign($manager, $takeaway, $driver)->assertStatus(422);

        // the driver lands on their screen, and sees their link in the menu, not the drivers page
        $this->actingAs($driver)->get($this->dashUrl('/'))->assertRedirect($this->dashUrl('/driver'));
        $this->actingAs($driver)->get($this->dashUrl('/drivers'))->assertForbidden();
        $this->actingAs($driver)->get($this->dashUrl('/driver'))->assertOk()->assertSee('Mona Ali')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
    }

    public function test_a_driver_sees_and_moves_only_their_own_orders(): void
    {
        $a = $this->restaurant('cafe-a');
        $manager = $this->staff($a, 'manager', 'm@cafe-a.test');
        [$karim, $samy] = [$this->driver($a, 'karim@cafe-a.test'), $this->driver($a, 'samy@cafe-a.test')];
        $mine = $this->delivery($a, 'preparing', 'ready');
        $theirs = $this->delivery($a, 'preparing', 'ready');
        $this->assign($manager, $mine, $karim)->assertOk();
        $this->assign($manager, $theirs, $samy)->assertOk();

        $orders = $this->actingAs($karim)->getJson($this->dashUrl('/driver/orders'))->assertOk()->json('orders');
        $this->assertSame([$mine->id], array_column($orders, 'id'));
        $this->assertSame('Mona Ali', $orders[0]['customer']);
        $this->assertSame('01012345678', $orders[0]['phone']);
        $this->assertSame(2, $orders[0]['items_count']);
        $this->assertEquals(220, $orders[0]['collect']); // 2 × 100 + 20 delivery, not paid yet
        $this->assertStringContainsString('destination=29.96,31.25', $orders[0]['maps']);

        $this->actingAs($karim)->postJson($this->dashUrl("/driver/orders/{$theirs->id}/picked-up"))->assertNotFound();
        $this->actingAs($karim)->postJson($this->dashUrl("/driver/orders/{$theirs->id}/failed"), ['reason' => 'x'])->assertNotFound();
        $this->assertSame('assigned', $theirs->fresh()->delivery_status);
        $this->actingAs($this->staff($a, 'kitchen', 'k@cafe-a.test'))->getJson($this->dashUrl('/driver/orders'))->assertForbidden();
    }

    public function test_driver_steps_move_the_order_through_the_existing_status_flow(): void
    {
        $a = $this->restaurant('cafe-a');
        $manager = $this->staff($a, 'manager', 'm@cafe-a.test');
        $driver = $this->driver($a, 'karim@cafe-a.test');
        $order = $this->delivery($a, 'preparing');
        $this->assign($manager, $order, $driver)->assertOk();

        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/delivered"))->assertStatus(422); // not picked up
        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/picked-up"))->assertStatus(422); // kitchen not done
        $this->as($a, fn () => app(OrderService::class)->transition($order->fresh(), 'ready'));

        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/picked-up"))->assertOk()
            ->assertJsonPath('order.status', 'on_way')->assertJsonPath('order.delivery_status', 'picked_up');
        $this->assign($manager, $order, $this->driver($a, 'samy@cafe-a.test'))->assertStatus(422); // already left with it
        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/delivered"))->assertOk()
            ->assertJsonPath('order.status', 'served');
        $order->refresh();
        $this->assertSame('delivered', $order->delivery_status);
        $this->assertNotNull($order->picked_up_at);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame(220.0, $order->cash_collected);
        $this->assertSame(['new', 'preparing', 'ready', 'on_way', 'served'], $this->as($a, fn () => $order->history()->pluck('to_status')->all()));
        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/delivered"))->assertStatus(422); // already delivered
    }

    public function test_a_failed_delivery_comes_back_to_the_restaurant_and_can_go_with_another_driver(): void
    {
        $a = $this->restaurant('cafe-a');
        $manager = $this->staff($a, 'manager', 'm@cafe-a.test');
        $driver = $this->driver($a, 'karim@cafe-a.test');
        $order = $this->delivery($a, 'preparing', 'ready');
        $this->assign($manager, $order, $driver)->assertOk();
        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/picked-up"))->assertOk();

        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/failed"), ['reason' => ''])->assertStatus(422);
        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/failed"), ['reason' => 'The customer does not answer'])->assertOk()
            ->assertJsonPath('order.status', 'ready')->assertJsonPath('order.delivery_status', 'failed');
        $order->refresh();
        $this->assertSame('The customer does not answer', $order->delivery_fail_reason);
        $this->assertNotNull($order->delivery_failed_at);
        $this->assertSame([], $this->actingAs($driver)->getJson($this->dashUrl('/driver/orders'))->json('orders'));

        // the board shows it with the reason, and the manager gives it to someone else
        $card = collect($this->actingAs($manager)->getJson($this->dashUrl('/orders/feed'))->assertOk()->json('orders'))->firstWhere('id', $order->id);
        $this->assertSame('failed', $card['delivery_status']);
        $this->assertSame('The customer does not answer', $card['delivery_reason']);
        $this->assertNotContains('ready', $card['next']);
        $samy = $this->driver($a, 'samy@cafe-a.test');
        $this->assign($manager, $order, $samy)->assertOk();
        $this->assertNull($order->fresh()->delivery_fail_reason);

        // moving the order on the board keeps the driver's steps in line
        $this->as($a, fn () => app(OrderService::class)->transition($order->fresh(), 'on_way'));
        $this->assertSame('picked_up', $order->fresh()->delivery_status);
        $this->as($a, fn () => app(OrderService::class)->transition($order->fresh(), 'served'));
        $this->assertSame('delivered', $order->fresh()->delivery_status);
        $this->assertSame($samy->id, $order->fresh()->driver_id);
    }

    public function test_location_is_taken_only_from_the_assigned_driver_while_the_order_is_out(): void
    {
        $a = $this->restaurant('cafe-a');
        $manager = $this->staff($a, 'manager', 'm@cafe-a.test');
        $driver = $this->driver($a, 'karim@cafe-a.test');
        $idle = $this->driver($a, 'samy@cafe-a.test');
        $order = $this->delivery($a, 'preparing', 'ready');
        $this->assign($manager, $order, $driver)->assertOk();

        $this->ping($driver)->assertStatus(409); // assigned but not out yet
        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/picked-up"))->assertOk();
        $this->ping($driver, 95, 31)->assertStatus(422);  // not a position
        $this->ping($driver)->assertOk()->assertJsonPath('stored', true);
        $this->ping($driver, 29.98, 31.23)->assertOk()->assertJsonPath('stored', false); // too soon after the last one
        $this->ping($idle)->assertStatus(409);
        $this->ping($this->owner($a))->assertStatus(409);
        $this->ping($this->staff($a, 'cashier', 'c@cafe-a.test'))->assertForbidden();

        $this->travel(10)->seconds();
        $this->ping($driver, 29.98, 31.23)->assertOk()->assertJsonPath('stored', true);
        $this->as($a, function () use ($driver, $order) {
            $this->assertSame(1, DriverLocation::query()->count()); // the latest one only
            $this->assertSame(29.98, DriverLocation::query()->where('user_id', $driver->id)->value('lat') + 0.0);
            $this->assertSame(2, DeliveryTrackPoint::query()->where('order_id', $order->id)->count());
        });

        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/delivered"))->assertOk();
        $this->travel(10)->seconds();
        $this->ping($driver)->assertStatus(409); // delivered: nothing is out any more
    }

    public function test_the_guest_sees_the_driver_only_on_their_own_order_and_only_while_it_is_out(): void
    {
        $a = $this->restaurant('cafe-a');
        $manager = $this->staff($a, 'manager', 'm@cafe-a.test');
        $driver = $this->driver($a, 'karim@cafe-a.test');
        $this->as($a, fn () => $driver->update(['name' => 'Karim Hassan']));
        $order = $this->delivery($a, 'preparing', 'ready');
        $neighbour = $this->delivery($a, 'preparing', 'ready');
        $this->assign($manager, $order, $driver)->assertOk();
        $this->assign($manager, $neighbour, $driver)->assertOk();

        $this->assertNull($this->trackingStatus($order)['driver']); // not out yet
        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/picked-up"))->assertOk();
        $this->assertSame(['name' => 'Karim', 'lat' => null, 'lng' => null, 'at' => null, 'minutes' => null], $this->trackingStatus($order)['driver']);
        $this->ping($driver)->assertOk();

        $seen = $this->trackingStatus($order)['driver'];
        $this->assertSame('Karim', $seen['name']);
        $this->assertSame([29.97, 31.24], [$seen['lat'], $seen['lng']]);
        $this->assertArrayNotHasKey('phone', $seen);
        $this->get($this->subUrl('cafe-a', "o/{$order->tracking_token}"))->assertOk()->assertSee('data-driver-box', false)->assertSee('leaflet', false);
        // the other order of the same driver (still waiting at the restaurant) shows nothing of them
        $this->assertNull($this->trackingStatus($neighbour)['driver']);

        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/delivered"))->assertOk();
        $this->assertNull($this->trackingStatus($order)['driver']);
        $this->get($this->subUrl('cafe-a', "o/{$order->tracking_token}"))->assertOk()->assertDontSee('data-driver-box', false);
    }

    public function test_a_failed_delivery_stops_showing_the_driver_to_the_guest(): void
    {
        $a = $this->restaurant('cafe-a');
        $driver = $this->driver($a, 'karim@cafe-a.test');
        $order = $this->delivery($a, 'preparing', 'ready');
        $this->assign($this->owner($a), $order, $driver)->assertOk();
        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/picked-up"))->assertOk();
        $this->ping($driver)->assertOk();
        $this->assertNotNull($this->trackingStatus($order)['driver']);

        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/failed"), ['reason' => 'Wrong address'])->assertOk();
        $this->assertNull($this->trackingStatus($order)['driver']);
    }

    public function test_restaurants_never_see_or_move_each_others_drivers_and_orders(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $driverA = $this->driver($a, 'karim@cafe-a.test');
        $driverB = $this->driver($b, 'samy@cafe-b.test');
        $managerB = $this->staff($b, 'manager', 'm@cafe-b.test');
        $orderA = $this->delivery($a, 'preparing', 'ready');
        $orderB = $this->delivery($b, 'preparing', 'ready');

        $this->assign($managerB, $orderA, $driverB)->assertNotFound();          // another restaurant's order
        $this->assign($managerB, $orderB, $driverA)->assertStatus(422);          // another restaurant's driver
        $this->assign($this->owner($a), $orderA, $driverA)->assertOk();
        $this->actingAs($driverB)->postJson($this->dashUrl("/driver/orders/{$orderA->id}/picked-up"))->assertNotFound();
        $this->assertSame([], $this->actingAs($driverB)->getJson($this->dashUrl('/driver/orders'))->json('orders'));
        $this->actingAs($managerB)->postJson($this->dashUrl("/drivers/{$driverA->id}/settle"))->assertNotFound();

        $feed = $this->actingAs($managerB)->getJson($this->dashUrl('/drivers/feed'))->assertOk()->json();
        $this->assertSame([$driverB->id], array_column($feed['drivers'], 'id'));
        $this->assertSame([$orderB->id], array_column($feed['waiting'], 'id'));
        $this->assertSame([$driverB->id], array_column($this->actingAs($managerB)->getJson($this->dashUrl('/orders/feed'))->json('drivers'), 'id'));

        // the guest link only works on its own restaurant's address
        $this->actingAs($driverA)->postJson($this->dashUrl("/driver/orders/{$orderA->id}/picked-up"))->assertOk();
        $this->ping($driverA)->assertOk();
        $this->getJson($this->subUrl('cafe-b', "o/{$orderA->tracking_token}/status"))->assertNotFound();
        $this->assertSame('Driver', $this->trackingStatus($orderA)['driver']['name'] ?? null);
    }

    public function test_the_manager_sees_what_each_driver_holds_and_settles_it_into_paid_cash(): void
    {
        $a = $this->restaurant('cafe-a');
        $manager = $this->staff($a, 'manager', 'm@cafe-a.test');
        $driver = $this->driver($a, 'karim@cafe-a.test');
        $first = $this->delivery($a, 'preparing', 'ready');
        $second = $this->delivery($a, 'preparing', 'ready');
        foreach ([$first, $second] as $order) {
            $this->assign($manager, $order, $driver)->assertOk();
            $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/picked-up"))->assertOk();
            $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/delivered"))->assertOk();
        }

        $row = $this->actingAs($manager)->getJson($this->dashUrl('/drivers/feed'))->assertOk()->json('drivers.0');
        $this->assertSame($driver->id, $row['id']);
        $this->assertSame(440, (int) $row['holding']);
        $this->assertSame(2, $row['holding_orders']);
        $this->assertSame(2, $row['delivered_today']);
        $this->assertNull($this->actingAs($this->staff($a, 'cashier', 'c@cafe-a.test'))->getJson($this->dashUrl('/drivers/feed'))->json('drivers.0.holding'));
        $this->actingAs($this->staff($a, 'callcenter', 'cc@cafe-a.test'))->postJson($this->dashUrl("/drivers/{$driver->id}/settle"))->assertForbidden();

        $this->actingAs($manager)->postJson($this->dashUrl("/drivers/{$driver->id}/settle"))->assertOk()
            ->assertJsonPath('orders', 2)->assertJsonPath('amount', 440);
        foreach ([$first, $second] as $order) {
            $order->refresh();
            $this->assertSame('paid', $order->payment_status);
            $this->assertSame('cash', $order->payment_method);
            $this->assertNotNull($order->cash_settled_at);
            $this->assertSame($manager->id, $order->cash_settled_by);
        }
        $this->assertSame(0, (int) $this->actingAs($manager)->getJson($this->dashUrl('/drivers/feed'))->json('drivers.0.holding'));
        $this->actingAs($manager)->postJson($this->dashUrl("/drivers/{$driver->id}/settle"))->assertStatus(422); // nothing left
    }

    public function test_drivers_need_the_delivery_feature_of_the_plan(): void
    {
        $basic = $this->restaurant('cafe-a', 'basic');
        $driver = $this->driver($basic, 'karim@cafe-a.test');
        $owner = $this->owner($basic);

        $this->actingAs($driver)->get($this->dashUrl('/driver'))->assertForbidden();
        $this->actingAs($owner)->get($this->dashUrl('/drivers'))->assertForbidden();
        $this->actingAs($owner)->getJson($this->dashUrl('/orders/feed'))->assertOk()->assertJsonPath('drivers', []);
        $this->actingAs($owner)->get($this->dashUrl('/orders'))->assertOk()->assertDontSee(route('dash.drivers'), false);

        $pro = $this->restaurant('cafe-b');
        $this->actingAs($this->owner($pro))->get($this->dashUrl('/orders'))->assertOk()->assertSee(route('dash.drivers'), false);
        $this->actingAs($this->owner($pro))->get($this->dashUrl('/drivers'))->assertOk()->assertSee('drivers-map', false);
    }
}
