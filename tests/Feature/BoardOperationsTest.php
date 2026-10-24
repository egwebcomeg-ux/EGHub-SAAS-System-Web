<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Shift;
use App\Models\TableCall;
use App\Models\TableSession;
use App\Models\Tenant;
use App\Services\Orders\ShiftService;
use App\Services\Settings;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** Board quick filters, food / drinks ready apart, table sessions with one bill, and the notifications bell. */
class BoardOperationsTest extends TenantTestCase
{
    private function cafe(string $slug = 'cafe-a'): Tenant
    {
        $tenant = $this->restaurant($slug, 'pro');
        app(Billing::class)->setAddon($tenant, 'pos_pack', 1);
        app(Billing::class)->setAddon($tenant, 'kitchen_pack', 1);

        return $tenant;
    }

    /** @return array{0:MenuItem,1:MenuItem} a burger (food category) and a juice (drinks category, guessed from its name) */
    private function menu(Tenant $tenant): array
    {
        return $this->as($tenant, function () {
            $food = Category::query()->create(['name' => 'Burgers']);
            $drinks = Category::query()->create(['name' => 'Cold drinks']);

            return [MenuItem::query()->create(['category_id' => $food->id, 'name' => 'Burger', 'price' => 100]),
                MenuItem::query()->create(['category_id' => $drinks->id, 'name' => 'Juice', 'price' => 30])];
        });
    }

    private function table(Tenant $tenant, string $name = '1'): DiningTable
    {
        return $this->as($tenant, fn () => DiningTable::query()->where('name', $name)->firstOrFail());
    }

    /** A guest order from a table QR. */
    private function qrOrder(Tenant $tenant, DiningTable $table, array $items): int
    {
        $this->get($this->subUrl($tenant->id, "t/{$table->qr_token}"))->assertRedirect();

        return $this->postJson($this->subUrl($tenant->id, 'order'), ['client_uuid' => (string) Str::uuid(), 'items' => $items])
            ->assertOk()->json('id') ?? $this->as($tenant, fn () => Order::query()->latest('id')->value('id'));
    }

    private function posOrder(array $data): int
    {
        return $this->postJson($this->dashUrl('/pos/orders'), $data + ['client_uuid' => (string) Str::uuid(), 'type' => 'takeaway'])
            ->assertOk()->json('id');
    }

    private function line(MenuItem $item, int $qty = 1): array
    {
        return ['item_id' => $item->id, 'qty' => $qty];
    }

    public function test_board_feed_counts_every_quick_filter(): void
    {
        $a = $this->cafe();
        [$burger, $juice] = $this->menu($a);
        $owner = $this->owner($a);
        $this->qrOrder($a, $this->table($a), [$this->line($burger), $this->line($juice)]);
        $this->actingAs($owner);
        $this->posOrder(['items' => [$this->line($juice)]]);
        $ready = $this->posOrder(['items' => [$this->line($burger)]]);
        $cancelled = $this->posOrder(['items' => [$this->line($juice)]]);
        $this->postJson($this->dashUrl("/orders/$ready/status"), ['status' => 'preparing'])->assertOk();
        $this->postJson($this->dashUrl("/orders/$ready/status"), ['status' => 'ready'])->assertOk();
        $this->postJson($this->dashUrl("/orders/$cancelled/status"), ['status' => 'cancelled'])->assertOk();

        $feed = $this->getJson($this->dashUrl('/orders/feed'))->assertOk();

        $this->assertSame([
            'all' => 3, 'kitchen' => 2, 'ready' => 1, 'dine_in' => 1, 'takeaway' => 2, 'delivery' => 0, 'food' => 2, 'drinks' => 2, 'confirm' => 0,
        ], $feed->json('counts'));
        $mixed = collect($feed->json('orders'))->firstWhere('type', 'dine_in');
        $this->assertSame(['food', 'drinks'], $mixed['kinds']);
        $this->assertSame(['food', 'drinks'], array_column($mixed['parts'], 'kind'));
        $this->assertSame([], collect($feed->json('orders'))->firstWhere('id', $ready)['parts']); // one kind: a single "ready"
    }

