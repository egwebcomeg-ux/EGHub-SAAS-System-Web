<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\GiftCard;
use App\Models\LoyaltyProgram;
use App\Models\Order;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\WalletTransaction;
use App\Services\Entitlements;
use App\Services\Orders\OrderService;
use App\Services\Orders\ShiftService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** Prepaid wallet & gift cards: top-up / pay / refund keep the ledger equal to the balance, gift cards redeem once. */
class WalletTest extends TenantTestCase
{
    private const PHONE = '01012345678';

    /** A restaurant with the POS pack and the wallet pack, a 60 EGP latte and a cashier with an open shift. */
    private function shop(string $slug = 'cafe-a'): array
    {
        $tenant = $this->restaurant($slug, 'pro');
        app(Billing::class)->setAddon($tenant, 'pos_pack', 1);
        app(Billing::class)->setAddon($tenant, 'wallet_pack', 1);
        $item = $this->item($tenant, 'Latte', 60);
        $cashier = $this->staff($tenant, 'cashier', "cashier@$slug.test");
        $this->actingAs($cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 100])->assertSessionHasNoErrors();

        return [$tenant, $item, $cashier];
    }

    private function balance(Tenant $tenant, string $phone = self::PHONE): float
    {
        return $this->as($tenant, fn () => (float) Customer::query()->where('phone', $phone)->value('credit_balance'));
    }

    /** The ledger of every customer adds up to their balance. */
    private function assertLedgerConsistent(Tenant $tenant): void
    {
        $this->as($tenant, function () {
            foreach (Customer::query()->get() as $c) {
                $sum = round((float) WalletTransaction::query()->where('customer_id', $c->id)->sum('delta'), 2);
                $this->assertSame($sum, round((float) $c->credit_balance, 2), "ledger of {$c->phone}");
                $this->assertGreaterThanOrEqual(0, $c->credit_balance);
            }
        });
    }

    public function test_wallet_is_behind_the_feature_and_the_permission(): void
    {
        $tenant = $this->restaurant('cafe-a', 'pro');
        app(Billing::class)->setAddon($tenant, 'pos_pack', 1);
        $owner = $this->owner($tenant);
        $this->actingAs($owner)->get($this->dashUrl('/wallet'))->assertForbidden();
        $this->actingAs($owner)->postJson($this->dashUrl('/wallet/topup'), ['phone' => self::PHONE, 'amount' => 50, 'method' => 'cash'])->assertForbidden();
        $this->actingAs($owner)->get($this->dashUrl('/pos'))->assertOk()->assertDontSee('data-topup-open', false);
        $this->get($this->subUrl('cafe-a', 'gift/ABCDEFGHJKMN'))->assertNotFound();

        app(Billing::class)->setAddon($tenant, 'wallet_pack', 1);
        $this->actingAs($owner)->get($this->dashUrl('/wallet'))->assertOk()->assertSee('المحفظة وكروت الهدايا');
        $this->actingAs($owner)->get($this->dashUrl('/pos'))->assertOk()->assertSee('data-topup-open', false)->assertSee('data-method="credit"', false);
        $waiter = $this->staff($tenant, 'waiter', 'w@a.test');
        $this->actingAs($waiter)->get($this->dashUrl('/wallet'))->assertForbidden();
        $this->actingAs($waiter)->get($this->dashUrl('/pos'))->assertOk()->assertDontSee('data-topup-open', false);

        // Business has the wallet without the add-on
        $this->assertTrue(Entitlements::for($this->restaurant('cafe-b', 'business'))->allows('wallet'));
    }

    public function test_top_up_pay_and_refund_keep_the_ledger_consistent(): void
    {
        [$a, $item, $cashier] = $this->shop();

        $this->actingAs($cashier)->postJson($this->dashUrl('/wallet/topup'), ['phone' => '+20 10 1234 5678', 'name' => 'Mona', 'amount' => 200, 'method' => 'cash'])
            ->assertOk()->assertJsonPath('balance', 200);
        $this->actingAs($cashier)->postJson($this->dashUrl('/wallet/topup'), ['phone' => self::PHONE, 'amount' => 50.555, 'method' => 'card'])
            ->assertOk()->assertJsonPath('balance', 250.56);
        $this->assertSame(250.56, $this->balance($a));

        // the shift counts the cash top-up in the drawer, the card one apart, and neither as a sale
        $shift = $this->as($a, fn () => Shift::query()->first());
        $summary = app(ShiftService::class)->summary($shift);
        $this->assertSame(['cash' => 200.0, 'card' => 50.56], $summary['prepaid']);
        $this->assertSame(300.0, $summary['expected_cash']); // 100 opening + 200 cash top-up
        $this->assertSame(0.0, $summary['total']);

        // pay a POS order from the balance (whole total)
        $this->actingAs($cashier)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'customer_phone' => self::PHONE,
            'items' => [['item_id' => $item->id, 'qty' => 2]], 'pay' => 'credit',
        ])->assertOk()->assertJsonPath('paid', true);
        $order = $this->as($a, fn () => Order::query()->latest('id')->first());
        $this->assertSame('credit', $order->payment_method);
        $this->assertSame(round(250.56 - $order->total, 2), $this->balance($a));
        $this->assertSame((float) $order->total, app(ShiftService::class)->summary($shift)['by_method']['credit']);

        // cancelled: the money goes back, once
        $this->as($a, function () use ($order, $cashier) {
            app(OrderService::class)->transition($order, 'cancelled', $cashier);
            $this->assertNull(app(WalletService::class)->refundOrder($order));
        });
        $this->assertSame(250.56, $this->balance($a));
        $this->assertSame(['topup', 'topup', 'payment', 'refund'], $this->as($a, fn () => WalletTransaction::query()->orderBy('id')->pluck('kind')->all()));
        $this->assertLedgerConsistent($a);

        $this->actingAs($cashier)->get($this->dashUrl('/wallet?tab=ledger'))->assertOk()->assertSee('استرداد');
        $tx = $this->as($a, fn () => WalletTransaction::query()->first());
        $this->actingAs($cashier)->get($this->dashUrl("/wallet/topups/{$tx->id}"))->assertOk()->assertSeeInOrder(['الرصيد دلوقتي', '200'], false);
    }

    public function test_paying_more_than_the_balance_is_refused(): void
    {
        [$a, $item, $cashier] = $this->shop();
        $this->actingAs($cashier)->postJson($this->dashUrl('/wallet/topup'), ['phone' => self::PHONE, 'amount' => 50, 'method' => 'cash'])->assertOk();

        // an open table order (no customer yet), settled from the wallet: 60 > 50
        $table = $this->as($a, fn () => DiningTable::query()->first());
        $id = $this->actingAs($cashier)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'dine_in', 'table_id' => $table->id, 'items' => [['item_id' => $item->id, 'qty' => 1]],
        ])->assertOk()->json('id');
        $this->actingAs($cashier)->postJson($this->dashUrl("/pos/orders/$id/pay"), ['method' => 'credit', 'phone' => self::PHONE])
            ->assertStatus(422)->assertJsonFragment(['message' => 'الرصيد مش كفاية: المحفظة فيها 50.']);
        $this->actingAs($cashier)->postJson($this->dashUrl("/pos/orders/$id/pay"), ['method' => 'credit', 'phone' => '01099999999'])->assertStatus(422);

        $this->assertSame(50.0, $this->balance($a));
        $this->assertSame('unpaid', $this->as($a, fn () => Order::query()->find($id)->payment_status));
        $this->assertLedgerConsistent($a);

        // topped up again, the same order goes through
        $this->actingAs($cashier)->postJson($this->dashUrl('/wallet/topup'), ['phone' => self::PHONE, 'amount' => 20, 'method' => 'cash'])->assertOk();
        $this->actingAs($cashier)->postJson($this->dashUrl("/pos/orders/$id/pay"), ['method' => 'credit', 'phone' => self::PHONE])->assertOk();
        $this->assertSame(round(70 - $this->as($a, fn () => Order::query()->find($id)->total), 2), $this->balance($a));
        $this->assertLedgerConsistent($a);
    }

    public function test_guest_pays_online_from_the_balance_of_the_card_on_this_phone(): void
    {
        [$a, $item, $cashier] = $this->shop();
        $this->actingAs($cashier)->postJson($this->dashUrl('/wallet/topup'), ['phone' => self::PHONE, 'amount' => 500, 'method' => 'cash'])->assertOk();
        $token = $this->as($a, fn () => Customer::query()->where('phone', self::PHONE)->value('card_token'));
        $order = fn (array $extra = []) => $this->postJson($this->subUrl('cafe-a', 'order'), $extra + [
            'client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $item->id, 'qty' => 1]], 'type' => 'takeaway',
            'customer_name' => 'Mona', 'customer_phone' => self::PHONE, 'pay_wallet' => true,
        ]);

        // the card link remembers the card on this phone and shows the balance
        $this->get($this->subUrl('cafe-a', "card/$token"))->assertOk()->assertSeeInOrder(['data-wallet-balance', '500'], false)->assertCookie('card_cafe-a');
        $this->withCookie('card_cafe-a', $token)->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-pay-wallet', false);

        $order()->assertStatus(422);                                                            // no card on this phone
        $this->withCredentials()->withCookie('card_cafe-a', $token); // fetch() sends the cookie with the JSON request
        $order(['customer_phone' => '01099999999'])->assertStatus(422);                         // someone else's number
        $url = $order()->assertOk()->json('url');
        $placed = $this->as($a, fn () => Order::query()->latest('id')->first());
        $this->assertSame(['paid', 'credit'], [$placed->payment_status, $placed->payment_method]);
        $this->assertSame(round(500 - $placed->total, 2), $this->balance($a));
        $this->get($url)->assertOk()->assertSee('اتدفع من رصيدك');

        $this->as($a, fn () => app(OrderService::class)->transition($placed, 'cancelled'));
        $this->assertSame(500.0, $this->balance($a));
        $this->assertLedgerConsistent($a);
    }

    public function test_gift_card_is_sold_printed_and_redeemed_once(): void
    {
        [$a, , $cashier] = $this->shop();
        $res = $this->actingAs($cashier)->postJson($this->dashUrl('/wallet/gift-cards'), [
            'amount' => 300, 'method' => 'instapay', 'recipient_phone' => '01155555555', 'message' => 'Happy birthday',
        ])->assertOk();
        $card = $this->as($a, fn () => GiftCard::query()->firstOrFail());
        $this->assertSame(12, strlen($card->code));
        $this->assertSame(['instapay' => 300.0], app(ShiftService::class)->summary($this->as($a, fn () => Shift::query()->first()))['prepaid']);

        $this->actingAs($cashier)->get($res->json('print'))->assertOk()
            ->assertSee($card->formattedCode())->assertSee('Happy birthday')->assertSee('<svg', false);

        $this->get($this->subUrl('cafe-a', "gift/{$card->code}"))->assertOk()->assertSeeInOrder(['data-amount', '300'], false)->assertSee('01155555555');
        // a new guest: the balance and their card open on this phone
        $this->post($this->subUrl('cafe-a', "gift/{$card->code}"), ['phone' => '01155555555'])
            ->assertRedirectContains('/card/')->assertCookie('card_cafe-a');
        $this->assertSame(300.0, $this->balance($a, '01155555555'));

        // once only
        $this->post($this->subUrl('cafe-a', "gift/{$card->code}"), ['phone' => self::PHONE])->assertSessionHasErrors('phone');
        $this->assertSame(0.0, $this->balance($a));
        $this->assertSame('redeemed', $card->fresh()->status);
        $this->assertLedgerConsistent($a);
    }

    public function test_an_existing_wallet_is_credited_without_opening_it_to_whoever_typed_the_number(): void
    {
        [$a, , $cashier] = $this->shop();
        $this->actingAs($cashier)->postJson($this->dashUrl('/wallet/topup'), ['phone' => self::PHONE, 'amount' => 100, 'method' => 'cash'])->assertOk();
        $code = $this->actingAs($cashier)->postJson($this->dashUrl('/wallet/gift-cards'), ['amount' => 50, 'method' => 'cash'])->json('code');

        $this->post($this->subUrl('cafe-a', 'gift/'.str_replace('-', '', $code)), ['phone' => self::PHONE])
            ->assertRedirect()->assertSessionHas('ok')->assertCookieMissing('card_cafe-a');
        $this->assertSame(150.0, $this->balance($a));
    }

    public function test_gift_cards_never_work_across_restaurants_and_void_or_expired_ones_are_refused(): void
    {
        [$a, , $cashierA] = $this->shop('cafe-a');
        [$b] = $this->shop('cafe-b');
        $code = str_replace('-', '', $this->actingAs($cashierA)->postJson($this->dashUrl('/wallet/gift-cards'), ['amount' => 100, 'method' => 'cash'])->json('code'));

        $this->get($this->subUrl('cafe-b', "gift/$code"))->assertNotFound();
        $this->post($this->subUrl('cafe-b', "gift/$code"), ['phone' => self::PHONE])->assertSessionHasErrors('phone');
        $this->assertSame(0.0, $this->balance($b));
        $this->assertSame('active', $this->as($a, fn () => GiftCard::query()->first()->status));

        // voided from the dashboard (owner / manager only, a cashier can't destroy prepaid value): no longer redeemable
        $card = $this->as($a, fn () => GiftCard::query()->first());
        $this->actingAs($cashierA)->post($this->dashUrl("/wallet/gift-cards/{$card->id}/void"))->assertForbidden();
        $this->assertSame('active', $this->as($a, fn () => GiftCard::query()->first()->status));
        $this->actingAs($this->staff($a, 'manager', 'm@a.test'))->post($this->dashUrl("/wallet/gift-cards/{$card->id}/void"))->assertSessionHasNoErrors();
        $this->assertSame('void', $this->as($a, fn () => GiftCard::query()->first()->status));
        $this->post($this->subUrl('cafe-a', "gift/$code"), ['phone' => self::PHONE])->assertSessionHasErrors('phone');

        // expired
        $other = $this->as($a, fn () => GiftCard::query()->create(['code' => 'EXPRDCARD234', 'amount' => 80, 'balance' => 80, 'status' => 'active', 'expires_at' => now()->subDay()]));
        $this->post($this->subUrl('cafe-a', "gift/{$other->code}"), ['phone' => self::PHONE])->assertSessionHasErrors('phone');
        $this->assertSame(0.0, $this->balance($a));

        // another restaurant's staff can't void or print it
        $this->actingAs($this->staff($b, 'manager', 'm@b.test'))->get($this->dashUrl("/wallet/gift-cards/{$card->id}/print"))->assertNotFound();
    }

    public function test_loyalty_join_does_not_hand_out_a_card_that_holds_a_balance(): void
    {
        [$a, , $cashier] = $this->shop();
        $this->as($a, fn () => LoyaltyProgram::query()->create([
            'type' => 'visits', 'title' => 'Coffee card', 'stamps_required' => 5, 'reward_type' => 'free_item', 'reward_label' => 'Free latte', 'is_active' => true,
        ]));
        $this->actingAs($cashier)->postJson($this->dashUrl('/wallet/topup'), ['phone' => self::PHONE, 'amount' => 100, 'method' => 'cash'])->assertOk();

        $this->post($this->subUrl('cafe-a', 'card'), ['phone' => self::PHONE])->assertSessionHasErrors('phone')->assertCookieMissing('card_cafe-a');
        $this->post($this->subUrl('cafe-a', 'card'), ['phone' => '01199999999'])->assertRedirectContains('/card/');
    }

    public function test_manual_adjustment_needs_a_manager_and_never_goes_below_zero(): void
    {
        [$a, , $cashier] = $this->shop();
        $this->actingAs($cashier)->postJson($this->dashUrl('/wallet/topup'), ['phone' => self::PHONE, 'amount' => 40, 'method' => 'cash'])->assertOk();
        $customer = $this->as($a, fn () => Customer::query()->first());

        $this->actingAs($cashier)->post($this->dashUrl("/wallet/customers/{$customer->id}/adjust"), ['delta' => -10, 'note' => 'x'])->assertForbidden();
        $owner = $this->owner($a);
        $this->actingAs($owner)->post($this->dashUrl("/wallet/customers/{$customer->id}/adjust"), ['delta' => -50, 'note' => 'too much'])->assertSessionHasErrors('delta');
        $this->actingAs($owner)->post($this->dashUrl("/wallet/customers/{$customer->id}/adjust"), ['delta' => -15.5, 'note' => 'refund cup'])->assertSessionHasNoErrors();
        $this->assertSame(24.5, $this->balance($a));
        $this->assertLedgerConsistent($a);
        $this->assertSame(24.5, $this->actingAs($owner)->getJson($this->dashUrl('/wallet/customer?phone='.self::PHONE))->json('balance'));
    }

    public function test_a_spent_wallet_still_does_not_open_to_whoever_types_the_number(): void
    {
        [$a, , $cashier] = $this->shop();
        $this->as($a, fn () => LoyaltyProgram::query()->create([
            'type' => 'visits', 'title' => 'Coffee card', 'stamps_required' => 5, 'reward_type' => 'free_item', 'reward_label' => 'Free latte', 'is_active' => true,
        ]));
        $this->actingAs($cashier)->postJson($this->dashUrl('/wallet/topup'), ['phone' => self::PHONE, 'amount' => 30, 'method' => 'cash'])->assertOk();
        $customer = $this->as($a, fn () => Customer::query()->first());
        $this->actingAs($this->owner($a))->post($this->dashUrl("/wallet/customers/{$customer->id}/adjust"), ['delta' => -30, 'note' => 'spent'])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->balance($a));

        // balance 0, but the history (amounts, order numbers) is still private; the answer doesn't mention money
        $this->post($this->subUrl('cafe-a', 'card'), ['phone' => self::PHONE])->assertSessionHasErrors('phone')->assertCookieMissing('card_cafe-a');
        $this->assertStringNotContainsString('رصيد', session('errors')->first('phone'));
    }
}
