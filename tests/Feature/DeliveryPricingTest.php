<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Settings;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * Delivery priced by the guest's pin (App\Services\Delivery\DeliveryPricing): the drawn zone holding it (a sub-zone
 * over its main zone) or the distance ring of the branch, always computed again on the server; the list of areas
 * stays as the fallback; the owner sets the method, the branch pin and the rings.
 */
class DeliveryPricingTest extends TenantTestCase
{
    /** The branch pin: 9 El Matḥaf street, Tanta. 0.009° of latitude ≈ 1 km. */
    private const PIN = [30.7865, 31.0004];

    private function order(Tenant $tenant, array $extra, int $qty = 2): TestResponse
    {
        $item = $this->as($tenant, fn () => MenuItem::query()->first());

        return $this->postJson($this->subUrl($tenant->id, 'order'), $extra + [
            'client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $item->id, 'qty' => $qty]],
            'customer_name' => 'Mona', 'customer_phone' => '01012345678', 'type' => 'delivery', 'address' => 'Building 4, floor 2',
        ]);
    }

    private function lastOrder(Tenant $tenant): Order
    {
        return $this->as($tenant, fn () => Order::query()->latest('id')->firstOrFail());
    }

    /** A main zone around the pin with a drawn street inside it. @return array{0:DeliveryZone, 1:DeliveryZone} */
    private function drawnZones(Tenant $tenant): array
    {
        return $this->as($tenant, function () {
            Settings::put(['online_delivery' => '1', 'delivery_zone_mode' => 'map']);
            $branch = Branch::query()->firstOrFail();
            $main = DeliveryZone::query()->create(['branch_id' => $branch->id, 'name' => 'El Bahr', 'fee' => 30, 'estimated_minutes' => 30,
                'polygon' => [[30.76, 30.98], [30.76, 31.02], [30.81, 31.02], [30.81, 30.98]]]);
            $street = DeliveryZone::query()->create(['branch_id' => $branch->id, 'parent_id' => $main->id, 'name' => 'El Bahr — far end', 'fee' => 45,
                'polygon' => [[30.80, 30.98], [30.80, 31.02], [30.81, 31.02], [30.81, 30.98]]]);

            return [$main, $street];
        });
    }

    private function distanceBranch(Tenant $tenant, array $rings): Branch
    {
        return $this->as($tenant, function () use ($rings) {
            Settings::put(['online_delivery' => '1']);
            $branch = Branch::query()->firstOrFail();
            $branch->update(['lat' => self::PIN[0], 'lng' => self::PIN[1], 'delivery_pricing' => 'distance', 'delivery_rings' => $rings]);

            return $branch;
        });
    }

    public function test_the_pin_is_priced_by_the_deepest_drawn_zone_and_a_browser_fee_is_ignored(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->item($a, 'Latte', 60);
        [$main, $street] = $this->drawnZones($a);

        // the menu opens the map first, with the list one tap away
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-loc-box', false)->assertSee('data-pin-list', false);

        // inside the drawn street (inside the main zone too): the street wins; fee / total sent by the browser are ignored
        $this->order($a, ['lat' => 30.805, 'lng' => 31.0, 'delivery_fee' => 0, 'fee' => 0, 'total' => 1, 'zone_id' => null])->assertOk();
        $order = $this->lastOrder($a);
        $this->assertSame($street->id, $order->delivery_zone_id);
        $this->assertSame(45.0, (float) $order->delivery_fee);
        $this->assertSame(165.0, (float) $order->total);
        // (a whole number comes back from the JSON column as an int)
        $this->assertEquals(['zone' => 'El Bahr › El Bahr — far end', 'text' => 'Building 4, floor 2', 'lat' => 30.805, 'lng' => 31], $order->delivery_address);

        // in the main zone only
        $this->order($a, ['lat' => 30.78, 'lng' => 31.0])->assertOk();
        $this->assertSame($main->id, $this->lastOrder($a)->delivery_zone_id);
        $this->assertSame(30.0, (float) $this->lastOrder($a)->delivery_fee);

        // outside every zone: refused, nothing placed
        $this->order($a, ['lat' => 30.9, 'lng' => 31.0])->assertStatus(422)->assertJsonPath('message', __('Sorry, we do not deliver to this location.'));
        $this->assertSame(2, $this->as($a, fn () => Order::query()->count()));
    }