    public function test_food_and_drinks_are_marked_ready_apart_and_the_order_turns_ready_once(): void
    {
        $a = $this->cafe();
        [$burger, $juice] = $this->menu($a);
        $owner = $this->owner($a);
        $this->actingAs($owner);
        $id = $this->posOrder(['items' => [$this->line($burger, 2), $this->line($juice)]]);
        $this->postJson($this->dashUrl("/orders/$id/status"), ['status' => 'preparing'])->assertOk();

        $food = $this->postJson($this->dashUrl("/orders/$id/part-ready"), ['kind' => 'food'])->assertOk();
        $this->assertSame('preparing', $food->json('order.status'));
        $this->assertSame([['kind' => 'food', 'ready' => true], ['kind' => 'drinks', 'ready' => false]],
            array_map(fn ($p) => ['kind' => $p['kind'], 'ready' => $p['ready']], $food->json('order.parts')));

        // the bar's screen marks the drinks: the order moves to ready through the normal transition
        $this->postJson($this->dashUrl("/kitchen/orders/$id"), ['action' => 'ready', 'kind' => 'drinks'])->assertJsonPath('status', 'ready');
        $this->postJson($this->dashUrl("/orders/$id/part-ready"), ['kind' => 'drinks'])->assertOk()->assertJsonPath('order.status', 'ready');

        $this->as($a, function () use ($id) {
            $this->assertSame(1, OrderStatusHistory::query()->where('order_id', $id)->where('to_status', 'ready')->count());
            $this->assertNotNull(Order::query()->find($id)->ready_at);
        });
        $this->postJson($this->dashUrl("/orders/$id/part-ready"), ['kind' => 'snacks'])->assertStatus(422);
    }

    public function test_single_kind_orders_keep_one_ready(): void
    {
        $a = $this->cafe();
        [$burger] = $this->menu($a);
        $this->actingAs($this->owner($a));
        $id = $this->posOrder(['items' => [$this->line($burger)]]);

        $this->postJson($this->dashUrl("/orders/$id/status"), ['status' => 'preparing'])->assertOk();
        $this->postJson($this->dashUrl("/orders/$id/status"), ['status' => 'ready'])->assertOk()->assertJsonPath('order.parts', []);
        // the whole order ready marks every line ready (the kitchen screens drop it)
        $this->assertSame(0, $this->as($a, fn () => Order::query()->find($id)->items()->whereNull('ready_at')->count()));
    }

