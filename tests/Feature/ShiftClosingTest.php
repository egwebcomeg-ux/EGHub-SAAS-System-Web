<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Reports\DailyDigest;
use Carbon\CarbonImmutable;
use Tests\TenantTestCase;

/**
 * Closing the day: the cashier takes the drivers' cash into the open shift before closing, a driver switched off still
 * hands in what they hold, a manager closes the shift a cashier left open, and the morning digest names what was left.
 */
class ShiftClosingTest extends TenantTestCase
{
    /** A restaurant with the cashier screen, its shifts and delivery. */
    private function cafe(string $slug = 'cafe-a'): Tenant
    {
        $tenant = $this->restaurant($slug, 'pro');
        app(Billing::class)->setAddon($tenant, 'pos_pack', 1);

        return $tenant;
    }

    /** A delivery order of 220 (two pizzas and the fee) the driver delivered and took in cash. */
    private function deliveredInCash(Tenant $tenant, User $driver): Order
    {
        $item = $this->item($tenant, 'Pizza', 100);
        $order = $this->as($tenant, function () use ($item) {
            $branch = Branch::query()->first();
            $zone = DeliveryZone::query()->firstOrCreate(['branch_id' => $branch->id, 'name' => 'Maadi'], ['fee' => 20, 'is_active' => true]);
            $order = app(OrderService::class)->place([
                'items' => [['item_id' => $item->id, 'qty' => 2]], 'customer_name' => 'Mona Ali', 'customer_phone' => '01012345678',
                'source' => 'online', 'type' => 'delivery', 'delivery_zone' => $zone, 'delivery_address' => ['zone' => 'Maadi', 'text' => '9 Road 233'],
            ], $branch);
            app(OrderService::class)->transition($order, 'preparing');
            app(OrderService::class)->transition($order, 'ready');

            return $order->fresh();
        });
        $this->actingAs($this->owner($tenant))->postJson($this->dashUrl("/orders/{$order->id}/driver"), ['driver_id' => $driver->id])->assertOk();
        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/picked-up"))->assertOk();
        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/delivered"))->assertOk();

        return $order->fresh();
    }

    public function test_the_cashier_takes_the_drivers_cash_into_the_open_shift_and_then_closes(): void
    {
        $a = $this->cafe();
        $cashier = $this->staff($a, 'cashier', 'cashier@cafe-a.test');
        $driver = $this->staff($a, 'driver', 'karim@cafe-a.test');
        $this->actingAs($cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 100])->assertSessionHasNoErrors();
        $order = $this->deliveredInCash($a, $driver);

        // the shift does not close over cash still out, and the page says who holds it
        $this->actingAs($cashier)->post($this->dashUrl('/shifts/close'), ['closing_cash' => 100])->assertSessionHasErrors('shift');
        $this->actingAs($cashier)->get($this->dashUrl('/shifts'))->assertOk()->assertSee('data-driver="'.$driver->id.'"', false)->assertSee('فلوس مع الطيارين');
        $this->actingAs($this->staff($a, 'waiter', 'waiter@cafe-a.test'))->post($this->dashUrl("/shifts/drivers/{$driver->id}/settle"))->assertForbidden();

        $this->actingAs($cashier)->post($this->dashUrl("/shifts/drivers/{$driver->id}/settle"))->assertSessionHasNoErrors();
        $order->refresh();
        $shift = $this->as($a, fn () => Shift::query()->sole());
        $this->assertSame(['paid', 'cash', $shift->id, $cashier->id], [$order->payment_status, $order->payment_method, $order->shift_id, $order->cash_settled_by]);
        $this->actingAs($cashier)->get($this->dashUrl('/shifts'))->assertOk()->assertDontSee('data-driver-cash', false);