    public function test_distance_rings_price_the_straight_line_distance_from_the_branch(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->item($a, 'Latte', 60);
        $this->distanceBranch($a, [
            ['km' => 4, 'fee' => 35, 'min' => 150, 'minutes' => 45], ['km' => 2, 'fee' => 25, 'min' => 0, 'minutes' => 30], ['km' => 6, 'fee' => 50, 'min' => 0, 'minutes' => null],
        ]);

        // no zone at all: delivery is still offered, map first, with no list to fall back to
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-otype="delivery"', false)
            ->assertSee('data-loc-box', false)->assertDontSee('data-pin-list', false);

        // about 1 km away: the first ring
        $this->order($a, ['lat' => self::PIN[0] + 0.009, 'lng' => self::PIN[1], 'delivery_fee' => 0])->assertOk();
        $order = $this->lastOrder($a);
        $this->assertNull($order->delivery_zone_id);
        $this->assertSame(25.0, (float) $order->delivery_fee);
        $this->assertSame(145.0, (float) $order->total);
        $this->assertEquals(1.0, $order->delivery_address['km']);
        $this->assertSame(30, $order->delivery_address['minutes']);
        $this->assertSame(__(':km km from the restaurant', ['km' => '1.0']), $order->delivery_address['zone']);

        // about 3 km: the 2–4 km ring and its minimum order (150) apply
        $this->order($a, ['lat' => self::PIN[0] + 0.027, 'lng' => self::PIN[1]])->assertStatus(422);
        $this->order($a, ['lat' => self::PIN[0] + 0.027, 'lng' => self::PIN[1]], qty: 3)->assertOk();
        $this->assertSame(35.0, (float) $this->lastOrder($a)->delivery_fee);

        // about 10 km: past the last ring
        $this->order($a, ['lat' => self::PIN[0] + 0.09, 'lng' => self::PIN[1]])->assertStatus(422);
        // no pin and no area: nothing to price
        $this->order($a, [])->assertStatus(422);
        $this->assertSame(2, $this->as($a, fn () => Order::query()->count()));
    }

    public function test_the_area_list_still_works_beside_the_map(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->item($a, 'Latte', 60);
        [$main] = $this->drawnZones($a);
        $this->distanceBranch($a, [['km' => 2, 'fee' => 25]]);

        // picked from the list: that area's fee, even with distance pricing on
        $this->order($a, ['zone_id' => $main->id])->assertOk();
        $this->assertSame($main->id, $this->lastOrder($a)->delivery_zone_id);
        $this->assertSame(30.0, (float) $this->lastOrder($a)->delivery_fee);
        // a pin sent with a listed area must sit inside it when the area is drawn
        $this->order($a, ['zone_id' => $main->id, 'lat' => 30.9, 'lng' => 31.0])->assertStatus(422);
    }

    public function test_the_confirmed_pin_is_kept_on_the_guests_saved_address(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->item($a, 'Latte', 60);
        $this->distanceBranch($a, [['km' => 3, 'fee' => 25]]);

        $this->order($a, ['lat' => 30.795, 'lng' => 31.0004])->assertOk();
        $addresses = $this->as($a, fn () => Customer::query()->where('phone', '01012345678')->firstOrFail()->addresses);
        $this->assertCount(1, $addresses);
        $this->assertSame(['label' => '', 'zone_id' => null, 'text' => 'Building 4, floor 2', 'zone' => __(':km km from the restaurant', ['km' => '0.9']), 'lat' => 30.795, 'lng' => 31.0004], $addresses[0]);
    }

