<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Settings;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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

    public function test_the_call_center_does_not_wire_wallet_top_ups_it_has_no_dialogs_for(): void
    {
        // Business includes the wallet: the POS gets the top-up dialogs, the call center must not ask pos.js for them
        $tenant = $this->restaurant('cc-biz', 'business');
        app(Billing::class)->setAddon($tenant, 'callcenter_pack', 1);
        $page = $this->actingAs($this->owner($tenant))->get($this->dashUrl('/call-center'))->assertOk();
        $page->assertDontSee('data-topup-open', false);
        preg_match('#<script type="application/json" id="pos-data">(.*?)</script>#s', $page->getContent(), $m);
        $data = json_decode($m[1] ?? '{}', true);
        $this->assertSame('callcenter', $data['mode'] ?? null);
        $this->assertFalse($data['walletManage']);
        $this->assertTrue($data['wallet']); // paying from the balance stays available
    }

    public function test_no_access_without_pack_or_permission(): void
    {
        $plain = $this->restaurant('cafe-plain', 'pro');
        $this->actingAs($this->owner($plain))->get($this->dashUrl('/call-center'))->assertForbidden();

        $a = $this->withCallCenter('cafe-a');
        $kitchen = $this->staff($a, 'kitchen', 'k@a.test');
        $this->actingAs($kitchen)->get($this->dashUrl('/call-center'))->assertForbidden();
    }

    public function test_pinned_location_must_be_inside_the_zone_area(): void
    {
        $a = $this->withCallCenter('cafe-a');
        $latte = $this->item($a, 'Latte', 60);
        $zone = $this->zone($a, ['polygon' => [[30.0, 31.0], [30.0, 31.1], [30.1, 31.1], [30.1, 31.0]]]);
        $sub = $this->zone($a, ['name' => 'Block 7', 'parent_id' => $zone->id, 'fee' => null, 'min_order' => null]);
        $agent = $this->staff($a, 'callcenter', 'agent@a.test');

        $this->actingAs($agent)->postJson($this->dashUrl('/call-center/orders'), $this->order($latte->id, 2, $zone, ['lat' => 30.5, 'lng' => 31.05]))
            ->assertStatus(422);
        // a sub-area without its own drawing is checked against its parent's area
        $this->actingAs($agent)->postJson($this->dashUrl('/call-center/orders'), $this->order($latte->id, 2, $sub, ['lat' => 30.05, 'lng' => 31.05]))
            ->assertOk();

        $customer = $this->as($a, fn () => Customer::query()->first());
        $this->assertSame(30.05, $customer->addresses[0]['lat']);
        $this->assertSame(30.05, $this->as($a, fn () => Order::query()->first())->delivery_address['lat']);
    }

    /** @return array<string, mixed> */
    private function screenData(TestResponse $page): array
    {
        preg_match('#<script type="application/json" id="pos-data">(.*?)</script>#s', $page->getContent(), $m);

        return json_decode($m[1] ?? '{}', true);
    }

    public function test_the_panel_shows_customer_delivery_and_order_with_the_two_step_area_pick(): void
    {
        $a = $this->withCallCenter('cafe-a');
        $nasr = $this->zone($a, ['name' => 'Nasr City', 'fee' => 30, 'min_order' => 150]);
        $street = $this->zone($a, ['name' => 'Abbas St', 'parent_id' => $nasr->id, 'fee' => 40, 'min_order' => null]);
        $agent = $this->staff($a, 'callcenter', 'agent@a.test');

        $page = $this->actingAs($agent)->get($this->dashUrl('/call-center'))->assertOk();
        // two zones (customer & delivery, the order), the steps for narrow screens and the always-visible footer
        foreach (['data-cc-panel', 'data-cc-tab="customer"', 'data-cc-tab="delivery"', 'data-cc-tab="order"', 'data-cc-summary', 'data-cc-footer',
            'data-customer-phone', 'data-lookup', 'data-caller', 'data-addresses', 'data-zone-root', 'data-zone-sub', 'data-zone ', 'data-zone-facts',
            'data-min-warning', 'data-address ', 'data-address-label', 'data-map-toggle', 'data-lines', 'data-totals', 'data-send'] as $hook) {
            $page->assertSee($hook, false);
        }
        $page->assertDontSee('data-pay>', false)->assertDontSee('data-coupon-apply', false); // paid later, on delivery

        $data = $this->screenData($page);
        $this->assertSame('manual', $data['zoneMode']);
        $zones = collect($data['zones'])->keyBy('id');
        $this->assertSame($nasr->id, $zones[$street->id]['parent']);
        $this->assertSame('Abbas St', $zones[$street->id]['short']);
        $this->assertSame('Nasr City › Abbas St', $zones[$street->id]['name']);
        $this->assertEquals(150, $zones[$street->id]['min']); // the street takes the main area's minimum
        $this->assertNull($zones[$nasr->id]['parent']);

        // drawn areas: one zone list with the map pin next to it, no two-step pickers
        $this->as($a, fn () => Settings::put(['delivery_zone_mode' => 'map']));
        $page = $this->actingAs($agent)->get($this->dashUrl('/call-center'))->assertOk()
            ->assertSee('data-zone ', false)->assertSee('data-map-toggle', false)->assertDontSee('data-zone-root', false);
        $this->assertSame('map', $this->screenData($page)['zoneMode']);
    }

    public function test_the_caller_card_carries_loyalty_and_stays_inside_the_restaurant(): void
    {
        $a = $this->withCallCenter('cafe-a');
        $b = $this->withCallCenter('cafe-b');
        $agent = $this->staff($a, 'callcenter', 'agent@a.test');
        $this->as($a, function () {
            $customer = Customer::query()->create(['phone' => '01012345678', 'name' => 'Mona']);
            $program = LoyaltyProgram::query()->create(['type' => 'visits', 'title' => 'Coffee card', 'stamps_required' => 8, 'reward_label' => 'Free coffee']);
            LoyaltyCard::query()->create(['loyalty_program_id' => $program->id, 'customer_id' => $customer->id, 'stamps' => 5, 'rewards_available' => 1]);
        });
        // the same mobile at another restaurant, with its own card and address
        $this->as($b, function () {
            $customer = Customer::query()->create(['phone' => '01012345678', 'name' => 'Other', 'addresses' => [['label' => 'B', 'zone_id' => 1, 'text' => 'elsewhere']]]);
            $program = LoyaltyProgram::query()->create(['type' => 'visits', 'title' => 'B card', 'stamps_required' => 5, 'reward_label' => 'x']);
            LoyaltyCard::query()->create(['loyalty_program_id' => $program->id, 'customer_id' => $customer->id, 'stamps' => 4]);
        });

        $this->actingAs($agent)->getJson($this->dashUrl('/call-center/customer?phone=01012345678'))->assertOk()
            ->assertJsonPath('name', 'Mona')->assertJsonPath('addresses', [])->assertJsonPath('orders', [])
            ->assertJsonCount(1, 'loyalty')
            ->assertJsonPath('loyalty.0', ['title' => 'Coffee card', 'stamps' => 5, 'of' => 8, 'rewards' => 1])
            ->assertJsonPath('tier', null)->assertJsonPath('balance', null); // basic plan: no tiers, no wallet

        // a paused program is not shown on the card
        $this->as($a, fn () => LoyaltyProgram::query()->update(['is_active' => false]));
        $this->actingAs($agent)->getJson($this->dashUrl('/call-center/customer?phone=01012345678'))->assertJsonCount(0, 'loyalty');

        // the lookup needs the call-center permission
        $kitchen = $this->staff($a, 'kitchen', 'k@a.test');
        $this->actingAs($kitchen)->getJson($this->dashUrl('/call-center/customer?phone=01012345678'))->assertForbidden();
    }
}
