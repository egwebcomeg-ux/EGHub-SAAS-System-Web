<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Settings;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Orders move with the screens a restaurant really uses: a paid counter sale is done at once without a kitchen in
 * use, paid orders left ready close by themselves, the board takes payments where there is no cashier screen, and
 * nothing forgotten pushes new orders off the screens.
 */
class OrderFlowScreensTest extends TenantTestCase
{
    private Tenant $tenant;

    private MenuItem $latte;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->restaurant('cafe-a', 'pro');
        app(Billing::class)->setAddon($this->tenant, 'pos_pack', 1);
        app(Billing::class)->setAddon($this->tenant, 'kitchen_pack', 1);
        $this->latte = $this->item($this->tenant, 'Latte', 60);
        $this->cashier = $this->staff($this->tenant, 'cashier', 'cashier@cafe-a.test');
    }

    private function sale(bool $paid = true): Order
    {
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $this->latte->id, 'qty' => 1]],
        ] + ($paid ? ['pay' => 'cash'] : []))->assertOk();

        return $this->as($this->tenant, fn () => Order::query()->latest('id')->firstOrFail());
    }

    private function settings(array $values): void
    {
        $this->as($this->tenant, fn () => Settings::put($values));
    }

    public function test_a_paid_counter_sale_is_done_at_once_unless_the_kitchen_is_in_use(): void
    {
        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 100])->assertSessionHasNoErrors();

        // nobody moves counter sales and the kitchen screen was never opened: done once paid
        $sale = $this->sale();
        $this->assertSame(['served', 'paid'], [$sale->status, $sale->payment_status]);
        $this->assertNotNull($sale->completed_at);
        // an unpaid ticket waits for its payment
        $this->assertSame('new', $this->sale(paid: false)->status);

        // the kitchen screen opened: sales go through the kitchen again
        $this->actingAs($this->owner($this->tenant))->getJson($this->dashUrl('/kitchen/feed'))->assertOk();
        $this->assertSame('new', $this->sale()->status);

        // the restaurant's own choice wins
        $this->settings(['order_flow' => 'counter']);
        $this->assertSame('served', $this->sale()->status);
        $this->settings(['order_flow' => 'kitchen', 'kitchen_seen_at' => '']);
        $this->assertSame('new', $this->sale()->status);
    }

    public function test_a_paid_order_left_ready_closes_by_itself_after_the_set_minutes(): void
    {
        $this->settings(['order_flow' => 'kitchen']);
        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 100])->assertSessionHasNoErrors();
        $paid = $this->sale();
        $unpaid = $this->sale(paid: false);
        foreach ([$paid, $unpaid] as $order) {
            $this->actingAs($this->owner($this->tenant))->postJson($this->dashUrl("/kitchen/orders/{$order->id}"), ['action' => 'ready'])->assertOk();
        }

        $this->travel(30)->minutes();
        $this->artisan('egmenu:auto-serve')->assertSuccessful();
        $this->assertSame('ready', $paid->fresh()->status);

        $this->travel(31)->minutes();
        $this->artisan('egmenu:auto-serve')->assertSuccessful();
        $this->assertSame(['served', 'ready'], [$paid->fresh()->status, $unpaid->fresh()->status]);

        // 0 = never
        $this->settings(['order_auto_serve_minutes' => '0']);
        $this->actingAs($this->cashier)->postJson($this->dashUrl("/pos/orders/{$unpaid->id}/pay"), ['method' => 'cash'])->assertOk();
        $this->travel(2)->hours();
        $this->artisan('egmenu:auto-serve')->assertSuccessful();
        $this->assertSame('ready', $unpaid->fresh()->status);
    }

    public function test_without_the_cashier_screen_the_board_takes_the_payment_of_a_takeaway(): void
    {
        $b = $this->restaurant('cafe-b', 'pro');
        $latte = $this->item($b, 'Latte', 60);
        $this->as($b, fn () => Settings::put(['online_takeaway' => '1']));
        $this->postJson($this->subUrl('cafe-b', 'order'), [
            'client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $latte->id, 'qty' => 2]], 'type' => 'takeaway',
            'customer_name' => 'Mona', 'customer_phone' => '01012345678',
        ])->assertOk();
        $order = $this->as($b, fn () => Order::query()->latest('id')->firstOrFail());
        $owner = $this->owner($b);

        $card = collect($this->actingAs($owner)->getJson($this->dashUrl('/orders/feed'))->json('orders'))->firstWhere('id', $order->id);
        $this->assertTrue($card['can_pay']);
        $this->actingAs($owner)->postJson($this->dashUrl("/orders/{$order->id}/pay"), ['method' => 'gold'])->assertStatus(422);
        $this->actingAs($owner)->postJson($this->dashUrl("/orders/{$order->id}/pay"), ['method' => 'instapay'])->assertOk()->assertJsonPath('order.paid', true);
        $this->assertSame(['paid', 'instapay'], [$order->fresh()->payment_status, $order->fresh()->payment_method]);
        $this->actingAs($owner)->postJson($this->dashUrl("/orders/{$order->id}/pay"), ['method' => 'cash'])->assertStatus(422);

        // with the cashier screen, payments go through the POS (its drawer and shift)
        $this->actingAs($this->owner($this->tenant))->postJson($this->dashUrl("/orders/{$order->id}/pay"), ['method' => 'cash'])->assertNotFound();
    }

    public function test_online_ordering_alone_brings_the_board_where_its_orders_are_taken(): void
    {
        $c = $this->restaurant('cafe-c', 'free');
        $this->assertFalse($this->as($c, fn () => Entitlements::current()->allows('order_board')));
        app(Billing::class)->setAddon($c, 'online_pack', 1);
        Entitlements::flush();
        $this->assertTrue($this->as($c, fn () => Entitlements::current()->allows('order_board')));
    }

    public function test_forgotten_orders_are_counted_and_never_hide_the_new_ones(): void
    {
        $this->settings(['order_flow' => 'kitchen']);
        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 100])->assertSessionHasNoErrors();
        $old = $this->sale(paid: false);
        $this->travel(13)->hours();
        $new = $this->sale(paid: false);

        $owner = $this->owner($this->tenant);
        $feed = $this->actingAs($owner)->getJson($this->dashUrl('/orders/feed'))->assertOk();
        $this->assertSame(1, $feed->json('stale'));
        $tickets = collect($this->actingAs($owner)->getJson($this->dashUrl('/kitchen/feed'))->json('tickets'))->pluck('id')->all();
        $this->assertSame([$new->id], $tickets);
        $this->assertNotContains($old->id, $tickets);
    }

    public function test_a_category_left_on_all_screens_shows_on_every_station_and_is_bumped_there(): void
    {
        $this->settings(['order_flow' => 'kitchen']);
        [$grill, $burger] = $this->as($this->tenant, function () {
            $grill = KitchenStation::query()->create(['name' => 'Grill', 'name_en' => '', 'sort_order' => 1]);
            $mains = Category::query()->create(['name' => 'Mains', 'kitchen_station_id' => $grill->id]);

            return [$grill, MenuItem::query()->create(['category_id' => $mains->id, 'name' => 'Burger', 'price' => 100])];
        });
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway',
            'items' => [['item_id' => $burger->id, 'qty' => 1], ['item_id' => $this->latte->id, 'qty' => 1]],
        ])->assertOk();
        $order = $this->as($this->tenant, fn () => Order::query()->latest('id')->firstOrFail());

        $kitchen = $this->owner($this->tenant);
        $ticket = collect($this->actingAs($kitchen)->getJson($this->dashUrl('/kitchen/feed?station='.$grill->id))->json('tickets'))->firstWhere('id', $order->id);
        $this->assertSame(['Burger', 'Latte'], collect($ticket['items'])->pluck('name')->sort()->values()->all());
        $this->actingAs($kitchen)->postJson($this->dashUrl("/kitchen/orders/{$order->id}?station={$grill->id}"), ['action' => 'ready'])->assertOk();
        $this->assertSame('ready', $order->fresh()->status);
    }
}