    public function test_the_owner_sets_distance_pricing_for_their_own_branch_only(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $b = $this->restaurant('cafe-b', 'pro');
        $branch = $this->as($a, fn () => Branch::query()->firstOrFail());
        $foreign = $this->as($b, fn () => Branch::query()->firstOrFail());
        $url = fn (Branch $br) => $this->dashUrl("/delivery-zones/branches/{$br->id}/pricing");
        $rings = [['km' => 4, 'fee' => 35, 'min' => 150, 'minutes' => 45], ['km' => 2, 'fee' => 25]];

        // the delivery page shows the method switch, the rings editor and the preview map
        $this->actingAs($this->owner($a))->get($this->dashUrl('/delivery-zones'))->assertOk()
            ->assertSee('data-pricing-map', false)->assertSee('data-ring-add', false)->assertSee('data-test-toggle', false);

        // distance needs the restaurant pin and a ring
        $this->actingAs($this->owner($a))->post($url($branch), ['method' => 'distance', 'rings' => $rings])->assertSessionHasErrors('lat');
        $this->actingAs($this->owner($a))->post($url($branch), ['method' => 'distance', 'lat' => self::PIN[0], 'lng' => self::PIN[1]])->assertSessionHasErrors('rings');
        $this->actingAs($this->owner($a))->post($url($branch), ['method' => 'distance', 'lat' => self::PIN[0], 'lng' => self::PIN[1], 'rings' => $rings])
            ->assertSessionHasNoErrors();
        $saved = $this->as($a, fn () => $branch->fresh());
        $this->assertSame('distance', $saved->delivery_pricing);
        $this->assertSame([2.0, 4.0], array_map(fn ($r) => (float) $r['km'], $saved->delivery_rings));
        $this->assertSame(self::PIN, [$saved->lat, $saved->lng]);

        // back to zones keeps the rings for later
        $this->actingAs($this->owner($a))->post($url($branch), ['method' => 'zones'])->assertSessionHasNoErrors();
        $this->assertSame('zones', $this->as($a, fn () => $branch->fresh()->delivery_pricing));
        $this->assertCount(2, $this->as($a, fn () => $branch->fresh()->delivery_rings));

        // another restaurant's branch is not found; a cashier may not change it
        $this->actingAs($this->owner($a))->post($url($foreign), ['method' => 'zones'])->assertNotFound();
        $this->assertSame('zones', $this->as($b, fn () => $foreign->fresh()->delivery_pricing));
        $cashier = $this->staff($a, 'cashier', 'cashier@cafe-a.test');
        $this->actingAs($cashier)->post($url($branch), ['method' => 'distance', 'lat' => 1, 'lng' => 1, 'rings' => $rings])->assertForbidden();
    }

    public function test_one_restaurants_zones_never_price_another_restaurants_order(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $b = $this->restaurant('cafe-b', 'pro');
        $this->item($a, 'Latte', 60);
        $this->as($a, fn () => Settings::put(['online_delivery' => '1', 'delivery_zone_mode' => 'map']));
        $this->as($a, fn () => DeliveryZone::query()->create(['branch_id' => Branch::query()->value('id'), 'name' => 'Own', 'fee' => 20,
            'polygon' => [[30.0, 31.0], [30.0, 31.1], [30.1, 31.1], [30.1, 31.0]]]));
        [$foreignMain] = $this->drawnZones($b);

        // a point in B's drawn zone (outside A's) is not delivered by A, and B's zone id is not A's either
        $this->order($a, ['lat' => 30.78, 'lng' => 31.0])->assertStatus(422);
        $this->order($a, ['zone_id' => $foreignMain->id])->assertStatus(422);
        $this->order($a, ['lat' => 30.05, 'lng' => 31.05])->assertOk();
        $this->assertSame(20.0, (float) $this->lastOrder($a)->delivery_fee);
    }
}