        $this->actingAs($cashier)->post($this->dashUrl('/shifts/close'), ['closing_cash' => 320])->assertSessionHasNoErrors();
        $shift->refresh();
        $this->assertNotNull($shift->closed_at);
        $this->assertSame([320.0, 320.0], [$shift->expected_cash, $shift->closing_cash]);
    }

    public function test_cash_the_cashier_took_for_a_delivered_order_at_the_pos_is_no_longer_with_the_driver(): void
    {
        $a = $this->cafe();
        $cashier = $this->staff($a, 'cashier', 'cashier@cafe-a.test');
        $driver = $this->staff($a, 'driver', 'karim@cafe-a.test');
        $this->actingAs($cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 0])->assertSessionHasNoErrors();
        $order = $this->deliveredInCash($a, $driver);

        $this->actingAs($cashier)->postJson($this->dashUrl("/pos/orders/{$order->id}/pay"), ['method' => 'cash'])->assertOk();

        $this->assertNotNull($order->fresh()->cash_settled_at);
        $this->actingAs($cashier)->post($this->dashUrl('/shifts/close'), ['closing_cash' => 220])->assertSessionHasNoErrors();
        $this->assertNotNull($this->as($a, fn () => Shift::query()->sole()->closed_at));
    }

    public function test_a_driver_switched_off_or_moved_to_another_role_still_hands_in_the_cash_they_hold(): void
    {
        $a = $this->cafe();
        $manager = $this->staff($a, 'manager', 'manager@cafe-a.test');
        $driver = $this->staff($a, 'driver', 'karim@cafe-a.test');
        $this->deliveredInCash($a, $driver);
        $this->as($a, function () use ($driver) {
            $driver->update(['is_active' => false]);
            $driver->syncRoles(['waiter']);
        });

        $row = $this->actingAs($manager)->getJson($this->dashUrl('/drivers/feed'))->assertOk()->json('drivers.0');
        $this->assertSame([$driver->id, 220, 1], [$row['id'], (int) $row['holding'], $row['holding_orders']]);

        $this->actingAs($manager)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 0])->assertSessionHasNoErrors();
        $this->actingAs($manager)->postJson($this->dashUrl("/drivers/{$driver->id}/settle"))->assertOk()->assertJsonPath('amount', 220);
        $this->assertSame([], $this->actingAs($manager)->getJson($this->dashUrl('/drivers/feed'))->json('drivers'));
    }

    public function test_a_manager_closes_the_shift_a_cashier_left_open_and_nobody_else_does(): void
    {
        $a = $this->cafe();
        $cashier = $this->staff($a, 'cashier', 'cashier@cafe-a.test');
        $manager = $this->staff($a, 'manager', 'manager@cafe-a.test');
        $this->actingAs($cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 150])->assertSessionHasNoErrors();
        $shift = $this->as($a, fn () => Shift::query()->sole());
        $b = $this->cafe('cafe-b');

        $this->actingAs($this->staff($a, 'cashier', 'second@cafe-a.test'))->post($this->dashUrl("/shifts/{$shift->id}/close"), ['closing_cash' => 150])->assertForbidden();
        $this->actingAs($this->owner($b))->post($this->dashUrl("/shifts/{$shift->id}/close"), ['closing_cash' => 150])->assertNotFound();
        $this->assertNull($shift->fresh()->closed_at);

        $this->actingAs($manager)->get($this->dashUrl('/shifts'))->assertOk()->assertSee('data-open-shift="'.$shift->id.'"', false);
        $this->actingAs($manager)->post($this->dashUrl("/shifts/{$shift->id}/close"), ['closing_cash' => 140, 'notes' => 'Cashier left'])->assertSessionHasNoErrors();
        $shift->refresh();
        $this->assertNotNull($shift->closed_at);
        $this->assertSame([150.0, 140.0, 'Cashier left'], [$shift->expected_cash, $shift->closing_cash, $shift->notes]);
        // closed once: the cashier's own close finds nothing open, the manager's second try is refused
        $this->actingAs($manager)->post($this->dashUrl("/shifts/{$shift->id}/close"), ['closing_cash' => 140])->assertSessionHasErrors('shift');
        $this->actingAs($cashier)->get($this->dashUrl('/shifts'))->assertOk()->assertDontSee('data-shift-stale', false);
    }

    public function test_with_the_blind_count_a_cashier_closes_without_the_expected_cash_and_the_manager_still_sees_it(): void
    {
        $a = $this->cafe();
        $cashier = $this->staff($a, 'cashier', 'cashier@cafe-a.test');
        $manager = $this->staff($a, 'manager', 'manager@cafe-a.test');
        $this->actingAs($cashier)->post($this->dashUrl('/pos/terminals/blind-count'), ['shift_blind_count' => 1])->assertForbidden();
        $this->actingAs($manager)->get($this->dashUrl('/pos/terminals'))->assertOk()->assertSee('data-blind-count', false);
        $this->actingAs($manager)->post($this->dashUrl('/pos/terminals/blind-count'), ['shift_blind_count' => 1])->assertSessionHasNoErrors();
        $this->actingAs($cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 150])->assertSessionHasNoErrors();

        // nothing on the cashier's side tells what the drawer should hold: the page, the drawer window, the day's report
        $this->actingAs($cashier)->get($this->dashUrl('/shifts'))->assertOk()->assertSee('data-blind-count', false)->assertDontSee('data-expected-cash', false);
        $this->actingAs($cashier)->postJson($this->dashUrl('/shifts/move'), ['type' => 'in', 'amount' => 50, 'reason' => 'Float'])->assertOk()->assertJsonPath('expected_cash', null);
        $this->actingAs($cashier)->get($this->dashUrl('/pos/z-report'))->assertForbidden();
        $this->actingAs($manager)->get($this->dashUrl('/shifts'))->assertOk()->assertSee('data-open-expected="200"', false);

        $this->actingAs($cashier)->post($this->dashUrl('/shifts/close'), ['closing_cash' => 180])->assertSessionHas('ok', 'اتقفلت الوردية. المدير هيراجع العدّ.');
        $shift = $this->as($a, fn () => Shift::query()->sole());
        $this->assertSame([200.0, 180.0], [$shift->expected_cash, $shift->closing_cash]);
        // closed: the report opens, the cashier's own history keeps the count without the gap, the manager reads it
        $this->actingAs($cashier)->get($this->dashUrl('/pos/z-report'))->assertOk();
        $this->actingAs($cashier)->get($this->dashUrl('/shifts'))->assertOk()->assertDontSee('data-shift-diff', false);
        $this->actingAs($manager)->get($this->dashUrl('/shifts'))->assertOk()->assertSee('data-shift-diff="-20"', false);
    }

    public function test_without_the_blind_count_the_cashier_sees_the_expected_cash(): void
    {
        $a = $this->cafe();
        $cashier = $this->staff($a, 'cashier', 'cashier@cafe-a.test');
        $this->actingAs($cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 150])->assertSessionHasNoErrors();

        $this->actingAs($cashier)->get($this->dashUrl('/shifts'))->assertOk()->assertSee('data-expected-cash', false)->assertDontSee('data-blind-count', false);
        $this->actingAs($cashier)->postJson($this->dashUrl('/shifts/move'), ['type' => 'in', 'amount' => 50, 'reason' => 'Float'])->assertOk()->assertJsonPath('expected_cash', 200);
        $this->actingAs($cashier)->get($this->dashUrl('/pos/z-report'))->assertOk();
    }

    public function test_the_morning_digest_names_the_shift_left_open_and_the_cash_not_handed_in(): void
    {
        $a = $this->cafe();
        $cashier = $this->staff($a, 'cashier', 'cashier@cafe-a.test');
        $driver = $this->staff($a, 'driver', 'karim@cafe-a.test');
        $this->actingAs($cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 0])->assertSessionHasNoErrors();
        $this->deliveredInCash($a, $driver);
        $digest = fn () => $this->as($a, fn () => app(DailyDigest::class)->build(CarbonImmutable::parse(local_today())->subDay()));

        // the same evening the shift is simply running
        $this->assertSame([], $digest()['open_shifts']);

        $this->travel(1)->days();
        $built = $digest();
        $this->assertSame(['Cashier'], array_column($built['open_shifts'], 'user'));
        $this->assertSame(220.0, $built['cash_held']);
        $this->assertFalse($built['empty']);
        $this->actingAs($cashier)->get($this->dashUrl('/shifts'))->assertOk()->assertSee('data-shift-stale', false);
    }
}
