<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Feedback;
use App\Models\Order;
use App\Models\PagerTicket;
use App\Models\Tenant;
use App\Services\Orders\OrderService;
use App\Services\Orders\Pager;
use App\Services\Settings;
use Tests\TenantTestCase;

/** The guest pager: the guest's phone rings when staff call (orders with the receipt QR, and the pager station). */
class GuestPagerTest extends TenantTestCase
{
    private function order(Tenant $tenant, string $type = 'takeaway', bool $table = false): Order
    {
        $item = $this->item($tenant, 'Latte', 60);

        return $this->as($tenant, fn () => app(OrderService::class)->place([
            'items' => [['item_id' => $item->id, 'qty' => 1]], 'customer_phone' => '01012345678', 'customer_name' => 'Mona',
            'source' => 'pos', 'type' => $type,
        ], Branch::query()->first(), $table ? DiningTable::query()->first() : null)->fresh());
    }

    private function move(Tenant $tenant, Order $order, string ...$statuses): Order
    {
        return $this->as($tenant, function () use ($order, $statuses) {
            foreach ($statuses as $status) {
                app(OrderService::class)->transition(Order::query()->find($order->id), $status);
            }

            return Order::query()->find($order->id);
        });
    }

    private function key(Tenant $tenant, Order $order): string
    {
        return $this->as($tenant, fn () => $order->pagerKey());
    }

    private function state(string $slug, string $key): array
    {
        return $this->getJson($this->subUrl($slug, "p/$key/state"))->assertOk()->json();
    }

    public function test_the_pager_page_opens_only_with_the_signed_key_in_its_own_restaurant(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $this->restaurant('cafe-b', 'full');
        $order = $this->order($a);
        $key = $this->key($a, $order);

        $this->get($this->subUrl('cafe-a', "p/$key"))->assertOk()->assertSee('#'.$order->number)
            ->assertSee('سيب الصفحة دي مفتوحة')->assertSee('جرّب الصوت')->assertDontSee('Mona')->assertDontSee('01012345678');
        $this->get($this->subUrl('cafe-b', "p/$key"))->assertNotFound();
        $this->get($this->subUrl('cafe-a', 'p/'.$order->id.'-'.str_repeat('a', 24)))->assertNotFound();
        $this->getJson($this->subUrl('cafe-b', "p/$key/state"))->assertNotFound();

        // a table order is served by the waiter, a delivery guest is at home: no pager
        $delivery = $this->order($a, 'delivery');
        $this->get($this->subUrl('cafe-a', 'p/'.$this->key($a, $delivery)))->assertNotFound();
        $dineIn = $this->order($a, 'dine_in', true);
        $this->get($this->subUrl('cafe-a', 'p/'.$this->key($a, $dineIn)))->assertNotFound();
    }

    public function test_the_state_feed_shows_only_that_order_and_marks_the_guest_connected(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $order = $this->order($a);
        $other = $this->order($a);
        $state = $this->state('cafe-a', $this->key($a, $order));

        $this->assertSame([$order->number, 'waiting', 0, false], [$state['number'], $state['phase'], $state['calls'], $state['done']]);
        $this->assertStringNotContainsString('Latte', json_encode($state));
        $this->assertNotNull($order->fresh()->pager_seen_at);
        $this->assertNull($other->fresh()->pager_seen_at);

        $feed = collect($this->actingAs($this->owner($a))->getJson($this->dashUrl('/orders/feed'))->assertOk()->json('orders'))->keyBy('id');
        $this->assertTrue($feed[$order->id]['pager']['connected']);
        $this->assertFalse($feed[$other->id]['pager']['seen']);

        // the page counts as open for 30 seconds after it last asked
        $this->travel(31)->seconds();
        $feed = collect($this->actingAs($this->owner($a))->getJson($this->dashUrl('/orders/feed'))->json('orders'))->keyBy('id');
        $this->assertFalse($feed[$order->id]['pager']['connected']);
        $this->assertTrue($feed[$order->id]['pager']['seen']);
    }

    public function test_ready_calls_the_guest_by_itself_and_staff_call_again(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $order = $this->order($a);
        $key = $this->key($a, $order);
        $this->move($a, $order, 'preparing');
        $this->assertSame(['preparing', 0], [$this->state('cafe-a', $key)['phase'], $this->state('cafe-a', $key)['calls']]);

        $this->move($a, $order, 'ready');
        $state = $this->state('cafe-a', $key);
        $this->assertSame(['ready', 1], [$state['phase'], $state['calls']]);

        $this->actingAs($this->owner($a))->postJson($this->dashUrl("/pager/orders/{$order->id}/call"))->assertOk()->assertJsonPath('pager.calls', 2);
        $this->assertSame(2, $this->state('cafe-a', $key)['calls']);
    }

