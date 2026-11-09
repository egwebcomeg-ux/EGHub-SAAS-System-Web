<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/** Paid by mobile wallet, the guest pays the wallet's fee on top (setting wallet_fee_percent, 1% by default): on the order and the receipt. */
class PosWalletFeeTest extends TenantTestCase
{
    private Tenant $tenant;

    private User $cashier;

    private int $latteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->restaurant('cafe-a', 'basic');
        app(Billing::class)->setAddon($this->tenant, 'pos_pack', 1);
        $this->latteId = $this->item($this->tenant, 'Latte', 60)->id;
        $this->cashier = $this->staff($this->tenant, 'cashier', 'cashier@cafe-a.test');
        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 0])->assertSessionHasNoErrors();
    }

    private function sell(string $pay): TestResponse
    {
        return $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $this->latteId, 'qty' => 2]], 'pay' => $pay,
        ]);
    }

    public function test_a_mobile_wallet_payment_adds_one_percent_on_the_order_and_the_receipt(): void
    {
        $this->sell('wallet')->assertOk()->assertJsonPath('total', 121.2);
        $this->sell('cash')->assertOk()->assertJsonPath('total', 120);

        [$wallet, $cash] = $this->as($this->tenant, fn () => Order::query()->orderBy('id')->get()->all());
        $this->assertSame([1.2, 121.2, 'wallet'], [$wallet->payment_fee, $wallet->total, $wallet->payment_method]);
        $this->assertSame([0.0, 120.0], [$cash->payment_fee, $cash->total]);
        $this->actingAs($this->cashier)->get($this->dashUrl("/orders/{$wallet->id}/receipt"))->assertOk()->assertSee('رسوم المحفظة');
        $this->actingAs($this->cashier)->get($this->dashUrl("/orders/{$cash->id}/receipt"))->assertOk()->assertDontSee('رسوم المحفظة');
    }

    public function test_the_owner_sets_the_fee_and_zero_means_none(): void
    {
        $this->actingAs($this->cashier)->post($this->dashUrl('/pos/terminals/wallet-fee'), ['wallet_fee_percent' => 0])->assertForbidden();
        $owner = $this->owner($this->tenant);
        $this->actingAs($owner)->post($this->dashUrl('/pos/terminals/wallet-fee'), ['wallet_fee_percent' => 20])->assertSessionHasErrors('wallet_fee_percent');
        $this->actingAs($owner)->post($this->dashUrl('/pos/terminals/wallet-fee'), ['wallet_fee_percent' => 0])->assertSessionHasNoErrors();
        $this->assertSame('0', $this->as($this->tenant, fn () => Settings::get('wallet_fee_percent')));

        $this->sell('wallet')->assertOk()->assertJsonPath('total', 120);
    }
}
