<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * Call-center delivery from a branch priced by distance: the agent finds the customer's place (typed address with
 * suggestions from saved / previous addresses and the geocoder, or the map pin) or picks a rough distance ring, and
 * the server prices it from the branch's rings, never from the browser.
 */
class CallCenterDistanceTest extends TenantTestCase
{
    /** The branch pin in Tanta. 0.009° of latitude ≈ 1 km. */
    private const PIN = [30.7865, 31.0004];

    private const RINGS = [
        ['km' => 2, 'fee' => 30, 'min' => 0, 'minutes' => 20],
        ['km' => 4, 'fee' => 45, 'min' => 100, 'minutes' => 35],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['geocoding.driver' => 'nominatim', 'geocoding.nominatim.wait' => 0]);
    }

    private function distanceRestaurant(string $slug): Tenant
    {
        $tenant = $this->restaurant($slug, 'basic');
        app(Billing::class)->setAddon($tenant, 'callcenter_pack', 1);
        $this->as($tenant, fn () => Branch::query()->firstOrFail()->update([
            'lat' => self::PIN[0], 'lng' => self::PIN[1], 'delivery_pricing' => 'distance', 'delivery_rings' => self::RINGS,
        ]));

        return $tenant;
    }

    private function agent(Tenant $tenant): User
    {
        return $this->staff($tenant, 'callcenter', 'agent@'.$tenant->id.'.test');
    }

    private function place(User $agent, int $itemId, array $extra, int $qty = 2): TestResponse
    {
        return $this->actingAs($agent)->postJson($this->dashUrl('/call-center/orders'), $extra + [
            'client_uuid' => (string) Str::uuid(), 'type' => 'delivery', 'items' => [['item_id' => $itemId, 'qty' => $qty]],
            'customer_name' => 'Mona', 'customer_phone' => '01012345678', 'address' => 'شارع البحر، عمارة 12، الدور 3',
        ]);
    }

    /** Nominatim's answer for a street ~1 km north of the branch. @return array<string,mixed> */
    private function bahrStreet(float $lat = 30.7955): array
    {
        return [
            'place_id' => 1, 'lat' => (string) $lat, 'lon' => '31.0004', 'category' => 'highway', 'type' => 'residential', 'name' => 'شارع البحر',
            'display_name' => 'شارع البحر، قسم أول طنطا، طنطا، الغربية، مصر',
            'address' => ['road' => 'شارع البحر', 'house_number' => '12', 'suburb' => 'قسم أول طنطا', 'city' => 'طنطا', 'state' => 'الغربية', 'country' => 'مصر', 'country_code' => 'eg'],
        ];
    }

    public function test_the_screen_finds_the_place_instead_of_the_zone_pickers(): void
    {
        $a = $this->distanceRestaurant('cafe-a');
        $page = $this->actingAs($this->agent($a))->get($this->dashUrl('/call-center'))->assertOk();
        $page->assertSee('data-dist-box', false)->assertSee('data-addr-q', false)->assertSee('data-ring', false)->assertDontSee('data-zone-root', false);
        preg_match('#<script type="application/json" id="pos-data">(.*?)</script>#s', $page->getContent(), $m);
        $data = json_decode($m[1] ?? '{}', true);
        $this->assertSame('distance', $data['pricing']['method']);
        $this->assertSame(self::PIN, $data['pricing']['center']);
        $this->assertEquals([2, 4], array_column($data['pricing']['rings'], 'km'));
    }

    public function test_a_pinned_order_is_priced_by_its_ring_and_a_browser_fee_is_ignored(): void
    {
        $a = $this->distanceRestaurant('cafe-a');
        $latte = $this->item($a, 'Latte', 60);
        $agent = $this->agent($a);

        // ~1 km away: the first ring (30), whatever fee the browser sends
        $this->place($agent, $latte->id, [
            'lat' => 30.7955, 'lng' => 31.0004, 'delivery_fee' => 1, 'fee' => 1, 'zone_id' => 999,
            'address_parts' => ['street' => 'شارع البحر', 'building' => '12', 'area' => 'قسم أول طنطا'],
        ])->assertOk();

        $order = $this->as($a, fn () => Order::query()->firstOrFail());
        $this->assertSame(30.0, $order->delivery_fee);
        $this->assertSame(150.0, $order->total);
        $this->assertNull($order->delivery_zone_id);
        $this->assertSame(30.7955, $order->delivery_address['lat']);
        $this->assertEquals(1, $order->delivery_address['km']);
        $this->assertSame(20, $order->delivery_address['minutes']);
        $this->assertSame('شارع البحر', $order->delivery_address['parts']['street']);
        $this->assertSame('شارع البحر، عمارة 12، الدور 3', $order->delivery_address['text']);

        // the caller keeps the address with its pin, for next time
        $saved = $this->as($a, fn () => Customer::query()->firstOrFail()->addresses[0]);
        $this->assertNull($saved['zone_id']);
        $this->assertSame([30.7955, 31.0004], [$saved['lat'], $saved['lng']]);
    }

    public function test_a_ring_picked_by_hand_prices_it_without_a_point_and_keeps_its_minimum(): void
    {
        $a = $this->distanceRestaurant('cafe-a');
        $latte = $this->item($a, 'Latte', 60);
        $agent = $this->agent($a);

        // the second ring: minimum 100, one latte (60) is not enough
        $this->place($agent, $latte->id, ['ring' => 1], 1)->assertStatus(422);
        $this->place($agent, $latte->id, ['ring' => 1])->assertOk();

        $order = $this->as($a, fn () => Order::query()->firstOrFail());
        $this->assertSame(45.0, $order->delivery_fee);
        $this->assertSame(165.0, $order->total);
        $this->assertArrayNotHasKey('lat', $order->delivery_address);
        $this->assertEquals(4, $order->delivery_address['ring_km']);

        // a ring the branch does not have, or neither pin nor ring
        $this->place($agent, $latte->id, ['ring' => 5])->assertStatus(422);
        $this->place($agent, $latte->id, [])->assertStatus(422);
        $this->assertSame(1, $this->as($a, fn () => Order::query()->count()));
    }

    public function test_a_place_past_the_last_ring_is_refused(): void
    {
        $a = $this->distanceRestaurant('cafe-a');
        $latte = $this->item($a, 'Latte', 60);

        // ~10 km away, even with a ring sent beside it
        $this->place($this->agent($a), $latte->id, ['lat' => 30.8765, 'lng' => 31.0004, 'ring' => 0])
            ->assertStatus(422)->assertJsonPath('message', __('This place is outside the delivery area.'));
        $this->assertSame(0, $this->as($a, fn () => Order::query()->count()));
    }

    public function test_a_zones_branch_still_needs_its_zone_and_ignores_rings(): void
    {
        $a = $this->restaurant('cafe-z', 'basic');
        app(Billing::class)->setAddon($a, 'callcenter_pack', 1);
        $latte = $this->item($a, 'Latte', 60);
        $zone = $this->as($a, fn () => DeliveryZone::query()->create(['branch_id' => Branch::query()->value('id'), 'name' => 'Maadi', 'fee' => 25]));
        $agent = $this->agent($a);

        $this->place($agent, $latte->id, ['ring' => 0])->assertStatus(422)->assertJsonValidationErrors('zone_id');
        $this->place($agent, $latte->id, ['zone_id' => $zone->id, 'ring' => 1])->assertOk();
        $this->assertSame(25.0, $this->as($a, fn () => Order::query()->firstOrFail()->delivery_fee));
        $this->actingAs($agent)->get($this->dashUrl('/call-center'))->assertOk()->assertDontSee('data-dist-box', false);
    }

    public function test_a_saved_address_with_its_pin_comes_priced(): void
    {
        $a = $this->distanceRestaurant('cafe-a');
        $latte = $this->item($a, 'Latte', 60);
        $agent = $this->agent($a);
        $this->as($a, fn () => Customer::query()->create(['phone' => '01012345678', 'name' => 'Mona', 'addresses' => [
            ['label' => 'البيت', 'zone_id' => null, 'text' => 'شارع البحر 12', 'lat' => 30.7955, 'lng' => 31.0004],
            ['label' => '', 'zone_id' => null, 'text' => 'كفر الزيات', 'lat' => 30.8765, 'lng' => 31.0004],
            ['label' => '', 'zone_id' => 7, 'text' => 'من غير دبوس'],
        ]]));

        $this->actingAs($agent)->getJson($this->dashUrl('/call-center/customer?phone=01012345678'))->assertOk()
            ->assertJsonPath('addresses.0.delivery.fee', 30)->assertJsonPath('addresses.0.delivery.km', 1)->assertJsonPath('addresses.0.outside', false)
            ->assertJsonPath('addresses.1.outside', true)->assertJsonPath('addresses.2.text', 'من غير دبوس');

        // picked: its pin goes with the order and prices it
        $this->place($agent, $latte->id, ['lat' => 30.7955, 'lng' => 31.0004, 'address' => 'شارع البحر 12'])->assertOk();
        $this->assertSame(30.0, $this->as($a, fn () => Order::query()->firstOrFail()->delivery_fee));
    }

    public function test_the_staff_geo_endpoints_need_a_signed_in_agent_and_answer_never_cached(): void
    {
        $a = $this->distanceRestaurant('cafe-a');
        Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response($this->bahrStreet())]);
        $reverse = $this->dashUrl('/call-center/geo/reverse?lat=30.7955&lng=31.0004');

        $this->getJson($reverse)->assertUnauthorized();
        $this->getJson($this->dashUrl('/call-center/geo/suggest?q=شارع'))->assertUnauthorized();
        $this->actingAs($this->staff($a, 'kitchen', 'k@a.test'))->getJson($reverse)->assertForbidden();
        Http::assertNothingSent();

        $res = $this->actingAs($this->agent($a))->getJson($reverse)->assertOk()
            ->assertJsonPath('street', 'شارع البحر')->assertJsonPath('house_number', '12')->assertJsonPath('area', 'قسم أول طنطا')
            ->assertJsonPath('delivery.fee', 30)->assertJsonPath('delivery.km', 1)->assertJsonPath('outside', false);
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));

        // outside the last ring: no price, said so
        $this->travel(3)->seconds();
        $this->getJson($this->dashUrl('/call-center/geo/reverse?lat=30.8765&lng=31.0004'))->assertOk()
            ->assertJsonPath('delivery', null)->assertJsonPath('outside', true);
    }

    public function test_suggestions_try_this_restaurants_known_addresses_first_then_the_geocoder(): void
    {
        $a = $this->distanceRestaurant('cafe-a');
        $b = $this->distanceRestaurant('cafe-b');
        $agent = $this->agent($a);
        $branchA = $this->as($a, fn () => Branch::query()->value('id'));
        // the caller's own saved address, and an earlier delivery of this branch to the same street
        $this->as($a, function () use ($branchA) {
            Customer::query()->create(['phone' => '01012345678', 'name' => 'Mona', 'addresses' => [
                ['label' => 'البيت', 'zone_id' => null, 'text' => 'شارع البحر، عمارة 12', 'lat' => 30.7955, 'lng' => 31.0004],
            ]]);
            Order::query()->create(['branch_id' => $branchA, 'number' => 1, 'type' => 'delivery', 'status' => 'delivered', 'source' => 'callcenter',
                'subtotal' => 100, 'total' => 130, 'delivery_address' => ['zone' => '', 'text' => 'شارع البحر، عمارة 40', 'lat' => 30.8065, 'lng' => 31.0004]]);
        });
        // the other restaurant delivered to the same street: never shown here
        $this->as($b, fn () => Order::query()->create(['branch_id' => Branch::query()->value('id'), 'number' => 1, 'type' => 'delivery', 'status' => 'delivered',
            'source' => 'online', 'subtotal' => 100, 'total' => 130, 'delivery_address' => ['zone' => '', 'text' => 'شارع البحر، برج السرّ', 'lat' => 30.79, 'lng' => 31.0]]));
        Http::fake(['nominatim.openstreetmap.org/search*' => Http::response([$this->bahrStreet(30.8765)])]);

        $url = fn (string $extra = '') => $this->dashUrl('/call-center/geo/suggest?q='.urlencode('البحر').'&phone=01012345678'.$extra);
        $res = $this->actingAs($agent)->getJson($url())->assertOk()->assertJsonPath('ok', true)->assertJsonPath('searched', false)
            ->assertJsonCount(2, 'results')
            ->assertJsonPath('results.0.source', 'saved')->assertJsonPath('results.0.text', 'شارع البحر، عمارة 12')
            ->assertJsonPath('results.0.address_label', 'البيت')->assertJsonPath('results.0.delivery.fee', 30)->assertJsonPath('results.0.delivery.km', 1)
            ->assertJsonPath('results.1.source', 'previous')->assertJsonPath('results.1.delivery.fee', 45)->assertJsonPath('results.1.delivery.km', 2.2)
            ->assertJsonPath('results.1.outside', false);
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('برج السرّ', $res->getContent());
        Http::assertNothingSent(); // known addresses already have their pin

        // "search the map too": the geocoder's place ~10 km away comes greyed out
        $this->actingAs($agent)->getJson($url('&more=1'))->assertOk()->assertJsonPath('searched', true)->assertJsonCount(3, 'results')
            ->assertJsonPath('results.2.source', 'map')->assertJsonPath('results.2.label', 'شارع البحر، قسم أول طنطا')
            ->assertJsonPath('results.2.street', 'شارع البحر')->assertJsonPath('results.2.delivery', null)->assertJsonPath('results.2.outside', true);
        Http::assertSentCount(1);

        // tenant B's agent typing the same words sees only B's own address
        $this->actingAs($this->agent($b))->getJson($this->dashUrl('/call-center/geo/suggest?q='.urlencode('البحر')))->assertOk()
            ->assertJsonCount(1, 'results')->assertJsonPath('results.0.text', 'شارع البحر، برج السرّ');
    }
}
