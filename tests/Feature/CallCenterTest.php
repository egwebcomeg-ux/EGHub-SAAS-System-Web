<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class CallCenterTest extends TenantTestCase
{
    private function withCallCenter(string $slug): Tenant
    {
        $tenant = $this->restaurant($slug, 'basic');
        app(Billing::class)->setAddon($tenant, 'callcenter_pack', 1);
        return $tenant;
    }

    private function zone(Tenant $tenant, array $attrs = []): DeliveryZone
    {
        return $this->as($tenant, fn () => DeliveryZone::query()->create($attrs + [
            'branch_id' => Branch::query()->first()->id, 'name' => 'Maadi', 'fee' => 25, 'min_order' => 100,
        ]));
    }

    private function order(int $itemId, int $qty, ?DeliveryZone $zone, array $extra = []): array
    {
        return $extra + [
            'client_uuid' => (string) Str::uuid(), 'type' => $zone ? 'delivery' : 'takeaway',
            'items' => [['item_id' => $itemId, 'qty' => $qty]], 'customer_name' => 'Mona', 'customer_phone' => '01012345678',
            'zone_id' => $zone?->id, 'address' => $zone ? '12 Street 9, floor 3' : null, 'address_label' => 'Home',
        ];
    }

    public function test_delivery_order_adds_zone_fee_and_saves_the_address(): void
    {
        $a = $this->withCallCenter('cafe-a');
        $latte = $this->item($a, 'Latte', 60);
        $zone = $this->zone($a);
        $agent = $this->staff($a, 'callcenter', 'agent@a.test');

        $this->actingAs($agent)->get($this->dashUrl('/call-center'))->assertOk()->assertSee('Maadi');
        $this->actingAs($agent)->postJson($this->dashUrl('/call-center/orders'), $this->order($latte->id, 2, $zone))->assertOk();

        $order = $this->as($a, fn () => Order::query()->first());
        $this->assertSame('delivery', $order->type);
        $this->assertSame('callcenter', $order->source);
        $this->assertSame(25.0, $order->delivery_fee);
        $this->assertSame(145.0, $order->total);
        $this->assertSame('12 Street 9, floor 3', $order->delivery_address['text']);

        $customer = $this->as($a, fn () => Customer::query()->first());
        $this->assertSame('callcenter', $customer->source);
        $this->assertSame($zone->id, $customer->addresses[0]['zone_id']);

        // the next call finds the caller with the saved address and last order
        $this->actingAs($agent)->getJson($this->dashUrl('/call-center/customer?phone=01012345678'))
            ->assertOk()->assertJsonPath('found', true)->assertJsonPath('name', 'Mona')
            ->assertJsonPath('addresses.0.text', '12 Street 9, floor 3')->assertJsonPath('orders_count', 1);

        // same address again is not duplicated
        $this->actingAs($agent)->postJson($this->dashUrl('/call-center/orders'), $this->order($latte->id, 2, $zone))->assertOk();
        $this->assertCount(1, $this->as($a, fn () => Customer::query()->first())->addresses);
    }

    public function test_minimum_order_zone_branch_and_isolation(): void
    {
        $a = $this->withCallCenter('cafe-a');
        $b = $this->withCallCenter('cafe-b');
        $latte = $this->item($a, 'Latte', 60);
        $zone = $this->zone($a);
        $foreignZone = $this->zone($b);
        $agent = $this->staff($a, 'callcenter', 'agent@a.test');

        // 60 < minimum 100
        $this->actingAs($agent)->postJson($this->dashUrl('/call-center/orders'), $this->order($latte->id, 1, $zone))
            ->assertStatus(422);
        // another restaurant's zone
        $this->actingAs($agent)->postJson($this->dashUrl('/call-center/orders'), $this->order($latte->id, 2, $foreignZone))
            ->assertStatus(422);
        // an inactive zone
        $this->as($a, fn () => $zone->update(['is_active' => false]));
        $this->actingAs($agent)->postJson($this->dashUrl('/call-center/orders'), $this->order($latte->id, 2, $zone))
            ->assertStatus(422);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());

        // pickup needs no zone
        $this->actingAs($agent)->postJson($this->dashUrl('/call-center/orders'), $this->order($latte->id, 1, null))->assertOk();

        // the other restaurant's customers are invisible
        $this->as($b, fn () => Customer::query()->create(['phone' => '01111111111', 'name' => 'Other']));
        $this->actingAs($agent)->getJson($this->dashUrl('/call-center/customer?phone=01111111111'))->assertJsonPath('found', false);
    }

    public function test_no_access_without_pack_or_permission(): void
    {
        $plain = $this->restaurant('cafe-plain', 'pro');
        $this->actingAs($this->owner($plain))->get($this->dashUrl('/call-center'))->assertForbidden();

        $a = $this->withCallCenter('cafe-a');
        $kitchen = $this->staff($a, 'kitchen', 'k@a.test');
        $this->actingAs($kitchen)->get($this->dashUrl('/call-center'))->assertForbidden();
    }
}
