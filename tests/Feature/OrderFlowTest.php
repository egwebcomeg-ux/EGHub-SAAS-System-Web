<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchItem;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Settings;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** Guest orders from a table QR, priced on the server, then moved across the live board. */
class OrderFlowTest extends TenantTestCase
{
    /** Latte 65 with a required Size group (Small +0 / Large +15) and optional Extras (Shot +10). */
    private function latte(Tenant $tenant): MenuItem
    {
        $item = $this->item($tenant, 'Latte', 65);

        return $this->as($tenant, function () use ($item) {
            $size = $item->optionGroups()->create(['name' => 'Size', 'type' => 'single', 'is_required' => true]);
            $size->options()->create(['name' => 'Small', 'price' => 0]);
            $size->options()->create(['name' => 'Large', 'price' => 15]);
            $extras = $item->optionGroups()->create(['name' => 'Extras', 'type' => 'multiple', 'max_choices' => 2]);
            $extras->options()->create(['name' => 'Shot', 'price' => 10]);

            return $item->load('optionGroups.options');
        });
    }

    private function scanTable(Tenant $tenant, string $name = '1'): DiningTable
    {
        $table = $this->as($tenant, fn () => DiningTable::query()->where('name', $name)->first());
        $this->get($this->subUrl($tenant->id, "t/{$table->qr_token}"))->assertRedirect();

        return $table;
    }

    private function option(MenuItem $item, string $name): int
    {
        return $item->optionGroups->flatMap->options->firstWhere('name', $name)->id;
    }

    private function order(Tenant $tenant, array $items, array $extra = [])
    {
        return $this->postJson($this->subUrl($tenant->id, 'order'), $extra + ['client_uuid' => (string) Str::uuid(), 'items' => $items]);
    }

    public function test_guest_orders_from_a_table_and_the_server_prices_it(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Settings::put(['service_percent' => '12', 'tax_percent' => '14']));
        $latte = $this->latte($a);
        $table = $this->scanTable($a, '3');

        $res = $this->order($a, [[
            'item_id' => $latte->id, 'qty' => 2, 'notes' => 'no sugar',
            'option_ids' => [$this->option($latte, 'Large'), $this->option($latte, 'Shot')],
            'price' => 1, // ignored: the client never sets prices
        ]], ['customer_name' => 'Mona'])->assertOk()->assertJsonPath('number', 1);

        $order = $this->as($a, fn () => Order::query()->with('items', 'history')->first());
        $this->assertSame($table->id, $order->dining_table_id);
        $this->assertSame('dine_in', $order->type);
        $this->assertSame(90.0, $order->items[0]->unit_price);      // 65 + 15 + 10
        $this->assertSame(180.0, $order->subtotal);
        $this->assertSame(21.6, $order->service);                    // 12%
        $this->assertSame(28.22, $order->tax);                       // 14% of 201.6
        $this->assertSame(229.82, $order->total);
        $this->assertSame(['Large', 'Shot'], array_column($order->items[0]->options, 'name'));
        $this->assertSame('new', $order->history->first()->to_status);

