<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Settings;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class OnlineOrderingTest extends TenantTestCase
{
    private function order(Tenant $tenant, array $extra)
    {
        $item = $this->as($tenant, fn () => MenuItem::query()->first());

        return $this->postJson($this->subUrl($tenant->id, 'order'), $extra + [
            'client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $item->id, 'qty' => 2]],
            'customer_name' => 'Mona', 'customer_phone' => '01012345678',
        ]);
    }

    public function test_pickup_and_delivery_from_the_menu_link(): void
    {
        $a = $this->restaurant('cafe-a', 'pro'); // Pro includes online ordering
        $this->item($a, 'Latte', 60);
        $zone = $this->as($a, function () {
            Settings::put(['online_delivery' => '1']);

            return DeliveryZone::query()->create(['branch_id' => Branch::query()->first()->id, 'name' => 'Maadi', 'fee' => 25, 'min_order' => 100]);
        });

        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-otype="takeaway"', false)->assertSee('Maadi');

        $this->order($a, ['type' => 'takeaway'])->assertOk()->assertJsonStructure(['url']);
        $this->order($a, ['type' => 'delivery', 'zone_id' => $zone->id, 'address' => '9 Road 233'])->assertOk();

        $orders = $this->as($a, fn () => Order::query()->orderBy('id')->get());
        $this->assertSame(['takeaway', 'delivery'], $orders->pluck('type')->all());
        $this->assertSame(['online', 'online'], $orders->pluck('source')->all());
        $this->assertSame(145.0, $orders[1]->total); // 120 + 25 delivery, no service charge
        $this->assertSame('9 Road 233', $orders[1]->delivery_address['text']);

        // the guest sees the WhatsApp button on the tracking page (the restaurant has a WhatsApp number)
        $this->as($a, fn () => Settings::put(['whatsapp' => '01099999999']));
        $this->get($this->subUrl('cafe-a', 'o/'.$orders[1]->tracking_token))->assertOk()->assertSee('wa.me/201099999999', false);
    }

    public function test_rules_phone_zone_minimum_and_schedule(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->item($a, 'Latte', 60);
        $zone = $this->as($a, function () {
            Settings::put(['online_delivery' => '1', 'online_prep_minutes' => '30']);

            return DeliveryZone::query()->create(['branch_id' => Branch::query()->first()->id, 'name' => 'Maadi', 'fee' => 25, 'min_order' => 500]);
        });

        $this->order($a, ['type' => 'takeaway', 'customer_phone' => ''])->assertStatus(422);
        $this->order($a, ['type' => 'delivery', 'address' => 'x'])->assertStatus(422);                         // no zone
        $this->order($a, ['type' => 'delivery', 'zone_id' => $zone->id, 'address' => 'x'])->assertStatus(422);  // under the minimum
        $this->order($a, ['type' => 'takeaway', 'scheduled_for' => now()->addMinutes(5)->format('Y-m-d\TH:i')])->assertStatus(422);
        $this->order($a, ['type' => 'takeaway', 'scheduled_for' => now()->addHours(3)->format('Y-m-d\TH:i')])->assertOk();
        $this->assertNotNull($this->as($a, fn () => Order::query()->first()->scheduled_for));
    }

    public function test_plans_without_online_ordering_still_need_a_table(): void
    {
        $basic = $this->restaurant('cafe-basic', 'basic');
        $this->item($basic, 'Latte');
        $this->order($basic, ['type' => 'takeaway'])->assertStatus(422);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());

        // the add-on switches it on
        app(Billing::class)->setAddon($basic, 'online_pack', 1);
        $this->order($basic, ['type' => 'takeaway'])->assertOk();
    }
}
