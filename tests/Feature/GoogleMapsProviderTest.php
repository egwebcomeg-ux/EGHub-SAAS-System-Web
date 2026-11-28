<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\Geocoding\MapProvider;
use App\Services\Settings;
use App\Support\Leaflet;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TenantTestCase;

/**
 * The delivery maps on Google (GEOCODER=google): the page gets the browser key instead of Leaflet, a screen showing
 * a Google map gets Google's addresses and any other gets OpenStreetMap's, and OpenStreetMap takes over wherever
 * Google cannot be used (a key missing, a host the browser key does not allow, the month's limit reached).
 */
class GoogleMapsProviderTest extends TenantTestCase
{
    /** The branch pin in Tanta. 0.009° of latitude ≈ 1 km. */
    private const PIN = [30.7865, 31.0004];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'geocoding.driver' => 'google', 'geocoding.nominatim.wait' => 0, 'geocoding.google.server_key' => 'server-key',
            'geocoding.google.browser_key' => 'browser-key', 'geocoding.google.hosts' => ['bbq.filetto.net'], 'geocoding.google.monthly_limit' => 3,
        ]);
    }

    private function deliveringRestaurant(string $slug): Tenant
    {
        $tenant = $this->restaurant($slug, 'pro');
        $this->as($tenant, function () {
            Settings::put(['online_delivery' => '1']);
            Branch::query()->firstOrFail()->update([
                'lat' => self::PIN[0], 'lng' => self::PIN[1], 'delivery_pricing' => 'distance',
                'delivery_rings' => [['km' => 3, 'fee' => 25, 'min' => 0, 'minutes' => 30]],
            ]);
        });

        return $tenant;
    }

    /** Google's answer for a building on a street in Tanta, the most precise result first. @return array<string,mixed> */
    private function googleStreet(): array
    {
        $part = fn (string $name, string ...$types) => ['long_name' => $name, 'short_name' => $name, 'types' => $types];

        return ['status' => 'OK', 'results' => [
            [
                'formatted_address' => '12 شارع البحر، طنطا، الغربية، مصر', 'types' => ['street_address'],
                'geometry' => ['location' => ['lat' => 30.7955, 'lng' => 31.0004]],
                'address_components' => [$part('12', 'street_number'), $part('شارع البحر', 'route'), $part('طنطا', 'locality', 'political'), $part('الغربية', 'administrative_area_level_1', 'political')],
            ],
            [
                'formatted_address' => 'قسم أول طنطا، طنطا، الغربية، مصر', 'types' => ['sublocality', 'political'],
                'geometry' => ['location' => ['lat' => 30.79, 'lng' => 31.0]],
                'address_components' => [$part('قسم أول طنطا', 'sublocality_level_1', 'sublocality', 'political'), $part('طنطا', 'locality', 'political')],
            ],
        ]];
    }

    /** Nominatim's answer for the same street. @return array<string,mixed> */
    private function osmStreet(): array
    {
        return ['lat' => '30.7955', 'lon' => '31.0004', 'name' => 'شارع البحر', 'address' => ['road' => 'شارع البحر', 'neighbourhood' => 'احمد عواجه', 'city' => 'طنطا']];
    }

    public function test_the_pin_on_a_google_map_gets_its_street_from_google(): void
    {
        $this->deliveringRestaurant('cafe-a');
        Http::fake(['maps.googleapis.com/*' => Http::response($this->googleStreet())]);

        $this->getJson($this->subUrl('cafe-a', 'geo/reverse?map=google&lat=30.7955&lng=31.0004'))->assertOk()
            ->assertJsonPath('found', true)->assertJsonPath('street', 'شارع البحر')->assertJsonPath('house_number', '12')
            // the district comes from the second result; "طنطا" is already in its name
            ->assertJsonPath('area', 'قسم أول طنطا')->assertJsonPath('formatted_ar', 'شارع البحر، قسم أول طنطا')
            ->assertJsonPath('delivery.fee', 25);

        Http::assertSent(function (HttpRequest $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return str_starts_with($r->url(), 'https://maps.googleapis.com/maps/api/geocode/json?')
                // the server key, never the browser's; Arabic names
                && $q['key'] === 'server-key' && $q['language'] === 'ar' && $q['latlng'] === '30.7955,31.0005';
        });
        // the same square again is the cached answer: Google is not called, the month's count stays
        $this->getJson($this->subUrl('cafe-a', 'geo/reverse?map=google&lat=30.7955&lng=31.0004'))->assertJsonPath('street', 'شارع البحر');
        Http::assertSentCount(1);
        $this->assertSame(1, MapProvider::usedThisMonth());
    }

    public function test_a_screen_without_a_google_map_gets_openstreetmap_addresses(): void
    {
        $this->deliveringRestaurant('cafe-a');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response($this->osmStreet())]);

        // an older page still open on Leaflet (no map said, or map=osm): Google's addresses never go on its map
        $this->getJson($this->subUrl('cafe-a', 'geo/reverse?lat=30.7955&lng=31.0004'))->assertOk()->assertJsonPath('area', 'احمد عواجه');
        Http::assertSentCount(1);
        $this->assertSame(0, MapProvider::usedThisMonth());
    }

    public function test_the_typed_address_is_looked_for_in_egypt_around_the_branch(): void
    {
        $this->deliveringRestaurant('cafe-a');
        Http::fake(['maps.googleapis.com/*' => Http::sequence()->push($this->googleStreet())->push(['status' => 'ZERO_RESULTS', 'results' => []])]);

        $this->getJson($this->subUrl('cafe-a', 'geo/search?map=google&q='.urlencode('شارع البحر')))->assertOk()->assertJsonPath('ok', true)
            ->assertJsonCount(2, 'results')->assertJsonPath('results.0.label', 'شارع البحر، طنطا')
            // about 1 km from the branch: inside the ring, priced
            ->assertJsonPath('results.0.lat', 30.7955)->assertJsonPath('results.0.outside', false)->assertJsonPath('results.0.delivery.fee', 25);
        Http::assertSent(function (HttpRequest $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return $q['address'] === 'شارع البحر' && $q['components'] === 'country:EG' && $q['bounds'] === '30.5365,30.7504|31.0365,31.2504';
        });

        // nothing found is an answer, not a failure
        $this->getJson($this->subUrl('cafe-a', 'geo/search?map=google&q='.urlencode('مكان مش موجود')))->assertOk()
            ->assertJsonPath('ok', true)->assertJsonCount(0, 'results');
    }

    public function test_a_refusal_answers_nothing_and_is_not_cached(): void
    {
        $this->deliveringRestaurant('cafe-a');
        Http::fake(['maps.googleapis.com/*' => Http::sequence()->push(['status' => 'REQUEST_DENIED', 'error_message' => 'no'])->push($this->googleStreet())]);

        $this->getJson($this->subUrl('cafe-a', 'geo/reverse?map=google&lat=30.7955&lng=31.0004'))->assertOk()->assertJsonPath('found', false);
        $this->getJson($this->subUrl('cafe-a', 'geo/reverse?map=google&lat=30.7955&lng=31.0004'))->assertOk()->assertJsonPath('found', true);
    }

    public function test_the_menu_page_draws_googles_map_with_the_browser_key_only(): void
    {
        $this->deliveringRestaurant('cafe-a');

        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('browser-key', false)->assertDontSee('server-key', false)->assertDontSee(str_replace('/', '\/', Leaflet::JS), false);

        config(['geocoding.driver' => 'nominatim']);
        $this->deliveringRestaurant('cafe-b');
        $this->get($this->subUrl('cafe-b'))->assertOk()->assertDontSee('browser-key', false)->assertSee(str_replace('/', '\/', Leaflet::JS), false);
    }

    public function test_the_call_center_map_and_its_typed_address_follow_the_provider(): void
    {
        $a = $this->deliveringRestaurant('cafe-a');
        app(Billing::class)->setAddon($a, 'callcenter_pack', 1);
        $agent = $this->staff($a, 'callcenter', 'agent@cafe-a.test');
        Http::fake(['maps.googleapis.com/*' => Http::response($this->googleStreet())]);

        $this->actingAs($agent)->get($this->dashUrl('/call-center'))->assertOk()->assertSee('browser-key', false)->assertDontSee(Leaflet::JS, false);
        $this->actingAs($agent)->getJson($this->dashUrl('/call-center/geo/suggest?map=google&more=1&q='.urlencode('شارع البحر')))->assertOk()
            ->assertJsonPath('results.0.source', 'map')->assertJsonPath('results.0.street', 'شارع البحر')->assertJsonPath('results.0.delivery.fee', 25);
    }

    public function test_openstreetmap_takes_over_where_google_cannot_be_used(): void
    {
        // the main domain, its subdomains and the listed own domains are the browser key's sites
        $this->assertSame('google', MapProvider::name('cafe-a.'.central_domain()));
        $this->assertSame('google', MapProvider::name('bbq.filetto.net'));
        $this->assertSame('nominatim', MapProvider::name('menu.another-restaurant.com'));

        // past the month's limit, until the next month
        MapProvider::countCall();
        MapProvider::countCall();
        MapProvider::countCall();
        $this->assertSame('nominatim', MapProvider::name('bbq.filetto.net'));
        $this->assertNull(MapProvider::client());
        $this->travel(32)->days();
        $this->assertSame('google', MapProvider::name('bbq.filetto.net'));

        // a key missing
        config(['geocoding.google.browser_key' => null]);
        $this->assertSame('nominatim', MapProvider::name('bbq.filetto.net'));
        config(['geocoding.driver' => 'none']);
        $this->assertSame('none', MapProvider::name('bbq.filetto.net'));
    }
}