    public function test_table_session_collects_orders_and_closes_with_one_payment(): void
    {
        $a = $this->cafe();
        [$burger, $juice] = $this->menu($a);
        $this->as($a, fn () => Settings::put(['service_percent' => '10', 'tax_percent' => '0']));
        $table = $this->table($a, '2');
        $owner = $this->owner($a);

        $guest = $this->qrOrder($a, $table, [$this->line($burger)]);           // 100 + 10 service
        $this->actingAs($owner);
        $waiter = $this->posOrder(['type' => 'dine_in', 'table_id' => $table->id, 'items' => [$this->line($juice, 2)]]); // 60 + 6
        $dropped = $this->posOrder(['type' => 'dine_in', 'table_id' => $table->id, 'items' => [$this->line($juice)]]);
        $this->postJson($this->dashUrl("/orders/$dropped/status"), ['status' => 'cancelled'])->assertOk();
        $takeaway = $this->posOrder(['items' => [$this->line($juice)]]);

        $session = $this->as($a, fn () => TableSession::query()->sole());
        $this->as($a, function () use ($session, $guest, $waiter, $dropped, $takeaway) {
            $this->assertSame([$guest, $waiter, $dropped], Order::query()->where('table_session_id', $session->id)->orderBy('id')->pluck('id')->all());
            $this->assertNull(Order::query()->find($takeaway)->table_session_id);
            $this->assertSame(176.0, $session->fresh()->total);
        });

        $tile = collect($this->getJson($this->dashUrl('/orders/tables/feed'))->assertOk()->json('tables'))->firstWhere('id', $table->id);
        $this->assertSame(2, $tile['session']['orders']);
        $this->assertSame(2, $tile['session']['unpaid']);

        $bill = $this->getJson($this->dashUrl("/orders/tables/sessions/{$session->id}"))->assertOk();
        $this->assertSame('176', $bill->json('total'));
        $this->assertSame('16', $bill->json('service'));
        $this->assertSame([['qty' => 1, 'name' => 'Burger'], ['qty' => 2, 'name' => 'Juice']],
            array_map(fn ($l) => ['qty' => $l['qty'], 'name' => $l['name']], $bill->json('lines')));

        // unpaid orders block closing; the payment needs the cashier's open shift
        $this->postJson($this->dashUrl("/orders/tables/sessions/{$session->id}/close"))->assertStatus(422);
        $this->postJson($this->dashUrl("/orders/tables/sessions/{$session->id}/pay"), ['method' => 'cash'])->assertStatus(422);
        $this->post($this->dashUrl('/shifts/open'), ['opening_cash' => 100]);
        $this->postJson($this->dashUrl("/orders/tables/sessions/{$session->id}/pay"), ['method' => 'cash'])->assertOk()->assertJsonPath('paid_orders', 2);
        $this->postJson($this->dashUrl("/orders/tables/sessions/{$session->id}/pay"), ['method' => 'cash'])->assertOk()->assertJsonPath('paid_orders', 0);

        $summary = $this->as($a, fn () => app(ShiftService::class)->summary(Shift::query()->sole()));
        $this->assertSame(2, $summary['orders']);
        $this->assertSame(176.0, $summary['by_method']['cash']);
        $this->assertSame(276.0, $summary['expected_cash']);

        // still in the kitchen: not yet
        $this->postJson($this->dashUrl("/orders/tables/sessions/{$session->id}/close"))->assertStatus(422);
        foreach ([$guest, $waiter] as $id) {
            $this->postJson($this->dashUrl("/orders/$id/status"), ['status' => 'preparing'])->assertOk();
            $this->postJson($this->dashUrl("/orders/$id/status"), ['status' => 'ready'])->assertOk();
        }
        $this->as($a, fn () => TableCall::query()->create(['branch_id' => $table->branch_id, 'dining_table_id' => $table->id, 'kind' => 'bill']));
        $this->get($this->dashUrl("/orders/tables/sessions/{$session->id}/receipt"))->assertOk()->assertSee('Burger')->assertSee('176');
        $this->postJson($this->dashUrl("/orders/tables/sessions/{$session->id}/close"))->assertOk();
        $this->postJson($this->dashUrl("/orders/tables/sessions/{$session->id}/close"))->assertStatus(422);

        $this->as($a, function () use ($session, $guest, $waiter) {
            $this->assertNotNull($session->fresh()->closed_at);
            $this->assertSame(['served', 'served'], Order::query()->whereIn('id', [$guest, $waiter])->pluck('status')->all());
            $this->assertSame(0, TableCall::query()->whereNull('handled_at')->count());
        });
        $tile = collect($this->getJson($this->dashUrl('/orders/tables/feed'))->json('tables'))->firstWhere('id', $table->id);
        $this->assertNull($tile['session']);

        // the next guests open a new session
        $next = $this->posOrder(['type' => 'dine_in', 'table_id' => $table->id, 'items' => [$this->line($juice)]]);
        $this->as($a, function () use ($next, $session) {
            $this->assertSame(2, TableSession::query()->count());
            $this->assertNotSame($session->id, Order::query()->find($next)->table_session_id);
        });
    }