        $this->get($res->json('url'))->assertOk()->assertSee('طلب رقم 1')->assertSee('Latte');
    }

    public function test_ordering_needs_a_scanned_table(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->latte($a);
        $this->order($a, [['item_id' => $latte->id, 'qty' => 1, 'option_ids' => [$this->option($latte, 'Small')]]])
            ->assertStatus(422);
    }

    public function test_required_choice_foreign_option_and_sold_out_are_rejected(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->latte($a);
        $mocha = $this->latte($a)->fresh(); // second item with its own options
        $this->scanTable($a);

        $this->order($a, [['item_id' => $latte->id, 'qty' => 1]])->assertStatus(422);                    // size missing
        $mochaLarge = $this->as($a, fn () => $mocha->load('optionGroups.options')->optionGroups->flatMap->options->firstWhere('name', 'Large')->id);
        $this->order($a, [['item_id' => $latte->id, 'qty' => 1, 'option_ids' => [$mochaLarge]]])->assertStatus(422); // other item's option
        $this->order($a, [['item_id' => $latte->id, 'qty' => 1, 'option_ids' => [$this->option($latte, 'Small'), $this->option($latte, 'Large')]]])
            ->assertStatus(422);                                                                             // two sizes

        $this->as($a, fn () => $latte->update(['is_sold_out' => true]));
        $this->order($a, [['item_id' => $latte->id, 'qty' => 1, 'option_ids' => [$this->option($latte, 'Small')]]])->assertStatus(422);
        $this->assertSame(0, $this->as($a, fn () => Order::query()->count()));
    }

    public function test_items_of_another_restaurant_cannot_be_ordered(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $itemOfB = $this->item($b, 'Secret', 10);
        $this->scanTable($a);

        $this->order($a, [['item_id' => $itemOfB->id, 'qty' => 1]])->assertStatus(422);
    }

    public function test_a_retried_submit_does_not_duplicate_the_order(): void
    {
        $a = $this->restaurant('cafe-a');
        $item = $this->item($a, 'Water', 10);
        $this->scanTable($a);
        $uuid = (string) Str::uuid();

        $first = $this->order($a, [['item_id' => $item->id, 'qty' => 1]], ['client_uuid' => $uuid])->assertOk();
        $again = $this->order($a, [['item_id' => $item->id, 'qty' => 1]], ['client_uuid' => $uuid])->assertOk();

        $this->assertSame($first->json('url'), $again->json('url'));
        $this->assertSame(1, $this->as($a, fn () => Order::query()->count()));
    }

    public function test_order_numbers_are_per_restaurant(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $ia = $this->item($a, 'Tea', 20);
        $ib = $this->item($b, 'Tea', 20);

        $this->scanTable($a);
        $this->order($a, [['item_id' => $ia->id, 'qty' => 1]])->assertJsonPath('number', 1);
        $this->order($a, [['item_id' => $ia->id, 'qty' => 1]])->assertJsonPath('number', 2);
        $this->scanTable($b);
        $this->order($b, [['item_id' => $ib->id, 'qty' => 1]])->assertJsonPath('number', 1);
    }

    public function test_restaurant_can_turn_ordering_off(): void
    {
        $a = $this->restaurant('cafe-a');
        $item = $this->item($a, 'Tea', 20);
        $this->as($a, fn () => Settings::put(['ordering_enabled' => '0']));
        $this->scanTable($a);
        $this->order($a, [['item_id' => $item->id, 'qty' => 1]])->assertStatus(422);
    }

    public function test_branch_price_override_is_charged_on_business(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $item = $this->item($a, 'Tea', 20);
        $this->as($a, fn () => BranchItem::query()->create(['branch_id' => Branch::query()->value('id'), 'menu_item_id' => $item->id, 'price' => 25]));
        $this->scanTable($a);

        $this->order($a, [['item_id' => $item->id, 'qty' => 2]])->assertOk();
        $this->assertSame(50.0, $this->as($a, fn () => Order::query()->value('subtotal') + 0.0));
    }

    public function test_board_shows_own_orders_and_moves_them(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $ia = $this->item($a, 'Tea A', 20);
        $ib = $this->item($b, 'Tea B', 20);
        $this->scanTable($a);
        $this->order($a, [['item_id' => $ia->id, 'qty' => 1]])->assertOk();
        $this->scanTable($b);
        $this->order($b, [['item_id' => $ib->id, 'qty' => 1]])->assertOk();

        $owner = $this->owner($a);
        $feed = $this->actingAs($owner)->getJson($this->dashUrl('/orders/feed'))->assertOk();
        $this->assertSame(['Tea A'], collect($feed->json('orders'))->pluck('items.0.name')->all());

        $id = $feed->json('orders.0.id');
        $this->actingAs($owner)->postJson($this->dashUrl("/orders/$id/status"), ['status' => 'preparing'])->assertOk();
        $this->actingAs($owner)->postJson($this->dashUrl("/orders/$id/status"), ['status' => 'served'])->assertStatus(422); // must be ready first
        $this->actingAs($owner)->postJson($this->dashUrl("/orders/$id/status"), ['status' => 'ready'])->assertOk();
        $this->actingAs($owner)->postJson($this->dashUrl("/orders/$id/status"), ['status' => 'served'])->assertOk();

        $order = $this->as($a, fn () => Order::query()->with('history')->find($id));
        $this->assertSame('served', $order->status);
        $this->assertNotNull($order->preparing_at);
        $this->assertNotNull($order->completed_at);
        $this->assertSame(['new', 'preparing', 'ready', 'served'], $order->history->pluck('to_status')->all());

        $orderOfB = $this->as($b, fn () => Order::query()->value('id'));
        $this->actingAs($owner)->postJson($this->dashUrl("/orders/$orderOfB/status"), ['status' => 'preparing'])->assertNotFound();
    }

    public function test_board_respects_roles_and_branches(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $item = $this->item($a, 'Tea', 20);
        $second = $this->as($a, fn () => Branch::query()->create(['name' => 'Second', 'slug' => 'second']));
        $this->as($a, fn () => DiningTable::query()->create(['branch_id' => $second->id, 'name' => 'S1']));
        $this->scanTable($a, '1');
        $this->order($a, [['item_id' => $item->id, 'qty' => 1]])->assertOk();
        $this->scanTable($a, 'S1');
        $this->order($a, [['item_id' => $item->id, 'qty' => 1]])->assertOk();

        $this->actingAs($this->staff($a, 'kitchen', 'k@a.test'))->getJson($this->dashUrl('/orders/feed'))->assertForbidden();

        $waiter = $this->staff($a, 'waiter', 'w@a.test');
        $waiter->forceFill(['branch_id' => $second->id])->save();
        $feed = $this->actingAs($waiter)->getJson($this->dashUrl('/orders/feed'))->assertOk();
        $this->assertSame(['S1'], collect($feed->json('orders'))->pluck('table')->all());
    }

    public function test_tracking_page_and_status(): void
    {
        $a = $this->restaurant('cafe-a');
        $item = $this->item($a, 'Tea', 20);
        $this->scanTable($a);
        $url = $this->order($a, [['item_id' => $item->id, 'qty' => 1]])->json('url');

        $this->getJson($url.'/status')->assertOk()->assertJsonPath('status', 'new');
        $this->get($this->subUrl('cafe-a', 'o/'.str_repeat('0', 24)))->assertNotFound();
    }

    public function test_starter_plan_menu_is_view_only(): void
    {
        $tenant = $this->restaurant('cafe-starter', 'starter');
        $latte = $this->item($tenant, 'Latte', 65);

        $this->get($this->subUrl('cafe-starter'))->assertOk()->assertSee('Latte');
        $this->postJson($this->subUrl('cafe-starter', 'order'), ['client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $latte->id, 'qty' => 1]]])
            ->assertStatus(422);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }
}
