<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Invoice;
use App\Models\KitchenStation;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PlatformAdmin;
use App\Models\Shift;
use App\Models\Tenant;
use App\Services\Entitlements;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** Operations add-ons: POS pack (POS, shifts, customer display) and kitchen pack (KDS, stations). */
class OperationsTest extends TenantTestCase
{
    private function withPacks(string $slug, array $packs, string $plan = 'basic'): Tenant
    {
        $tenant = $this->restaurant($slug, $plan);
        foreach ($packs as $pack) {
            app(Billing::class)->setAddon($tenant, $pack, 1);
        }
        return $tenant;
    }

    private function posOrder(array $extra = [])
    {
        return $this->postJson($this->dashUrl('/pos/orders'), $extra + ['client_uuid' => (string) Str::uuid(), 'type' => 'takeaway']);
    }

    public function test_operations_are_locked_without_the_add_on(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $owner = $this->owner($a);
        $this->actingAs($owner)->get($this->dashUrl('/pos'))->assertForbidden();
        $this->actingAs($owner)->get($this->dashUrl('/kitchen'))->assertForbidden();
        $this->actingAs($owner)->get($this->dashUrl('/shifts'))->assertForbidden();
        $this->get($this->menuUrl('cafe-a/board'))->assertNotFound();
    }

    public function test_pos_pack_unlocks_pos_shifts_and_display(): void
    {
        $a = $this->withPacks('cafe-a', ['pos_pack']);
        $rights = Entitlements::for($a);
        $this->assertTrue($rights->allows('pos'));
        $this->assertTrue($rights->allows('shifts'));
        $this->assertTrue($rights->allows('customer_display'));
        $this->assertFalse($rights->allows('kitchen_display'));

        $this->item($a, 'Latte', 60);
        $this->actingAs($this->owner($a))->get($this->dashUrl('/pos'))->assertOk()->assertSee('Latte');
        $this->get($this->menuUrl('cafe-a/board'))->assertOk();
    }

    public function test_cashier_shift_takeaway_cash_sale_and_close(): void
    {
        $a = $this->withPacks('cafe-a', ['pos_pack']);
        $item = $this->item($a, 'Latte', 60);
        $cashier = $this->staff($a, 'cashier', 'c@a.test');

        // no shift: payment refused, order not created
        $this->actingAs($cashier)->posOrder(['items' => [['item_id' => $item->id, 'qty' => 2]], 'pay' => 'cash'])->assertStatus(422);

        $this->actingAs($cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 500])->assertSessionHasNoErrors();
        $this->actingAs($cashier)->posOrder(['items' => [['item_id' => $item->id, 'qty' => 2]], 'pay' => 'cash'])
            ->assertOk()->assertJsonPath('paid', true);
        $this->actingAs($cashier)->posOrder(['items' => [['item_id' => $item->id, 'qty' => 1]], 'pay' => 'card'])->assertOk();
        $this->actingAs($cashier)->post($this->dashUrl('/shifts/move'), ['type' => 'out', 'amount' => 30, 'reason' => 'ice'])->assertSessionHasNoErrors();

        $shift = $this->as($a, fn () => Shift::query()->first());
        $summary = app(\App\Services\Orders\ShiftService::class)->summary($shift);
        $this->assertSame(120.0, $summary['by_method']['cash']);
        $this->assertSame(60.0, $summary['by_method']['card']);
        $this->assertSame(590.0, $summary['expected_cash']); // 500 + 120 − 30

