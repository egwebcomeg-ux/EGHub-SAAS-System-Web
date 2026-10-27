<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Settings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * The delivery address like the delivery apps: the pin's street from the geocoder (Nominatim through the restaurant's
 * own host, cached, one call a second for the whole platform, nothing when it is busy), the area found for the pin,
 * the map's search, and the address typed in parts that the server turns into the one line every screen reads.
 */
class DeliveryAddressGeocodingTest extends TenantTestCase
{
    /** The branch pin: 9 El Matḥaf street, Tanta. 0.009° of latitude ≈ 1 km. */
    private const PIN = [30.7865, 31.0004];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['geocoding.driver' => 'nominatim', 'geocoding.nominatim.wait' => 0]);
    }

    /** Nominatim's answer for a street in Tanta. @return array<string,mixed> */
    private function bahrStreet(): array
    {
        return [
            'place_id' => 1, 'lat' => '30.7866', 'lon' => '31.0003', 'category' => 'highway', 'type' => 'residential', 'name' => 'شارع البحر',
            'display_name' => 'شارع البحر، قسم أول طنطا، طنطا، الغربية، مصر',
            'address' => ['road' => 'شارع البحر', 'house_number' => '12', 'suburb' => 'قسم أول طنطا', 'city' => 'طنطا', 'state' => 'الغربية', 'country' => 'مصر', 'country_code' => 'eg'],
        ];
    }

    private function fakeNominatim(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/reverse*' => Http::response($this->bahrStreet()),
            'nominatim.openstreetmap.org/search*' => Http::response([$this->bahrStreet()]),
        ]);
    }

    private function distanceBranch(Tenant $tenant, array $rings = [['km' => 3, 'fee' => 25, 'min' => 0, 'minutes' => 30]]): Branch
    {
        return $this->as($tenant, function () use ($rings) {
            Settings::put(['online_delivery' => '1']);
            $branch = Branch::query()->firstOrFail();
            $branch->update(['lat' => self::PIN[0], 'lng' => self::PIN[1], 'delivery_pricing' => 'distance', 'delivery_rings' => $rings]);

            return $branch;
        });
    }

    private function reverse(string $slug, float $lat, float $lng): TestResponse
    {
        return $this->getJson($this->subUrl($slug, "geo/reverse?lat={$lat}&lng={$lng}"));
    }

    private function order(Tenant $tenant, array $extra): TestResponse
    {
        $item = $this->as($tenant, fn () => MenuItem::query()->first());

        return $this->postJson($this->subUrl($tenant->id, 'order'), $extra + [
            'client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $item->id, 'qty' => 2]],
            'customer_name' => 'Mona', 'customer_phone' => '01012345678', 'type' => 'delivery',
        ]);
    }

    public function test_the_pin_gets_its_street_and_area_from_nominatim_within_its_usage_policy(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->distanceBranch($a);
        $this->fakeNominatim();

        $res = $this->reverse('cafe-a', self::PIN[0] + 0.009, self::PIN[1])->assertOk()->assertExactJson([
            'found' => true, 'street' => 'شارع البحر', 'house_number' => '12', 'area' => 'قسم أول طنطا', 'city' => 'طنطا',
            // "طنطا" is already in "قسم أول طنطا": not said twice
            'formatted_ar' => 'شارع البحر، قسم أول طنطا',
            // about 1 km from the branch: the first distance ring prices it
            'delivery' => ['zone_id' => null, 'name' => __(':km km from the restaurant', ['km' => '1.0']), 'fee' => 25, 'min' => 0, 'km' => 1],
            'zone' => null,
        ]);
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));

        Http::assertSent(function (HttpRequest $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return str_starts_with($r->url(), 'https://nominatim.openstreetmap.org/reverse?')
                && $q['format'] === 'jsonv2' && $q['zoom'] === '18' && $q['addressdetails'] === '1' && $q['accept-language'] === 'ar,en'
                && $r->hasHeader('User-Agent', 'EgHub/1.0 (+https://eghub.app; info@eghub.app)')
                // only the point leaves the server
                && array_keys($q) === ['lat', 'lon', 'zoom', 'addressdetails', 'format', 'accept-language'];
        });
    }

    public function test_one_answer_is_cached_per_25_m_square_for_every_restaurant(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $b = $this->restaurant('cafe-b', 'pro');
        $this->distanceBranch($a);
        $this->distanceBranch($b);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::sequence()->push($this->bahrStreet())->push(['error' => 'Unable to geocode'])]);

        $this->reverse('cafe-a', 30.79001, 31.00001)->assertJsonPath('found', true);
        // a few metres away, and the same point from another restaurant: the cached answer, no new call
        $this->reverse('cafe-a', 30.79004, 31.00003)->assertJsonPath('street', 'شارع البحر');
        $this->reverse('cafe-b', 30.79001, 31.00001)->assertJsonPath('street', 'شارع البحر');
        Http::assertSentCount(1);

        // "nothing here" (the sea) is a real answer and is cached too
        $this->travel(5)->seconds();
        $this->reverse('cafe-a', 31.5, 31.5)->assertJsonPath('found', false)->assertJsonPath('street', null);
        $this->travel(5)->seconds();
        $this->reverse('cafe-a', 31.5, 31.5)->assertJsonPath('found', false);
        Http::assertSentCount(2);
    }

    public function test_one_call_a_second_for_the_platform_and_a_refusal_or_timeout_answers_nothing_uncached(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->distanceBranch($a);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::sequence()
            ->push($this->bahrStreet())
            ->push(['error' => 'Too many requests'], 429)
            ->pushFailedConnection()
            ->push($this->bahrStreet())]);

        $this->reverse('cafe-a', 30.79, 31.0)->assertJsonPath('found', true);
        // another point in the same second: no call, an empty answer (the guest types the street)
        $this->reverse('cafe-a', 30.80, 31.0)->assertOk()->assertJsonPath('found', false)->assertJsonPath('formatted_ar', null);
        Http::assertSentCount(1);

        // a 429, then a timeout: nothing, never cached
        $this->travel(3)->seconds();
        $this->reverse('cafe-a', 30.80, 31.0)->assertOk()->assertJsonPath('found', false);
        $this->travel(3)->seconds();
        $this->reverse('cafe-a', 30.80, 31.0)->assertOk()->assertJsonPath('found', false);
        $this->travel(3)->seconds();
        $this->reverse('cafe-a', 30.80, 31.0)->assertOk()->assertJsonPath('found', true);
        Http::assertSentCount(4);
    }

    public function test_the_geocoder_is_rate_limited_per_visitor(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->distanceBranch($a);
        $this->fakeNominatim();

        for ($i = 0; $i < 30; $i++) {
            $this->reverse('cafe-a', 30.79, 31.0)->assertOk();
        }
        $this->reverse('cafe-a', 30.79, 31.0)->assertStatus(429);
        Http::assertSentCount(1);
    }

    public function test_only_a_restaurant_delivering_online_can_use_it_and_none_turns_it_off(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->restaurant('cafe-b', 'pro'); // no delivery
        $this->distanceBranch($a);
        $this->fakeNominatim();

        $this->reverse('cafe-b', 30.79, 31.0)->assertNotFound();
        $this->getJson($this->subUrl('cafe-b', 'geo/search?q=شارع البحر'))->assertNotFound();
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('geo\/reverse', false)->assertSee('data-pin-search', false);

        config(['geocoding.driver' => 'none']);
        $this->reverse('cafe-a', 30.79, 31.0)->assertNotFound();
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('"geo":null', false)->assertDontSee('data-pin-search', false);
        Http::assertNothingSent();
    }

    public function test_a_pin_in_a_drawn_zone_names_that_zone(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $street = $this->as($a, function () {
            Settings::put(['online_delivery' => '1', 'delivery_zone_mode' => 'map']);
            $branch = Branch::query()->firstOrFail();
            $main = DeliveryZone::query()->create(['branch_id' => $branch->id, 'name' => 'El Bahr', 'fee' => 30,
                'polygon' => [[30.76, 30.98], [30.76, 31.02], [30.81, 31.02], [30.81, 30.98]]]);

            return DeliveryZone::query()->create(['branch_id' => $branch->id, 'parent_id' => $main->id, 'name' => 'Far end', 'fee' => 45,
                'polygon' => [[30.80, 30.98], [30.80, 31.02], [30.81, 31.02], [30.81, 30.98]]]);
        });
        $this->fakeNominatim();

        $this->reverse('cafe-a', 30.805, 31.0)->assertOk()
            ->assertJsonPath('zone', ['id' => $street->id, 'name' => 'El Bahr › Far end', 'by' => 'area'])
            ->assertJsonPath('delivery.zone_id', $street->id)->assertJsonPath('delivery.fee', 45);
        // outside every drawn zone: no zone, no price (the menu says "we do not deliver here")
        $this->travel(3)->seconds();
        $this->reverse('cafe-a', 30.9, 31.0)->assertOk()->assertJsonPath('zone', null)->assertJsonPath('delivery', null);
    }

    public function test_areas_typed_by_hand_are_matched_by_the_pins_names_only_when_sure(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $zones = $this->as($a, function () {
            Settings::put(['online_delivery' => '1', 'delivery_zone_mode' => 'manual']);
            $branch = Branch::query()->firstOrFail();

            return collect(['البحر', 'حي الإستاد', 'سيجر'])->mapWithKeys(fn ($name) => [$name => DeliveryZone::query()->create(['branch_id' => $branch->id, 'name' => $name, 'fee' => 20])]);
        });
        $place = fn (array $address) => Http::response(['lat' => '30.79', 'lon' => '31.0', 'name' => '', 'address' => $address + ['city' => 'طنطا']]);

        // "شارع البحر" is the area "البحر" (شارع / ال folded away)
        Http::fake(['nominatim.openstreetmap.org/*' => Http::sequence()
            ->pushResponse($place(['road' => 'شارع البحر', 'suburb' => 'قسم أول طنطا']))
            ->pushResponse($place(['road' => 'شارع النادي', 'neighbourhood' => 'الاستاد']))
            ->pushResponse($place(['road' => 'شارع الجيش', 'suburb' => 'قسم ثان طنطا']))]);
        $this->reverse('cafe-a', 30.79, 31.0)->assertJsonPath('zone', ['id' => $zones['البحر']->id, 'name' => 'البحر', 'by' => 'name']);
        // "الاستاد" is "حي الإستاد" (hamza and حي folded)
        $this->travel(3)->seconds();
        $this->reverse('cafe-a', 30.80, 31.0)->assertJsonPath('zone.id', $zones['حي الإستاد']->id);
        // nothing alike: the guest chooses
        $this->travel(3)->seconds();
        $this->reverse('cafe-a', 30.81, 31.0)->assertJsonPath('found', true)->assertJsonPath('zone', null)->assertJsonPath('delivery', null);

        // two areas with the same name: not sure, the guest chooses
        $this->as($a, fn () => DeliveryZone::query()->create(['branch_id' => Branch::query()->value('id'), 'name' => 'شارع البحر', 'fee' => 20]));
        $this->reverse('cafe-a', 30.79, 31.0)->assertJsonPath('zone', null);
    }

    public function test_the_map_search_asks_near_the_branch_in_egypt_and_is_cached(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->distanceBranch($a);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::sequence()->push([$this->bahrStreet()])->push([], 429)]);
        $url = $this->subUrl('cafe-a', 'geo/search?q='.urlencode('شارع البحر'));

        $res = $this->getJson($url)->assertOk()->assertJsonPath('ok', true)->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0', ['label' => 'شارع البحر، قسم أول طنطا', 'lat' => 30.7866, 'lng' => 31.0003,
                'street' => 'شارع البحر', 'house_number' => '12', 'area' => 'قسم أول طنطا', 'city' => 'طنطا', 'formatted_ar' => 'شارع البحر، قسم أول طنطا']);
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        Http::assertSent(function (HttpRequest $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return str_starts_with($r->url(), 'https://nominatim.openstreetmap.org/search?') && $q['q'] === 'شارع البحر'
                && $q['countrycodes'] === 'eg' && $q['bounded'] === '0' && $q['limit'] === '5' && $q['format'] === 'jsonv2'
                && $q['viewbox'] === '30.7504,31.0365,31.2504,30.5365';
        });

        // the same words again: cached; too short: refused before anything leaves
        $this->getJson($url)->assertJsonPath('results.0.street', 'شارع البحر');
        $this->getJson($this->subUrl('cafe-a', 'geo/search?q=ab'))->assertStatus(422);
        Http::assertSentCount(1);

        // busy: an empty list that says so (the menu says "try again")
        $this->travel(3)->seconds();
        $this->getJson($this->subUrl('cafe-a', 'geo/search?q=ميدان الساعة'))->assertOk()->assertExactJson(['ok' => false, 'results' => []]);
    }

    public function test_the_address_in_parts_makes_the_orders_line_and_is_kept_with_the_order_and_the_guest(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->item($a, 'Latte', 60);
        $this->distanceBranch($a);
        $parts = ['area' => 'قسم أول طنطا', 'street' => 'شارع البحر', 'building' => '12', 'floor' => '3', 'apartment' => '5', 'landmark' => 'جنب الصيدلية', 'label' => 'home'];

        $this->order($a, ['lat' => 30.795, 'lng' => 31.0004, 'address_parts' => $parts])->assertOk();
        $order = $this->as($a, fn () => Order::query()->latest('id')->firstOrFail());
        $text = $order->delivery_address['text'];
        foreach (['شارع البحر', '12', '3', '5', 'قسم أول طنطا', 'جنب الصيدلية'] as $piece) {
            $this->assertStringContainsString($piece, $text);
        }
        $this->assertTrue(str_starts_with($text, 'شارع البحر'));
        $this->assertSame($parts, $order->delivery_address['parts']);
        // everything that reads the one line still has it (zone label, text, pin)
        $this->assertSame(30.795, $order->delivery_address['lat']);
        $this->assertNotSame('', $order->delivery_address['zone']);

        // the guest's saved address keeps the pin, the parts and the label
        $saved = $this->as($a, fn () => Customer::query()->where('phone', '01012345678')->firstOrFail()->addresses[0]);
        $this->assertSame($parts, $saved['parts']);
        $this->assertSame($text, $saved['text']);
        $this->assertNotSame('', $saved['label']);
        $this->assertSame([30.795, 31.0004], [$saved['lat'], $saved['lng']]);

        // parts without a street are refused; the one line of an older page still works
        $this->order($a, ['lat' => 30.795, 'lng' => 31.0004, 'address_parts' => ['building' => '4']])->assertStatus(422)
            ->assertJsonPath('message', __('Write the street name.'));
        $this->order($a, ['lat' => 30.795, 'lng' => 31.0004, 'address' => 'Building 4, floor 2'])->assertOk();
        $last = $this->as($a, fn () => Order::query()->latest('id')->firstOrFail());
        $this->assertSame('Building 4, floor 2', $last->delivery_address['text']);
        $this->assertArrayNotHasKey('parts', $last->delivery_address);
    }

    public function test_saved_addresses_with_their_parts_stay_with_their_restaurant(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->restaurant('cafe-b', 'pro');
        $this->item($a, 'Latte', 60);
        $this->distanceBranch($a);
        $parts = ['street' => 'شارع البحر', 'building' => '12', 'label' => 'work'];
        $this->order($a, ['lat' => 30.795, 'lng' => 31.0004, 'address_parts' => $parts])->assertOk();
        $mona = $this->as($a, fn () => Customer::query()->where('phone', '01012345678')->firstOrFail());

        // the summary gives the pin and the parts back: choosing it fills the form without a new street lookup
        $this->withCookie('card_cafe-a', $mona->card_token)->withCredentials()->getJson($this->subUrl('cafe-a', 'me/summary'))->assertOk()
            ->assertJsonPath('guest.addresses.0.parts', $parts)->assertJsonPath('guest.addresses.0.lat', 30.795)
            ->assertJsonPath('guest.addresses.0.lng', 31.0004);
        // Mona's key on another restaurant opens nothing there
        $this->defaultCookies = [];
        $this->withCookie('card_cafe-b', $mona->card_token)->withCredentials()->getJson($this->subUrl('cafe-b', 'me/summary'))->assertExactJson(['guest' => null]);
        Http::assertNothingSent();
    }
}