    public function test_table_sessions_respect_rights_restaurants_and_branches(): void
    {
        $a = $this->cafe();
        $b = $this->cafe('cafe-b');
        [$burger] = $this->menu($a);
        $table = $this->table($a);
        $this->qrOrder($a, $table, [$this->line($burger)]);
        $session = $this->as($a, fn () => TableSession::query()->sole());

        $this->actingAs($this->owner($b))->getJson($this->dashUrl("/orders/tables/sessions/{$session->id}"))->assertNotFound();
        $this->assertNull(collect($this->actingAs($this->owner($b))->getJson($this->dashUrl('/orders/tables/feed'))->json('tables'))->firstWhere('session', '!=', null));

        $this->actingAs($this->staff($a, 'kitchen', 'k@a.test'))->getJson($this->dashUrl('/orders/tables/feed'))->assertForbidden();
        // the call center updates orders but does not take money
        $phone = $this->staff($a, 'callcenter', 'cc@a.test');
        $this->actingAs($phone)->getJson($this->dashUrl("/orders/tables/sessions/{$session->id}"))->assertOk();
        $this->actingAs($phone)->postJson($this->dashUrl("/orders/tables/sessions/{$session->id}/pay"), ['method' => 'cash'])->assertForbidden();

        $second = $this->as($a, fn () => Branch::query()->create(['name' => 'Second', 'slug' => 'second']));
        $waiter = $this->staff($a, 'waiter', 'w@a.test');
        $waiter->forceFill(['branch_id' => $second->id])->save();
        $this->actingAs($waiter)->getJson($this->dashUrl("/orders/tables/sessions/{$session->id}"))->assertNotFound();
        $this->assertNotContains($table->id, collect($this->actingAs($waiter)->getJson($this->dashUrl('/orders/tables/feed'))->json('tables'))->pluck('id'));
    }

    public function test_notifications_follow_rights_and_branch_and_count_unread(): void
    {
        $a = $this->cafe();
        [$burger] = $this->menu($a);
        $table = $this->table($a);
        $this->qrOrder($a, $table, [$this->line($burger)]);
        $this->as($a, fn () => TableCall::query()->create(['branch_id' => $table->branch_id, 'dining_table_id' => $table->id, 'kind' => 'waiter']));
        $owner = $this->owner($a);

        $feed = $this->actingAs($owner)->getJson($this->dashUrl('/notifications'))->assertOk();
        $this->assertSame(2, $feed->json('unread'));
        $this->assertEqualsCanonicalizing(['order', 'call'], array_column($feed->json('items'), 'kind'));

        $this->travel(1)->minutes();
        $this->postJson($this->dashUrl('/notifications/seen'))->assertOk();
        $this->assertSame(0, $this->getJson($this->dashUrl('/notifications'))->json('unread'));

        $this->travel(1)->minutes();
        $id = $this->as($a, fn () => Order::query()->latest('id')->value('id'));
        $this->postJson($this->dashUrl("/orders/$id/status"), ['status' => 'preparing']);
        $this->postJson($this->dashUrl("/orders/$id/status"), ['status' => 'ready']);
        $feed = $this->getJson($this->dashUrl('/notifications'));
        $this->assertSame(1, $feed->json('unread'));
        $this->assertSame('ready', $feed->json('items.0.kind'));

        // kitchen staff have no order rights; a waiter of another branch sees nothing of this one
        $this->assertSame([], $this->actingAs($this->staff($a, 'kitchen', 'k@a.test'))->getJson($this->dashUrl('/notifications'))->json('items'));
        $second = $this->as($a, fn () => Branch::query()->create(['name' => 'Second', 'slug' => 'second']));
        $waiter = $this->staff($a, 'waiter', 'w@a.test');
        $waiter->forceFill(['branch_id' => $second->id])->save();
        $this->assertSame(0, $this->actingAs($waiter)->getJson($this->dashUrl('/notifications'))->json('unread'));
        // another restaurant's owner
        $this->assertSame([], $this->actingAs($this->owner($this->cafe('cafe-b')))->getJson($this->dashUrl('/notifications'))->json('items'));
    }
}
