<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Orders\OrderService;
use App\Services\Settings;
use Illuminate\Support\Carbon;
use Tests\TenantTestCase;

/** The guest's "where is my order" page (/o/{token}) and the status feed it polls. */
class OrderTrackingTest extends TenantTestCase
{
    /** Places an order of $type in $tenant (a table for dine-in, a zone for delivery). */
    private function place(Tenant $tenant, string $type, array $extra = []): Order
    {
        $item = $this->item($tenant, 'Latte', 60);

        return $this->as($tenant, function () use ($item, $type, $extra) {
            $branch = Branch::query()->first();
            $table = $type === 'dine_in' ? DiningTable::query()->first() : null;

            return app(OrderService::class)->place($extra + [
                'items' => [['item_id' => $item->id, 'qty' => 2]], 'customer_phone' => '01012345678', 'source' => 'pos', 'type' => $type,
            ], $branch, $table)->fresh();
        });
    }

    private function move(Tenant $tenant, Order $order, string ...$statuses): void
    {
        $this->as($tenant, function () use ($order, $statuses) {
            foreach ($statuses as $status) {
                app(OrderService::class)->transition(Order::query()->find($order->id), $status);
            }
        });
    }

    private function zone(Tenant $tenant, ?int $minutes): DeliveryZone
    {
        return $this->as($tenant, fn () => DeliveryZone::query()->create([
            'branch_id' => Branch::query()->first()->id, 'name' => 'Maadi', 'fee' => 25, 'estimated_minutes' => $minutes, 'is_active' => true,
        ]));
    }

    private function statusOf(Order $order, string $slug = 'cafe-a'): array
    {
        return $this->getJson($this->subUrl($slug, "o/{$order->tracking_token}/status"))->assertOk()->json();
    }

    public function test_each_order_type_shows_its_own_steps(): void
    {
        $a = $this->restaurant('cafe-a');

        $dineIn = $this->place($a, 'dine_in');
        $this->get($this->subUrl('cafe-a', "o/{$dineIn->tracking_token}"))->assertOk()
            ->assertSeeInOrder(['data-step="new"', 'اتبعت', 'data-step="preparing"', 'بيتحضر', 'data-step="ready"', 'جاهز', 'data-step="served"', 'اتقدّم'], false)
            ->assertDontSee('data-step="on_way"', false)
            ->assertSee('?reorder='.$dineIn->tracking_token, false)
            ->assertDontSee('data-contact', false); // the guest sits in the restaurant

        $takeaway = $this->place($a, 'takeaway');
        $this->assertSame(['new', 'preparing', 'ready', 'served'], array_column($this->statusOf($takeaway)['steps'], 'key'));
        $this->get($this->subUrl('cafe-a', "o/{$takeaway->tracking_token}"))->assertOk()
            ->assertSee('جاهز للاستلام')->assertSee('اتسلّم')->assertDontSee('data-step="on_way"', false);

        $zone = $this->zone($a, 30);
        $delivery = $this->place($a, 'delivery', ['delivery_zone' => $zone, 'delivery_address' => ['zone' => 'Maadi', 'text' => '9 Road 233']]);
        $this->as($a, fn () => Settings::put(['phone' => '0223456789', 'whatsapp' => '01099999999']));
        $this->move($a, $delivery, 'preparing', 'ready', 'on_way');

        $this->get($this->subUrl('cafe-a', "o/{$delivery->tracking_token}"))->assertOk()
            ->assertSeeInOrder(['data-step="ready"', 'data-step="on_way"', 'خرج مع الطيار', 'data-step="served"', 'وصل'], false)
            ->assertSee('9 Road 233')->assertSee('25')                      // address and delivery fee
            ->assertSee('tel:0223456789', false)->assertSee('wa.me/201099999999', false);

        $status = $this->statusOf($delivery);
        $this->assertSame('on_way', $status['status']);
        $this->assertSame(3, $status['step']);
        $this->assertSame([true, true, true, true, false], array_column($status['steps'], 'done'));
        $this->assertSame([false, false, false, true, false], array_column($status['steps'], 'current'));
        $this->assertNotNull($status['steps'][3]['time']); // when it left, read from the status history
    }

    public function test_expected_time_comes_from_the_prep_minutes_the_zone_and_the_scheduled_time(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Settings::put(['online_prep_minutes' => '30']));
        $this->travelTo(Carbon::parse('2026-10-19 20:00:00'));

        // takeaway: ready = placed + the restaurant's preparation minutes, then accepted + those minutes
        $takeaway = $this->place($a, 'takeaway');
        $eta = $this->statusOf($takeaway)['eta'];
        $this->assertSame('ready', $eta['kind']);
        $this->assertTrue(Carbon::parse($eta['at'])->equalTo(now()->addMinutes(30)));
        $this->assertSame(30, $eta['minutes']);
        $this->get($this->subUrl('cafe-a', "o/{$takeaway->tracking_token}"))->assertSee('متوقع يجهز حوالي 8:30')->assertSee('باقي حوالي 30 دقيقة');

