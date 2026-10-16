<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Models\PrepaidPackage;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\WalletTransaction;
use App\Services\Entitlements;
use App\Services\Loyalty\Packages;
use App\Services\Orders\OrderService;
use App\Services\Orders\ShiftService;
use App\Services\Settings;
use App\Services\Wallet\WalletService;
use Tests\TenantTestCase;

/** Advanced cards: cashback to the wallet, membership tiers with a discount, prepaid packages sold and used at the counter. */
class CardServicesTest extends TenantTestCase
{
    private const PHONE = '01012345678';

    /** A Pro cafe with the POS pack and the advanced cards pack, a 60 EGP latte and a cashier with an open shift. */
    private function shop(string $slug = 'cafe-a'): array
    {
        $tenant = $this->restaurant($slug, 'pro');
        app(Billing::class)->setAddon($tenant, 'pos_pack', 1);
        app(Billing::class)->setAddon($tenant, 'cards_pack', 1);
        $item = $this->item($tenant, 'Latte', 60);
        $cashier = $this->staff($tenant, 'cashier', "cashier@$slug.test");
        $this->actingAs($cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 100])->assertSessionHasNoErrors();

        return [$tenant, $item, $cashier];
    }

    /** Places a 1-latte order for the guest at the POS and pays it in cash (so it counts for loyalty and cashback). */
    private function paidOrder(Tenant $tenant, $item, $cashier, string $phone = self::PHONE): Order
    {
        return $this->as($tenant, function () use ($item, $cashier, $phone) {
            $order = app(OrderService::class)->place(['items' => [['item_id' => $item->id, 'qty' => 1]], 'source' => 'pos', 'type' => 'takeaway',
                'customer_phone' => $phone, 'customer_name' => 'Mona'], Branch::query()->firstOrFail(), null, $cashier);

            return app(OrderService::class)->pay($order, 'cash', $cashier, app(ShiftService::class)->current($cashier));
        });
    }

    public function test_the_cards_pack_unlocks_the_pages_and_replaces_the_wallet_pack(): void
    {
        $tenant = $this->restaurant('cafe-a', 'pro');
        $owner = $this->owner($tenant);
        $this->actingAs($owner)->get($this->dashUrl('/loyalty/cards'))->assertForbidden();

        app(Billing::class)->setAddon($tenant, 'wallet_pack', 1);
        app(Billing::class)->setAddon($tenant, 'cards_pack', 1);
        $rights = Entitlements::for($tenant->fresh());
        $this->assertTrue($rights->allows('wallet') && $rights->allows('cashback') && $rights->allows('prepaid_packages') && $rights->allows('membership'));
        // the smaller wallet pack is covered by the cards pack, so it is not billed twice
        $this->assertSame(['cards_pack'], $tenant->subscription()->first()->addons()->pluck('key')->all());

        $this->actingAs($owner)->get($this->dashUrl('/loyalty/cards'))->assertOk()->assertSee('البطاقات المتقدمة');
        // the cashier reaches the counter even without a stamp program
        $cashier = $this->staff($tenant, 'cashier', 'c@a.test');
        $this->actingAs($cashier)->get($this->dashUrl('/loyalty'))->assertOk();
        $this->actingAs($cashier)->get($this->dashUrl('/loyalty/cards'))->assertForbidden();

        // Business has everything without the add-on
        $this->assertTrue(Entitlements::for($this->restaurant('cafe-b', 'business'))->allows('cashback|prepaid_packages|membership'));
    }

    public function test_cashback_goes_to_the_wallet_and_comes_back_on_cancel(): void
    {
        [$tenant, $item, $cashier] = $this->shop();
        $owner = $this->owner($tenant);
        $this->actingAs($owner)->post($this->dashUrl('/loyalty/cards/cashback'), ['percent' => 10, 'min_order' => 50])->assertSessionHasNoErrors();
        $this->assertSame('10', $this->as($tenant, fn () => Settings::get('cashback_percent')));

        $order = $this->paidOrder($tenant, $item, $cashier);
        $this->as($tenant, function () use ($order) {
            $customer = Customer::query()->where('phone', self::PHONE)->firstOrFail();
            $this->assertSame(6.0, round((float) $customer->credit_balance, 2));
            $this->assertSame(1, WalletTransaction::query()->where('order_id', $order->id)->where('kind', 'cashback')->count());
            // served after paid: nothing is counted twice
            app(OrderService::class)->transition($order->fresh(), 'preparing');
            app(OrderService::class)->transition($order->fresh(), 'ready');
            app(OrderService::class)->transition($order->fresh(), 'served');
            $this->assertSame(6.0, round((float) $customer->fresh()->credit_balance, 2));
        });

        // a second paid order, then cancelled: its cashback is taken back once, and the ledger still adds up
        $second = $this->paidOrder($tenant, $item, $cashier);
        $this->assertSame(12.0, $this->as($tenant, fn () => round((float) Customer::query()->where('phone', self::PHONE)->value('credit_balance'), 2)));
        $this->as($tenant, fn () => app(OrderService::class)->transition(Order::query()->findOrFail($second->id), 'cancelled', $cashier, 'test'));
        $this->as($tenant, function () use ($second) {
            $customer = Customer::query()->where('phone', self::PHONE)->firstOrFail();
            $this->assertSame(6.0, round((float) $customer->credit_balance, 2));
            $this->assertSame(1, WalletTransaction::query()->where('order_id', $second->id)->where('kind', 'cashback_rev')->count());
            $this->assertSame(6.0, round((float) WalletTransaction::query()->where('customer_id', $customer->id)->sum('delta'), 2));
        });

        // the card page tells the guest
        $token = $this->as($tenant, fn () => Customer::query()->where('phone', self::PHONE)->value('card_token'));
        $this->get($this->subUrl('cafe-a', "card/$token"))->assertOk()->assertSee('كاش باك');

        // below the minimum order nothing is given
        $this->as($tenant, fn () => Settings::put(['cashback_min_order' => '100']));
        $this->paidOrder($tenant, $item, $cashier, '01099999999');
        $this->assertSame(0.0, $this->as($tenant, fn () => round((float) Customer::query()->where('phone', '01099999999')->value('credit_balance'), 2)));
    }

    public function test_membership_tier_discount_is_taken_off_orders_with_the_guest_number(): void
    {
        [$tenant, $item, $cashier] = $this->shop();
        $owner = $this->owner($tenant);
        $this->actingAs($owner)->post($this->dashUrl('/loyalty/cards/tiers'), ['tiers' => [
            ['name' => 'سيلفر', 'name_en' => 'Silver', 'min_visits' => 2, 'min_spent' => 0, 'discount_percent' => 5, 'color' => '#999999'],
            ['name' => 'جولد', 'name_en' => 'Gold', 'min_visits' => 5, 'min_spent' => 0, 'discount_percent' => 10, 'color' => '#C9A227'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(2, $this->as($tenant, fn () => MembershipTier::query()->count()));

        // a new guest: no tier, full price
        $order = $this->paidOrder($tenant, $item, $cashier);
        $this->assertSame(60.0, (float) $order->total);
        $this->assertSame(0.0, (float) $order->member_discount);

        // five visits: Gold, 10% off
        $this->as($tenant, fn () => Customer::query()->where('phone', self::PHONE)->update(['visits_count' => 5]));
        $order = $this->paidOrder($tenant, $item, $cashier);
        $this->assertSame(54.0, (float) $order->total);
        $this->assertSame(6.0, (float) $order->member_discount);
        $this->assertSame('جولد', $order->member_tier);

        // the guest sees the tier on the card; the counter sees it too
        $token = $this->as($tenant, fn () => Customer::query()->where('phone', self::PHONE)->value('card_token'));
        $this->get($this->subUrl('cafe-a', "card/$token"))->assertOk()->assertSee('جولد')->assertSee('data-membership', false);
        $this->actingAs($cashier)->get($this->dashUrl('/loyalty?q='.self::PHONE))->assertOk()->assertSee('جولد');

        // removing the tiers removes the discount
        $this->actingAs($owner)->post($this->dashUrl('/loyalty/cards/tiers'), ['tiers' => []])->assertSessionHasNoErrors();
        $this->assertSame(60.0, (float) $this->paidOrder($tenant, $item, $cashier)->total);
    }

    public function test_prepaid_package_is_sold_at_the_counter_used_once_per_visit_and_expires(): void
    {
        [$tenant, $item, $cashier] = $this->shop();
        $owner = $this->owner($tenant);
        $this->actingAs($owner)->post($this->dashUrl('/loyalty/cards/packages'), ['name' => '١٠ قهوة', 'name_en' => '10 coffees', 'price' => 150, 'uses' => 10, 'valid_days' => 30])
            ->assertSessionHasNoErrors();
        $package = $this->as($tenant, fn () => PrepaidPackage::query()->firstOrFail());

        // the cashier finds the guest, creates the card and sells the package for cash
        $this->actingAs($cashier)->post($this->dashUrl('/loyalty/enroll'), ['phone' => self::PHONE, 'name' => 'Mona'])->assertSessionHasNoErrors();
        $customer = $this->as($tenant, fn () => Customer::query()->where('phone', self::PHONE)->firstOrFail());
        $this->actingAs($cashier)->post($this->dashUrl("/loyalty/customers/{$customer->id}/packages"), ['package_id' => $package->id, 'method' => 'cash'])
            ->assertSessionHasNoErrors();
        $copy = $this->as($tenant, fn () => CustomerPackage::query()->firstOrFail());
        $this->assertSame([10, 10, 'active', 150.0], [$copy->uses_total, $copy->uses_left, $copy->status, (float) $copy->price_paid]);
        $this->assertTrue($copy->expires_at->isSameDay(now()->addDays(30)));

        // the money is in the shift as prepaid cash, not as a sale
        $summary = $this->as($tenant, fn () => app(ShiftService::class)->summary(Shift::query()->firstOrFail()));
        $this->assertSame(['cash' => 150.0], $summary['prepaid']);
        $this->assertSame(0, $summary['orders']);

        // one use at a time
        $this->actingAs($cashier)->post($this->dashUrl("/loyalty/packages/{$copy->id}/use"))->assertSessionHasNoErrors();
        $this->assertSame(9, $this->as($tenant, fn () => CustomerPackage::query()->firstOrFail()->uses_left));
        $this->get($this->subUrl('cafe-a', "card/{$customer->card_token}"))->assertOk()->assertSee('١٠ قهوة')->assertSee('9<span', false);
        $this->actingAs($cashier)->get($this->dashUrl('/loyalty?q='.self::PHONE))->assertOk()->assertSee('9/10');

        // after the last use the package is used up
        $this->as($tenant, function () use ($copy, $cashier) {
            $c = CustomerPackage::query()->findOrFail($copy->id);
            for ($i = 0; $i < 9; $i++) {
                app(Packages::class)->use($c, $cashier);
            }
            $this->assertSame(['used', 0], [$c->fresh()->status, $c->fresh()->uses_left]);
            $this->expectExceptionMessage('استُهلكت');
            app(Packages::class)->use($c, $cashier);
        });
    }

    public function test_expired_packages_close_and_cannot_be_used(): void
    {
        [$tenant, $item, $cashier] = $this->shop();
        $copy = $this->as($tenant, function () use ($cashier) {
            $package = PrepaidPackage::query()->create(['name' => 'باقة', 'price' => 100, 'uses' => 5, 'valid_days' => 7, 'is_active' => true]);
            $customer = Customer::query()->create(['phone' => self::PHONE, 'name' => 'Mona']);

            return app(Packages::class)->sell($package, $customer, 'cash', $cashier, app(ShiftService::class)->current($cashier));
        });
        $this->travel(8)->days();
        $this->assertSame(1, $this->as($tenant, fn () => app(Packages::class)->expire()));
        $this->assertSame('expired', $this->as($tenant, fn () => CustomerPackage::query()->findOrFail($copy->id)->status));
        $this->actingAs($cashier)->post($this->dashUrl("/loyalty/packages/{$copy->id}/use"))->assertSessionHasErrors('package');
        // the guest's card no longer lists it
        $token = $this->as($tenant, fn () => Customer::query()->where('phone', self::PHONE)->value('card_token'));
        $this->get($this->subUrl('cafe-a', "card/$token"))->assertOk()->assertDontSee('data-packages', false);
    }

    public function test_cashback_expires_after_the_days_set_taking_back_only_what_is_still_on_the_balance(): void
    {
        [$tenant, $item, $cashier] = $this->shop();
        $this->actingAs($this->owner($tenant))->post($this->dashUrl('/loyalty/cards/cashback'), ['percent' => 10, 'min_order' => 0, 'expire_days' => 30])->assertSessionHasNoErrors();
        $this->assertSame('30', $this->as($tenant, fn () => Settings::get('cashback_expire_days')));

        $this->paidOrder($tenant, $item, $cashier);                       // +6 cashback, expires in 30 days
        $this->as($tenant, function () use ($cashier) {
            $customer = Customer::query()->where('phone', self::PHONE)->firstOrFail();
            app(WalletService::class)->topUp($customer, 10, 'cash', $cashier, app(ShiftService::class)->current($cashier));
            app(WalletService::class)->adjust($customer, -12, $cashier, 'spent'); // 16 → 4 left
        });

        $this->travel(10)->days();
        $this->assertSame(0, $this->as($tenant, fn () => app(WalletService::class)->expireCashback()));
        $this->travel(25)->days();
        $this->assertSame(1, $this->as($tenant, fn () => app(WalletService::class)->expireCashback()));
        $this->as($tenant, function () {
            $customer = Customer::query()->where('phone', self::PHONE)->firstOrFail();
            // only 4 of the 6 were still on the balance; the line is settled and is not taken twice
            $this->assertSame(0.0, round((float) $customer->credit_balance, 2));
            $this->assertSame(-4.0, (float) WalletTransaction::query()->where('kind', 'cashback_exp')->value('delta'));
            $this->assertNotNull(WalletTransaction::query()->where('kind', 'cashback')->value('settled_at'));
            $this->assertSame(0, app(WalletService::class)->expireCashback());
            $this->assertSame(0.0, round((float) WalletTransaction::query()->where('customer_id', $customer->id)->sum('delta'), 2));
        });
    }

    public function test_packages_and_tiers_stay_inside_their_restaurant(): void
    {
        [$a, $item, $cashierA] = $this->shop('cafe-a');
        [$b, $itemB, $cashierB] = $this->shop('cafe-b');
        $copy = $this->as($a, function () use ($cashierA) {
            $package = PrepaidPackage::query()->create(['name' => 'باقة', 'price' => 100, 'uses' => 5, 'valid_days' => 7, 'is_active' => true]);
            $customer = Customer::query()->create(['phone' => self::PHONE, 'name' => 'Mona']);
            MembershipTier::query()->create(['name' => 'VIP', 'min_visits' => 0, 'min_spent' => 0, 'discount_percent' => 20, 'color' => '#000000']);

            return app(Packages::class)->sell($package, $customer, 'cash', $cashierA, app(ShiftService::class)->current($cashierA));
        });

        // cafe-b's cashier cannot touch cafe-a's package, and cafe-a's tier does not discount cafe-b's orders
        $this->actingAs($cashierB)->post($this->dashUrl("/loyalty/packages/{$copy->id}/use"))->assertNotFound();
        $this->assertSame(5, $this->as($a, fn () => CustomerPackage::query()->findOrFail($copy->id)->uses_left));
        $this->assertSame(60.0, (float) $this->paidOrder($b, $itemB, $cashierB)->total);
        $this->assertSame(0, $this->as($b, fn () => MembershipTier::query()->count()));
    }
}