        $this->actingAs($cashier)->post($this->dashUrl('/shifts/close'), ['closing_cash' => 580])->assertSessionHasNoErrors();
        $shift->refresh();
        $this->assertNotNull($shift->closed_at);
        $this->assertSame(-10.0, app(\App\Services\Orders\ShiftService::class)->summary($shift)['difference']);
    }

    public function test_discount_needs_permission_and_dine_in_needs_a_table(): void
    {
        $a = $this->withPacks('cafe-a', ['pos_pack']);
        $item = $this->item($a, 'Latte', 100);
        $cashier = $this->staff($a, 'cashier', 'c@a.test');
        $manager = $this->staff($a, 'manager', 'm@a.test');

        $this->actingAs($cashier)->posOrder(['items' => [['item_id' => $item->id, 'qty' => 1]], 'discount' => 20])->assertForbidden();
        $this->actingAs($manager)->posOrder(['items' => [['item_id' => $item->id, 'qty' => 1]], 'discount' => 20])->assertOk();
        $this->assertSame(80.0, $this->as($a, fn () => Order::query()->latest('id')->value('total') + 0.0));

        $this->actingAs($manager)->posOrder(['type' => 'dine_in', 'items' => [['item_id' => $item->id, 'qty' => 1]]])->assertStatus(422);
        $table = $this->as($a, fn () => DiningTable::query()->first());
        $this->actingAs($manager)->posOrder(['type' => 'dine_in', 'table_id' => $table->id, 'items' => [['item_id' => $item->id, 'qty' => 1]]])
            ->assertOk()->assertJsonPath('paid', false);

        // the unpaid table order is listed, then settled (manager has no open shift → refused, opens one, pays)
        $open = $this->actingAs($manager)->getJson($this->dashUrl('/pos/open'))->assertOk()->json('orders');
        $this->assertCount(2, $open); // the discounted takeaway was not paid either
        $id = collect($open)->firstWhere('table', $table->name)['id'];
        $this->actingAs($manager)->postJson($this->dashUrl("/pos/orders/$id/pay"), ['method' => 'card'])->assertStatus(422);
        $this->actingAs($manager)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 0]);
        $this->actingAs($manager)->postJson($this->dashUrl("/pos/orders/$id/pay"), ['method' => 'card'])->assertOk();
        $this->actingAs($manager)->postJson($this->dashUrl("/pos/orders/$id/pay"), ['method' => 'card'])->assertStatus(422); // already paid

        $this->actingAs($manager)->get($this->dashUrl("/orders/$id/receipt"))->assertOk()->assertSee('Latte');
    }

    public function test_kitchen_display_flow(): void
    {
        $a = $this->withPacks('cafe-a', ['pos_pack', 'kitchen_pack']);
        $item = $this->item($a, 'Burger', 120);
        $this->actingAs($this->owner($a))->post($this->dashUrl('/shifts/open'), ['opening_cash' => 0]);
        $this->actingAs($this->owner($a))->posOrder(['items' => [['item_id' => $item->id, 'qty' => 1]], 'notes' => 'no onion'])->assertOk();

        $kitchen = $this->staff($a, 'kitchen', 'k@a.test');
        $feed = $this->actingAs($kitchen)->getJson($this->dashUrl('/kitchen/feed'))->assertOk();
        $this->assertSame('Burger', $feed->json('tickets.0.items.0.name'));
        $id = $feed->json('tickets.0.id');

        $this->actingAs($kitchen)->postJson($this->dashUrl("/kitchen/orders/$id"), ['action' => 'start'])->assertJsonPath('status', 'preparing');
        $this->actingAs($kitchen)->postJson($this->dashUrl("/kitchen/orders/$id"), ['action' => 'ready'])->assertJsonPath('status', 'ready');
        $this->assertSame([], $this->actingAs($kitchen)->getJson($this->dashUrl('/kitchen/feed'))->json('tickets'));

        // kitchen staff can't take orders
        $this->actingAs($kitchen)->get($this->dashUrl('/pos'))->assertForbidden();
    }

    public function test_stations_split_an_order_and_it_is_ready_when_all_are_done(): void
    {
        $a = $this->withPacks('cafe-a', ['pos_pack', 'kitchen_pack']);
        [$grill, $bar, $burger, $juice] = $this->as($a, function () {
            $grill = KitchenStation::query()->create(['name' => 'Grill']);
            $bar = KitchenStation::query()->create(['name' => 'Bar']);
            $food = Category::query()->create(['name' => 'Food', 'kitchen_station_id' => $grill->id]);
            $drinks = Category::query()->create(['name' => 'Drinks', 'kitchen_station_id' => $bar->id]);
            return [$grill, $bar, MenuItem::query()->create(['category_id' => $food->id, 'name' => 'Burger', 'price' => 100]),
                MenuItem::query()->create(['category_id' => $drinks->id, 'name' => 'Juice', 'price' => 30])];
        });
        $owner = $this->owner($a);
        $this->actingAs($owner)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 0]);
        $id = $this->actingAs($owner)->posOrder(['items' => [['item_id' => $burger->id, 'qty' => 1], ['item_id' => $juice->id, 'qty' => 1]]])->json('id');

        $grillFeed = $this->actingAs($owner)->getJson($this->dashUrl("/kitchen/feed?station={$grill->id}"))->json('tickets.0.items');
        $this->assertSame(['Burger'], array_column($grillFeed, 'name'));

        $this->actingAs($owner)->postJson($this->dashUrl("/kitchen/orders/$id?station={$grill->id}"), ['action' => 'ready'])->assertJsonPath('status', 'preparing');
        $this->actingAs($owner)->postJson($this->dashUrl("/kitchen/orders/$id?station={$bar->id}"), ['action' => 'ready'])->assertJsonPath('status', 'ready');
    }

    public function test_board_shows_only_numbers(): void
    {
        $a = $this->withPacks('cafe-a', ['pos_pack']);
        $item = $this->item($a, 'Secret Burger', 100);
        $this->actingAs($this->owner($a))->posOrder(['items' => [['item_id' => $item->id, 'qty' => 1]], 'customer_name' => 'Mona']);

        $feed = $this->getJson($this->menuUrl('cafe-a/board/feed'))->assertOk();
        $this->assertSame([1], $feed->json('preparing'));
        $this->assertStringNotContainsString('Mona', $feed->getContent());
        $this->assertStringNotContainsString('Secret', $feed->getContent());
    }

    public function test_restaurant_buys_an_add_on_and_it_renews_with_the_plan(): void
    {
        $a = $this->restaurant('cafe-a', 'basic');
        $a->subscription()->update(['current_period_end' => now()->addDays(30)]); // prorated: 30 days left = one month
        $owner = $this->owner($a);
        $this->actingAs($owner)->post($this->dashUrl('/billing/addons/kitchen_pack'))->assertRedirect();
        $invoice = Invoice::query()->where('tenant_id', 'cafe-a')->where('kind', 'addon')->firstOrFail();
        $this->assertSame(149.0, $invoice->total);

        $admin = PlatformAdmin::query()->create(['name' => 'A', 'email' => 'a@egmenu.test', 'password' => 'Secret-pass-123']);
        $this->actingAs($admin, 'admin')->post($this->adminUrl("/invoices/{$invoice->id}/paid"), ['gateway' => 'manual', 'amount' => 149]);
        Entitlements::flush();
        $this->assertTrue(Entitlements::for($a)->allows('kitchen_display'));

        $quote = app(Billing::class)->quote($a->fresh(), \App\Models\Plan::query()->where('code', 'basic')->first(), 1);
        $this->assertSame(149.0 + 149.0, $quote['total']); // plan + kitchen pack
    }

    public function test_admin_grants_and_removes_an_add_on(): void
    {
        $a = $this->restaurant('cafe-a', 'basic');
        $admin = PlatformAdmin::query()->create(['name' => 'A', 'email' => 'a@egmenu.test', 'password' => 'Secret-pass-123']);
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/tenants/cafe-a/addons'), ['addons' => ['pos_pack' => 1, 'branches' => 2]]);
        Entitlements::flush();
        $this->assertTrue(Entitlements::for($a)->allows('pos'));
        $this->assertSame(3, Entitlements::for($a)->limit('branches'));

        $this->actingAs($admin, 'admin')->post($this->adminUrl('/tenants/cafe-a/addons'), ['addons' => ['pos_pack' => 0, 'branches' => 0]]);
        Entitlements::flush();
        $this->assertFalse(Entitlements::for($a)->allows('pos'));
    }
}