    public function test_auto_call_can_be_switched_off(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $this->actingAs($this->owner($a))->post($this->dashUrl('/pager/settings'), ['expire_hours' => 4])->assertSessionHas('ok');
        $order = $this->move($a, $this->order($a), 'preparing', 'ready');

        $this->assertSame(0, $order->pager_calls);
        $this->assertSame('0', $this->as($a, fn () => Settings::get('pager_auto_call')));
    }

    public function test_picked_up_stops_the_calls(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $order = $this->move($a, $this->order($a), 'preparing', 'ready');
        $key = $this->key($a, $order);

        $this->postJson($this->subUrl('cafe-a', "p/$key/picked-up"))->assertOk()->assertJsonPath('done', true)->assertJsonPath('phase', 'picked_up');
        $this->assertNotNull($order->fresh()->pager_picked_up_at);
        $this->actingAs($this->owner($a))->postJson($this->dashUrl("/pager/orders/{$order->id}/call"))->assertStatus(422);
        $this->assertSame(1, $order->fresh()->pager_calls);
    }

    public function test_the_station_opens_numbered_tickets_once_per_branch_and_day(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $owner = $this->owner($a);
        $this->actingAs($owner)->get($this->dashUrl('/pager'))->assertOk()->assertSee('شاشة النداء');

        $this->actingAs($owner)->postJson($this->dashUrl('/pager/tickets'), ['number' => 120])->assertOk()
            ->assertJsonPath('ticket.number', 120)->assertJsonPath('next', 121);
        $this->actingAs($owner)->postJson($this->dashUrl('/pager/tickets'))->assertOk()->assertJsonPath('ticket.number', 121);
        $this->actingAs($owner)->postJson($this->dashUrl('/pager/tickets'), ['number' => 120])->assertStatus(422);

        // another branch keeps its own numbers, and tomorrow starts again
        $second = $this->as($a, fn () => Branch::query()->create(['name' => 'Maadi', 'slug' => 'maadi', 'is_active' => true]));
        $this->as($a, fn () => Pager::open($second, 120, null));
        $this->travel(1)->days();
        $this->actingAs($owner)->postJson($this->dashUrl('/pager/tickets'), ['number' => 120])->assertOk();

        $this->assertSame(4, PagerTicket::query()->where('tenant_id', 'cafe-a')->count());
    }

    public function test_a_station_ticket_rings_the_guest_and_closes(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $owner = $this->owner($a);
        $ticket = $this->actingAs($owner)->postJson($this->dashUrl('/pager/tickets'), ['number' => 7])->json('ticket');
        $token = PagerTicket::query()->findOrFail($ticket['id'])->token;

        $this->assertSame('waiting', $this->state('cafe-a', $token)['phase']);
        $this->actingAs($owner)->postJson($ticket['call'])->assertOk()->assertJsonPath('ticket.calls', 1)->assertJsonPath('ticket.connected', true);
        $this->assertSame(['ready', 1], array_values(array_intersect_key($this->state('cafe-a', $token), array_flip(['phase', 'calls']))));
        $this->get($this->subUrl('cafe-b', "p/$token"))->assertNotFound();

        $this->actingAs($owner)->postJson($ticket['picked'])->assertOk()->assertJsonPath('ticket.status', 'picked_up');
        $this->assertTrue($this->state('cafe-a', $token)['done']);
        $this->actingAs($owner)->postJson($ticket['call'])->assertStatus(422);
    }

    public function test_open_tickets_expire_after_the_hours_set(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $owner = $this->owner($a);
        $ticket = $this->actingAs($owner)->postJson($this->dashUrl('/pager/tickets'))->json('ticket');
        $token = PagerTicket::query()->findOrFail($ticket['id'])->token;

        $this->travel(4)->hours();
        $this->travel(1)->minutes();
        $this->assertSame('expired', collect($this->actingAs($owner)->getJson($this->dashUrl('/pager/feed'))->json('tickets'))->firstWhere('id', $ticket['id'])['status']);
        $this->assertSame(['expired', true], [$this->state('cafe-a', $token)['phase'], $this->state('cafe-a', $token)['done']]);
        $this->actingAs($owner)->postJson($ticket['call'])->assertStatus(422);
    }

    public function test_staff_need_the_pager_permission(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $order = $this->move($a, $this->order($a), 'preparing', 'ready');
        $driver = $this->staff($a, 'driver', 'd@cafe-a.test');
        $kitchen = $this->staff($a, 'kitchen', 'k@cafe-a.test');

        $this->actingAs($driver)->get($this->dashUrl('/pager'))->assertForbidden();
        $this->actingAs($driver)->postJson($this->dashUrl("/pager/orders/{$order->id}/call"))->assertForbidden();
        $this->actingAs($kitchen)->get($this->dashUrl('/pager'))->assertOk();
        $this->actingAs($kitchen)->postJson($this->dashUrl("/pager/orders/{$order->id}/call"))->assertOk();
        // the kitchen prints the guest's pager ticket, never the priced receipt
        $this->actingAs($kitchen)->get($this->dashUrl("/orders/{$order->id}/receipt?copy=pager"))->assertOk()->assertSee('امسح عشان نناديك');
        $this->actingAs($kitchen)->get($this->dashUrl("/orders/{$order->id}/receipt"))->assertForbidden();
    }