        $this->travel(10)->minutes();
        $this->move($a, $takeaway, 'preparing');
        $this->assertTrue(Carbon::parse($this->statusOf($takeaway)['eta']['at'])->equalTo(now()->addMinutes(30)));

        // delivery: arrival = ready + the zone's minutes; once ready, ready time + zone minutes
        $zone = $this->zone($a, 25);
        $delivery = $this->place($a, 'delivery', ['delivery_zone' => $zone, 'delivery_address' => ['zone' => 'Maadi', 'text' => 'x']]);
        $eta = $this->statusOf($delivery)['eta'];
        $this->assertSame('arrive', $eta['kind']);
        $this->assertTrue(Carbon::parse($eta['at'])->equalTo(now()->addMinutes(55)));
        $this->move($a, $delivery, 'preparing', 'ready');
        $this->assertTrue(Carbon::parse($this->statusOf($delivery)['eta']['at'])->equalTo(now()->addMinutes(25)));

        // a zone without minutes: only the ready time
        $plain = $this->place($a, 'delivery', ['delivery_zone' => $this->zone($a, null), 'delivery_address' => ['zone' => 'Maadi', 'text' => 'x']]);
        $this->assertSame('ready', $this->statusOf($plain)['eta']['kind']);

        // scheduled for later: the chosen time
        $later = $this->place($a, 'takeaway');
        $this->as($a, fn () => Order::query()->whereKey($later->id)->update(['scheduled_for' => now()->addDay()->setTime(13, 15)]));
        $eta = $this->statusOf($later)['eta'];
        $this->assertSame('scheduled', $eta['kind']);
        $this->assertTrue(Carbon::parse($eta['at'])->equalTo(now()->addDay()->setTime(13, 15)));
        $this->assertNull($eta['minutes']);

        // late: no past time is promised
        $this->travel(3)->hours();
        $this->assertSame('خلاص قرّب', $this->statusOf($plain)['eta']['text']);

        // finished orders have no expected time
        $this->move($a, $takeaway, 'ready', 'served');
        $this->assertNull($this->statusOf($takeaway)['eta']);
    }

    public function test_cancelled_order_shows_a_clear_state_and_stops_polling(): void
    {
        $a = $this->restaurant('cafe-a');
        $order = $this->place($a, 'takeaway');
        $this->as($a, fn () => app(OrderService::class)->transition(Order::query()->find($order->id), 'cancelled', null, 'Out of milk'));

        $this->get($this->subUrl('cafe-a', "o/{$order->tracking_token}"))->assertOk()
            ->assertSee('الطلب ده اتلغى')->assertSee('data-cancel-reason', false)->assertSee('Out of milk')
            ->assertDontSee('data-steps', false)->assertDontSee('data-live', false)
            ->assertDontSee('/status', false); // no polling script

        $status = $this->statusOf($order);
        $this->assertTrue($status['final']);
        $this->assertTrue($status['cancelled']);
        $this->assertSame(-1, $status['step']);
        $this->assertNull($status['eta']);
    }

    public function test_status_feed_keeps_status_and_label_and_adds_the_tracking_fields(): void
    {
        $a = $this->restaurant('cafe-a');
        $order = $this->place($a, 'dine_in');
        // a drink in the order: food and drinks readiness are shown apart
        $this->as($a, fn () => Order::query()->find($order->id)->items()->update(['prep_kind' => 'drinks']));
        $this->as($a, function () use ($order) {
            $line = Order::query()->find($order->id)->items()->first()->replicate();
            $line->prep_kind = 'food';
            $line->save();
        });

        $this->getJson($this->subUrl('cafe-a', "o/{$order->tracking_token}/status"))->assertOk()
            ->assertJsonPath('status', 'new')->assertJsonPath('label', 'جديد')->assertJsonPath('final', false)
            ->assertJsonStructure([
                'status', 'label', 'final', 'cancelled', 'headline', 'step', 'now',
                'steps' => [['key', 'label', 'done', 'current', 'time']],
                'parts' => [['kind', 'label', 'ready']],
                'eta' => ['kind', 'at', 'time', 'text', 'late', 'minutes'],
            ])
            ->assertJsonPath('parts.0.kind', 'food')->assertJsonPath('parts.1.kind', 'drinks')->assertJsonPath('parts.1.ready', false);
    }

    public function test_a_token_of_another_restaurant_is_not_found(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->restaurant('cafe-b');
        $order = $this->place($a, 'takeaway');

        $this->get($this->subUrl('cafe-b', "o/{$order->tracking_token}"))->assertNotFound();
        $this->getJson($this->subUrl('cafe-b', "o/{$order->tracking_token}/status"))->assertNotFound();
        $this->get($this->pathUrl('cafe-b', "o/{$order->tracking_token}"))->assertNotFound();
        $this->get($this->subUrl('cafe-a', "o/{$order->tracking_token}"))->assertOk();
    }
}
