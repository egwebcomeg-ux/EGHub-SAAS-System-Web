<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * The cashier screen: the restaurant may refuse orders without the customer's mobile, the cashier sees only the name
 * of the mobile they type (no customer list), and delivery is taken from the POS on the call-center screen.
 */
class PosCustomerPhoneTest extends TenantTestCase
{
    private Tenant $tenant;

    private User $cashier;

    private int $latteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->restaurant('cafe-a', 'basic');
        app(Billing::class)->setAddon($this->tenant, 'pos_pack', 1);
        app(Billing::class)->setAddon($this->tenant, 'callcenter_pack', 1);
        $this->latteId = $this->item($this->tenant, 'Latte', 60)->id;
        $this->cashier = $this->staff($this->tenant, 'cashier', 'cashier@cafe-a.test');
    }

    private function sell(array $extra = []): TestResponse
    {
        return $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/orders'), $extra + [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $this->latteId, 'qty' => 1]],
        ]);
    }

    public function test_with_the_setting_on_no_pos_order_goes_without_the_customer_mobile(): void
    {
        // the customer box is on every order type, required or not; each item takes its own note
        $this->actingAs($this->cashier)->get($this->dashUrl('/pos'))->assertOk()->assertSee('data-customer-box', false)->assertSee('data-note-dialog', false);
        $this->sell()->assertOk();

        $this->as($this->tenant, fn () => Settings::put(['pos_require_phone' => '1']));
        $this->sell()->assertStatus(422)->assertJsonValidationErrors('customer_phone');
        $this->sell(['customer_phone' => '01012345678', 'customer_name' => 'Mona'])->assertOk();

        $this->assertSame(2, $this->as($this->tenant, fn () => Order::query()->count()));
        $this->assertSame('Mona', $this->as($this->tenant, fn () => Customer::query()->where('phone', '01012345678')->sole()->name));
    }

    public function test_the_cashier_sees_only_the_name_of_the_mobile_they_type(): void
    {
        $this->as($this->tenant, fn () => Customer::query()->create(['phone' => '01012345678', 'name' => 'Mona', 'phone_2' => '01099999999', 'notes' => 'VIP']));

        $this->actingAs($this->cashier)->getJson($this->dashUrl('/pos/customer?phone=01012345678'))
            ->assertOk()->assertExactJson(['found' => true, 'phone' => '01012345678', 'name' => 'Mona']);
        $this->actingAs($this->cashier)->getJson($this->dashUrl('/pos/customer?phone=01011111111'))
            ->assertOk()->assertJsonPath('found', false);
        $this->actingAs($this->cashier)->getJson($this->dashUrl('/pos/customer?phone=123'))->assertStatus(422);

        // no customer list for the cashier
        $this->actingAs($this->cashier)->get($this->dashUrl('/customers'))->assertForbidden();
    }

    public function test_the_cashier_takes_a_landline_and_finds_the_customer_by_either_number(): void
    {
        $this->as($this->tenant, fn () => Customer::query()->create(['phone' => '01012345678', 'name' => 'Mona', 'phone_2' => '0403597535']));

        // the customer's landline is their second number: same customer, the number typed stays on the order
        $this->actingAs($this->cashier)->getJson($this->dashUrl('/pos/customer?phone=040-359-7535'))
            ->assertOk()->assertExactJson(['found' => true, 'phone' => '0403597535', 'name' => 'Mona']);
        $this->sell(['customer_phone' => '0403597535'])->assertOk();
        $order = $this->as($this->tenant, fn () => Order::query()->sole());
        $this->assertSame('0403597535', $order->customer_phone);
        $this->assertSame(1, $this->as($this->tenant, fn () => Customer::query()->count()));

        // a new landline is a new customer
        $this->sell(['customer_phone' => '0223456789', 'customer_name' => 'Sara'])->assertOk();
        $this->assertSame('Sara', $this->as($this->tenant, fn () => Customer::query()->where('phone', '0223456789')->sole()->name));
    }

    public function test_the_cashier_takes_a_delivery_order_on_the_call_center_screen(): void
    {
        $zone = $this->as($this->tenant, fn () => DeliveryZone::query()->create([
            'branch_id' => Branch::query()->first()->id, 'name' => 'Maadi', 'fee' => 25, 'min_order' => 0,
        ]));

        $this->actingAs($this->cashier)->get($this->dashUrl('/pos'))->assertOk()->assertSee('data-to-delivery', false);
        $this->actingAs($this->cashier)->get($this->dashUrl('/call-center'))->assertOk()->assertSee('data-to-cashier', false);
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/call-center/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'delivery', 'items' => [['item_id' => $this->latteId, 'qty' => 2]],
            'customer_phone' => '01012345678', 'zone_id' => $zone->id, 'address' => '12 Street 9',
        ])->assertOk();

        $order = $this->as($this->tenant, fn () => Order::query()->sole());
        $this->assertSame('delivery', $order->type);
        $this->assertSame(145.0, $order->total);
    }

    public function test_no_delivery_button_without_the_call_center_pack(): void
    {
        app(Billing::class)->setAddon($this->tenant, 'callcenter_pack', 0);

        $this->actingAs($this->cashier)->get($this->dashUrl('/pos'))->assertOk()->assertDontSee('data-to-delivery', false);
        $this->actingAs($this->cashier)->get($this->dashUrl('/call-center'))->assertForbidden();
    }
}
