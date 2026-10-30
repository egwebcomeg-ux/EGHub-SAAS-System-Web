<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\CorporateVoucher;
use App\Models\Customer;
use App\Models\Tenant;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Entitlements;
use Tests\TenantTestCase;

/**
 * The guest desk: one lookup, then the wallet (top-up, gift card) and the guest's company vouchers next to the stamp
 * card — each panel only with its plan feature and permission, the money going through the wallet's own endpoints.
 */
class GuestDeskTest extends TenantTestCase
{
    private const PHONE = '01012345678';

    /** A Complete restaurant (wallet, corporate vouchers, loyalty card) with a guest holding one company voucher. */
    private function restaurantWithGuest(string $slug = 'cafe-a'): array
    {
        $tenant = $this->restaurant($slug, 'full');
        $voucher = $this->as($tenant, function () use ($tenant) {
            $customer = Customer::query()->create(['phone' => self::PHONE, 'name' => 'Mona', 'source' => 'pos']);
            $service = app(CorporateVouchers::class);
            $contract = $service->createContract(['name' => 'Eva Pharma', 'voucher_value' => 200, 'quantity' => 1,
                'expires_on' => local_at()->addMonth()->format('Y-m-d'), 'mode' => 'single'], $this->owner($tenant));
            $voucher = CorporateVoucher::query()->where('corporate_contract_id', $contract->id)->firstOrFail();
            $service->link($voucher, $customer);

            return $voucher;
        });

        return [$tenant, $voucher];
    }

    public function test_the_desk_shows_the_wallet_and_the_guests_company_vouchers_to_whoever_may_use_them(): void
    {
        [$tenant, $voucher] = $this->restaurantWithGuest();
        $cashier = $this->staff($tenant, 'cashier', 'cashier@a.test');

        $this->actingAs($cashier)->get($this->dashUrl('/loyalty?q='.self::PHONE))->assertOk()
            ->assertSee(__('Guest desk'))
            ->assertSee('data-wallet-desk', false)
            ->assertSee('action="'.$this->dashUrl('/wallet/topup').'"', false)
            ->assertSee('action="'.$this->dashUrl('/wallet/gift-cards').'"', false)
            ->assertSee('data-corporate-voucher="'.$voucher->serial.'"', false)
            ->assertSee('Eva Pharma')
            ->assertSee('href="'.$this->dashUrl('/vouchers/redeem?phone='.self::PHONE).'"', false);

        // the call center stamps cards but holds neither the wallet nor the redeem permission
        $callCenter = $this->staff($tenant, 'callcenter', 'calls@a.test');
        $this->actingAs($callCenter)->get($this->dashUrl('/loyalty?q='.self::PHONE))->assertOk()
            ->assertDontSee('data-wallet-desk', false)
            ->assertDontSee('data-corporate-desk', false);
    }

    public function test_the_panels_stay_hidden_without_their_plan_feature(): void
    {
        $tenant = $this->restaurant('cafe-a', 'pro');
        $rights = Entitlements::for($tenant);
        $this->assertTrue($rights->allows('loyalty_stamps'));
        $this->assertFalse($rights->allows('wallet') || $rights->allows('corporate_vouchers'));
        $this->as($tenant, fn () => Customer::query()->create(['phone' => self::PHONE, 'name' => 'Mona', 'source' => 'pos']));

        $this->actingAs($this->staff($tenant, 'cashier', 'cashier@a.test'))->get($this->dashUrl('/loyalty?q='.self::PHONE))->assertOk()
            ->assertDontSee('data-wallet-desk', false)
            ->assertDontSee('data-corporate-desk', false);
    }

    public function test_a_restaurant_with_the_wallet_alone_has_the_guest_desk(): void
    {
        $tenant = $this->restaurant('cafe-a', 'free');
        $cashier = $this->staff($tenant, 'cashier', 'cashier@a.test');
        $this->actingAs($cashier)->get($this->dashUrl('/loyalty'))->assertForbidden();

        app(Billing::class)->setAddon($tenant, 'wallet_pack', 1);
        $this->assertFalse(Entitlements::for($tenant->fresh())->allows('loyalty_stamps|prepaid_packages|membership|cashback'));
        $this->as($tenant, fn () => Customer::query()->create(['phone' => self::PHONE, 'name' => 'Mona', 'source' => 'pos']));

        $this->actingAs($cashier)->get($this->dashUrl('/loyalty?q='.self::PHONE))->assertOk()->assertSee('data-wallet-desk', false);
    }

    public function test_money_taken_at_the_desk_follows_the_shift_rule_of_the_pos(): void
    {
        $tenant = $this->restaurant('cafe-a', 'pro');
        app(Billing::class)->setAddon($tenant, 'pos_pack', 1);
        app(Billing::class)->setAddon($tenant, 'wallet_pack', 1);
        $this->as($tenant, fn () => Customer::query()->create(['phone' => self::PHONE, 'name' => 'Mona', 'source' => 'pos']));
        $cashier = $this->staff($tenant, 'cashier', 'cashier@a.test');
        $topUp = fn () => $this->actingAs($cashier)->postJson($this->dashUrl('/wallet/topup'), ['phone' => self::PHONE, 'amount' => 100, 'method' => 'cash']);

        $this->actingAs($cashier)->get($this->dashUrl('/loyalty?q='.self::PHONE))->assertOk()->assertSee('data-needs-shift', false);
        $topUp()->assertStatus(422)->assertJson(['message' => __('Open a shift first to take payments.')]);

        $this->actingAs($cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 100])->assertSessionHasNoErrors();
        $this->actingAs($cashier)->get($this->dashUrl('/loyalty?q='.self::PHONE))->assertOk()->assertDontSee('data-needs-shift', false);
        $topUp()->assertOk()->assertJson(['balance' => 100]);
        $this->assertSame(100.0, $this->balance($tenant));
    }

    public function test_the_redeem_screen_opened_from_the_desk_looks_the_guest_up(): void
    {
        [$tenant] = $this->restaurantWithGuest();
        $cashier = $this->staff($tenant, 'cashier', 'cashier@a.test');

        $this->actingAs($cashier)->get($this->dashUrl('/vouchers/redeem?phone='.self::PHONE))->assertOk()->assertSee('"lookup":"'.self::PHONE.'"', false);
        $this->actingAs($cashier)->get($this->dashUrl('/vouchers/redeem?phone=not-a-phone'))->assertOk()->assertSee('"lookup":null', false);
    }

    private function balance(Tenant $tenant): float
    {
        return $this->as($tenant, fn () => (float) Customer::query()->where('phone', self::PHONE)->value('credit_balance'));
    }
}