    public function test_the_free_plan_is_locked_and_the_add_on_opens_it(): void
    {
        $a = $this->restaurant('cafe-a', 'free');
        $order = $this->order($a);
        $owner = $this->owner($a);

        $this->actingAs($owner)->get($this->dashUrl('/pager'))->assertForbidden();
        $this->get($this->subUrl('cafe-a', 'p/'.$this->key($a, $order)))->assertNotFound();

        $a->subscription()->first()->addons()->create(['key' => 'pager_pack', 'quantity' => 1, 'unit_price' => 150]);
        $this->actingAs($owner)->get($this->dashUrl('/pager'))->assertOk();
        $this->get($this->subUrl('cafe-a', 'p/'.$this->key($a, $order)))->assertOk();
    }

    public function test_the_receipt_carries_the_pager_qr_while_the_order_is_open(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $order = $this->order($a);
        $owner = $this->owner($a);

        $this->actingAs($owner)->get($this->dashUrl("/orders/{$order->id}/receipt"))->assertOk()->assertSee('data-receipt-pager', false);
        $this->actingAs($owner)->get($this->dashUrl("/orders/{$order->id}/receipt?copy=kitchen"))->assertOk()->assertDontSee('data-receipt-pager', false);
        $this->move($a, $order, 'preparing', 'ready', 'served');
        $this->actingAs($owner)->get($this->dashUrl("/orders/{$order->id}/receipt"))->assertOk()->assertDontSee('data-receipt-pager', false);
    }

    public function test_the_reports_show_the_waits_calls_and_connected_guests(): void
    {
        $this->travelTo(today()->setTime(12, 0)); // the whole story on one day
        $a = $this->restaurant('cafe-a', 'full');
        $owner = $this->owner($a);
        $ticket = $this->actingAs($owner)->postJson($this->dashUrl('/pager/tickets'))->json('ticket');
        $token = PagerTicket::query()->findOrFail($ticket['id'])->token;
        $this->state('cafe-a', $token); // the guest opened the page
        $this->travel(4)->minutes();
        $this->actingAs($owner)->postJson($ticket['call'])->assertOk();
        $this->travel(2)->minutes();
        $this->postJson($this->subUrl('cafe-a', "p/$token/picked-up"))->assertOk();

        $report = $this->actingAs($owner)->get($this->dashUrl('/reports'))->assertOk()->assertSee('data-pager-report', false)->viewData('pager');
        $this->assertSame(['paged' => 1, 'calls' => 1, 'connected' => 100, 'wait' => 4, 'pickup' => 2], $report['total']);
    }

    public function test_after_the_pickup_only_a_happy_guest_gets_the_google_link(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $this->as($a, fn () => Settings::put(['google_review_url' => 'https://g.page/r/rosto']));
        $order = $this->move($a, $this->order($a), 'preparing', 'ready');
        $key = $this->key($a, $order);

        $this->postJson($this->subUrl('cafe-a', "p/$key/rate"), ['rating' => 5])->assertStatus(422); // not picked up yet
        $this->postJson($this->subUrl('cafe-a', "p/$key/picked-up"))->assertOk();
        $this->postJson($this->subUrl('cafe-a', "p/$key/rate"), ['rating' => 5])->assertOk()->assertJsonPath('google', 'https://g.page/r/rosto');
        $this->postJson($this->subUrl('cafe-a', "p/$key/rate"), ['rating' => 2, 'comment' => 'Cold'])->assertOk()->assertJsonPath('google', null);

        $this->assertSame([5, 2], Feedback::query()->where('tenant_id', 'cafe-a')->where('order_id', $order->id)->orderBy('id')->pluck('rating')->all());
    }

    public function test_guests_on_the_same_shop_wifi_all_get_their_rating_saved(): void
    {
        $a = $this->restaurant('cafe-a', 'full');

        // five guests, five orders, one shared address: every rating (and every complaint) is kept
        foreach (range(1, 5) as $i) {
            $key = $this->key($a, $this->move($a, $this->order($a), 'preparing', 'ready'));
            $this->postJson($this->subUrl('cafe-a', "p/$key/picked-up"))->assertOk();
            $this->postJson($this->subUrl('cafe-a', "p/$key/rate"), ['rating' => 2, 'comment' => "Guest $i"])->assertOk();
        }
        $this->assertSame(5, Feedback::query()->where('tenant_id', 'cafe-a')->whereNotNull('comment')->count());

        // one order still can't be flooded
        $this->postJson($this->subUrl('cafe-a', "p/$key/rate"), ['rating' => 1])->assertOk();
        $this->postJson($this->subUrl('cafe-a', "p/$key/rate"), ['rating' => 1])->assertOk();
        $this->postJson($this->subUrl('cafe-a', "p/$key/rate"), ['rating' => 1])->assertStatus(429);
    }
}
