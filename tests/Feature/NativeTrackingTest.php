<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DeliveryTrackPoint;
use App\Models\DeliveryZone;
use App\Models\DriverLocation;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Orders\OrderService;
use Tests\TenantTestCase;

/** The EgHub driver app's background location: a signed per-delivery endpoint and a short-lived bearer token. */
class NativeTrackingTest extends TenantTestCase
{
    /** @return array{0:Tenant, 1:User, 2:Order} a delivery order given to a driver and ready to leave */
    private function delivery(string $slug = 'cafe-a'): array
    {
        $tenant = $this->restaurant($slug);
        $item = $this->item($tenant, 'Pizza', 100);
        $driver = $this->staff($tenant, 'driver', "d@$slug.test");
        $order = $this->as($tenant, function () use ($item) {
            $branch = Branch::query()->first();
            $zone = DeliveryZone::query()->firstOrCreate(['branch_id' => $branch->id, 'name' => 'Maadi'], ['fee' => 20, 'is_active' => true]);
            $order = app(OrderService::class)->place([
                'items' => [['item_id' => $item->id, 'qty' => 1]], 'customer_name' => 'Mona', 'customer_phone' => '01012345678',
                'source' => 'pos', 'type' => 'delivery', 'delivery_zone' => $zone,
                'delivery_address' => ['zone' => 'Maadi', 'text' => '9 Road 233', 'lat' => 29.96, 'lng' => 31.25],
            ], $branch);
            app(OrderService::class)->transition($order, 'preparing');
            app(OrderService::class)->transition($order->fresh(), 'ready');

            return $order->fresh();
        });
        $this->actingAs($this->owner($tenant))->postJson($this->dashUrl("/orders/{$order->id}/driver"), ['driver_id' => $driver->id])->assertOk();

        return [$tenant, $driver, $order];
    }

    /** @return array{endpoint:string, token:string, intervalSec:int, maxMinutes:int} */
    private function leave(User $driver, Order $order): array
    {
        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/picked-up"))->assertOk();

        return $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/tracking"))->assertOk()->json();
    }

    /** What the app's background service sends: no session, only the bearer token. */
    private function fix(string $endpoint, ?string $token, float $lat = 29.97)
    {
        auth()->forgetGuards();

        return $this->withHeaders($token ? ['Authorization' => "Bearer $token"] : [])->postJson($endpoint, [
            'lat' => $lat, 'lng' => 31.24, 'accuracy' => 9, 'speed' => 7.5, 'heading' => 180, 'at' => now()->toIso8601String(),
        ]);
    }

    public function test_the_driver_gets_a_tracking_hand_over_only_while_their_order_is_out(): void
    {
        [$a, $driver, $order] = $this->delivery();
        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/tracking"))->assertStatus(409); // not picked up yet

        $tracking = $this->leave($driver, $order);

        $this->assertSame(20, $tracking['intervalSec']);
        $this->assertSame(240, $tracking['maxMinutes']);
        $this->assertStringStartsWith("http://app.egmenu.test/native/track/{$order->id}?expires=", $tracking['endpoint']);
        $this->assertStringContainsString('signature=', $tracking['endpoint']);

        $other = $this->staff($a, 'driver', 'other@cafe-a.test');
        $this->actingAs($other)->postJson($this->dashUrl("/driver/orders/{$order->id}/tracking"))->assertNotFound();
    }

    public function test_a_fix_from_the_app_is_stored_like_the_driver_screens_own_and_reaches_the_guest(): void
    {
        [$a, $driver, $order] = $this->delivery();
        $tracking = $this->leave($driver, $order);

        $this->fix($tracking['endpoint'], $tracking['token'])->assertOk()->assertJsonPath('stored', true);

        $location = $this->as($a, fn () => DriverLocation::query()->where('user_id', $driver->id)->sole());
        $this->assertEqualsWithDelta(29.97, $location->lat, 0.0001);
        $this->assertSame(9, $location->accuracy);
        $this->assertSame(1, $this->as($a, fn () => DeliveryTrackPoint::query()->where('order_id', $order->id)->count()));
        $status = $this->getJson($this->subUrl('cafe-a', "o/{$order->tracking_token}/status"))->assertOk()->json();
        $this->assertEqualsWithDelta(29.97, $status['driver']['lat'], 0.0001);
    }

    public function test_a_fix_without_a_valid_token_or_signature_is_refused(): void
    {
        [, $driver, $order] = $this->delivery();
        $tracking = $this->leave($driver, $order);

        $this->fix($tracking['endpoint'], null)->assertUnauthorized()->assertJsonPath('stop', true);
        $this->fix($tracking['endpoint'], $tracking['token'].'x')->assertUnauthorized();
        [$payload, $signature] = explode('.', $tracking['token']);
        $forged = rtrim(strtr(base64_encode((string) json_encode(['t' => 'cafe-a', 'o' => $order->id, 'u' => $driver->id, 'e' => time() + 99999])), '+/', '-_'), '=');
        $this->fix($tracking['endpoint'], "$forged.$signature")->assertUnauthorized();
        $this->fix(str_replace('signature=', 'signature=0', $tracking['endpoint']), $tracking['token'])->assertForbidden();
        // the token of one delivery cannot report for another order
        $this->fix(str_replace("/track/{$order->id}?", '/track/999?', $tracking['endpoint']), $tracking['token'])->assertForbidden();
    }

    public function test_the_hand_over_expires(): void
    {
        [, $driver, $order] = $this->delivery();
        $tracking = $this->leave($driver, $order);

        $this->travel(241)->minutes();

        $this->fix($tracking['endpoint'], $tracking['token'])->assertForbidden()->assertJsonPath('stop', true);
    }

    public function test_the_app_is_told_to_stop_once_the_order_is_delivered(): void
    {
        [, $driver, $order] = $this->delivery();
        $tracking = $this->leave($driver, $order);
        $this->actingAs($driver)->postJson($this->dashUrl("/driver/orders/{$order->id}/delivered"), ['method' => 'cash'])->assertOk();

        $this->fix($tracking['endpoint'], $tracking['token'])->assertStatus(410)->assertJsonPath('stop', true);
    }

    public function test_the_endpoint_is_rate_limited_per_delivery(): void
    {
        [, $driver, $order] = $this->delivery();
        $tracking = $this->leave($driver, $order);

        for ($i = 0; $i < 12; $i++) {
            $this->fix($tracking['endpoint'], $tracking['token'])->assertOk();
        }

        $this->fix($tracking['endpoint'], $tracking['token'])->assertTooManyRequests();
    }
}
