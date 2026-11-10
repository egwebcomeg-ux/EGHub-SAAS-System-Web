<?php

namespace Tests\Feature;

use App\Models\CorporateContract;
use App\Models\CorporateRep;
use App\Models\CorporateRepMovement;
use App\Models\CorporateVoucher;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Corporate\CorporateAccount;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Menu\MenuCatalog;
use App\Services\Orders\OrderService;
use App\Services\Settings;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * The reps of a company: the company is added on its own, each rep gets a balance charged to the company, his
 * vouchers are issued out of it, and he may order on the company's account outside the vouchers (an amount of its own).
 */
class CorporateRepsTest extends TenantTestCase
{
    private const REP_PHONE = '01012345678';

    private Tenant $tenant;

    private MenuItem $latte;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->restaurant('cafe-r', 'full');
        // a paid order stays in the kitchen (one is cancelled below), not done once paid (OrderFlow)
        $this->as($this->tenant, fn () => Settings::put(['order_flow' => 'kitchen']));
        $this->latte = $this->item($this->tenant, 'Latte', 60);
        $this->cashier = $this->staff($this->tenant, 'cashier', 'cashier@cafe-r.test');
        $this->manager = $this->staff($this->tenant, 'manager', 'manager@cafe-r.test');
    }

    /** The company alone, as the form sends it: no vouchers, no contact person. */
    private function company(array $extra = []): CorporateContract
    {
        $this->actingAs($this->manager)->post($this->dashUrl('/corporate'), $extra + [
            'name' => 'Eva Pharma', 'expires_on' => now()->addYear()->format('Y-m-d'), 'mode' => 'balance',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return $this->as($this->tenant, fn () => CorporateContract::query()->latest('id')->firstOrFail());
    }

    private function rep(CorporateContract $company, string $name, ?string $phone = null): CorporateRep
    {
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/{$company->id}/reps"), ['name' => $name, 'phone' => $phone])->assertSessionHasNoErrors();

        return $this->as($this->tenant, fn () => CorporateRep::query()->latest('id')->firstOrFail());
    }

    private function repPost(CorporateRep $rep, string $action, array $data, ?User $by = null): TestResponse
    {
        return $this->actingAs($by ?? $this->manager)->post($this->dashUrl("/corporate/reps/{$rep->id}/$action"), $data);
    }

    private function freshCompany(CorporateContract $company): CorporateContract
    {
        return $this->as($this->tenant, fn () => CorporateContract::query()->findOrFail($company->id));
    }

    private function balance(CorporateRep $rep): float
    {
        return $this->as($this->tenant, fn () => CorporateRep::query()->findOrFail($rep->id)->balance);
    }

    /** An unpaid order of $qty lattes (60 each). */
    private function openOrder(int $qty): Order
    {
        return $this->as($this->tenant, fn () => app(OrderService::class)->place([
            'type' => 'takeaway', 'source' => 'pos', 'items' => [['item_id' => $this->latte->id, 'qty' => $qty]],
        ], MenuCatalog::defaultBranch(), null, $this->owner($this->tenant)));
    }

    public function test_a_company_is_added_on_its_own_and_each_reps_balance_is_charged_to_it(): void
    {
        $company = $this->company(['paid_amount' => 1000, 'paid_method' => 'transfer']);
        $this->assertSame([0, 0.0, null], [$company->quantity, $company->totalValue(), $company->contact_name]);
        $this->assertSame(0, $this->as($this->tenant, fn () => CorporateVoucher::query()->count()));

        $ahmed = $this->rep($company, 'Ahmed', self::REP_PHONE);
        $mona = $this->rep($company, 'Mona');
        $this->repPost($ahmed, 'topup', ['amount' => 600])->assertSessionHasNoErrors();
        $this->assertSame(600.0, $this->balance($ahmed));
        $this->assertSame([600.0, 600.0, 'paid'], [$this->freshCompany($company)->rep_value, $this->freshCompany($company)->totalValue(), $this->freshCompany($company)->paid_status]);

        // a prepaid company's reps can't hold more than it paid: 400 is left of the 1000
        $this->repPost($mona, 'topup', ['amount' => 500])->assertSessionHasErrors('rep');
        $this->assertSame(0.0, $this->balance($mona));
        $this->repPost($mona, 'topup', ['amount' => 400])->assertSessionHasNoErrors();
        $this->assertSame(1000.0, $this->freshCompany($company)->totalValue());

        // what is not on vouchers goes back to the company, free for another rep
        $this->repPost($ahmed, 'withdraw', ['amount' => 700])->assertSessionHasErrors('rep');
        $this->repPost($ahmed, 'withdraw', ['amount' => 100])->assertSessionHasNoErrors();
        $this->assertSame([500.0, 900.0], [$this->balance($ahmed), $this->freshCompany($company)->rep_value]);

        $this->actingAs($this->manager)->get($this->dashUrl("/corporate/{$company->id}"))->assertOk()->assertSee('data-rep="Ahmed"', false)->assertSee('data-rep="Mona"', false);
        $this->actingAs($this->manager)->get($this->dashUrl("/corporate/{$company->id}/statement"))->assertOk()
            ->assertSee('data-statement-rep="Ahmed"', false)->assertSee('data-ledger-row="rep"', false)->assertSee('data-ledger-row="rep_back"', false);
    }

    public function test_a_reps_vouchers_come_out_of_his_balance_and_a_voided_one_goes_back_to_it(): void
    {
        $company = $this->company(['billing' => 'account']);
        $ahmed = $this->rep($company, 'Ahmed', self::REP_PHONE);
        $mona = $this->rep($company, 'Mona');
        $this->repPost($ahmed, 'topup', ['amount' => 500])->assertSessionHasNoErrors();
        $this->repPost($mona, 'topup', ['amount' => 100])->assertSessionHasNoErrors();
        $issue = fn (CorporateRep $rep, int $count) => $this->repPost($rep, 'vouchers', [
            'value' => 100, 'count' => $count, 'expires_on' => now()->addMonth()->format('Y-m-d'), 'mode' => 'single',
        ]);

        $issue($ahmed, 6)->assertSessionHasErrors('rep'); // 600 out of 500
        $this->assertSame(0, $this->as($this->tenant, fn () => CorporateVoucher::query()->count()));
        $issue($ahmed, 4)->assertSessionHasNoErrors();
        $issue($mona, 1)->assertSessionHasNoErrors();

        $his = $this->as($this->tenant, fn () => CorporateVoucher::query()->where('corporate_rep_id', $ahmed->id)->orderBy('id')->get());
        $this->assertCount(4, $his);
        $this->assertSame([100.0, 100.0, 'single', 'Ahmed', self::REP_PHONE], [$his[0]->value, $his[0]->balance, $his[0]->modeName(), $his[0]->handed_to, $his[0]->handed_to_phone]);
        $this->assertSame(100.0, $this->balance($ahmed));
        // issuing moves no money of the company: it was charged when the balance was put
        $this->assertSame([600.0, 0], [$this->freshCompany($company)->totalValue(), $this->freshCompany($company)->quantity]);

        // his print holds his vouchers only
        $hers = $this->as($this->tenant, fn () => CorporateVoucher::query()->where('corporate_rep_id', $mona->id)->sole());
        $this->actingAs($this->manager)->get($this->dashUrl("/corporate/{$company->id}/print?rep_id={$ahmed->id}&unprinted=1"))->assertOk()
            ->assertSee('data-print-voucher="'.$his[0]->serial.'"', false)->assertDontSee('data-print-voucher="'.$hers->serial.'"', false);

        // a voided voucher's value is his balance again, once
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/vouchers/{$his[0]->id}/void"))->assertSessionHasNoErrors();
        $this->assertSame(200.0, $this->balance($ahmed));
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/vouchers/{$his[0]->id}/void"))->assertSessionHasErrors('voucher');
        $this->assertSame(200.0, $this->balance($ahmed));
        $this->assertSame(['topup', 'issue', 'return'], $this->as($this->tenant, fn () => CorporateRepMovement::query()->where('corporate_rep_id', $ahmed->id)->orderBy('id')->pluck('kind')->all()));
    }

    public function test_a_rep_orders_on_the_companys_account_outside_his_vouchers_as_an_amount_of_its_own(): void
    {
        $company = $this->company(['billing' => 'account', 'credit_limit' => 300]);
        $ahmed = $this->rep($company, 'Ahmed', self::REP_PHONE);
        $this->repPost($this->rep($company, 'Mona'), 'direct', ['left' => 200])->assertSessionHasErrors('rep'); // no mobile to know her by
        $this->repPost($ahmed, 'direct', ['left' => 500])->assertSessionHasNoErrors();

        // the cashier types the rep's mobile: his allowance comes up, apart from any voucher
        $found = $this->actingAs($this->cashier)->postJson($this->dashUrl('/vouchers/redeem/check'), ['query' => self::REP_PHONE])->assertOk()->json('vouchers');
        $this->assertCount(1, $found);
        $this->assertSame([true, true, 500], [$found[0]['direct'], $found[0]['ok'], $found[0]['balance']]);
        $pay = fn (Order $order) => $this->actingAs($this->cashier)->postJson($this->dashUrl("/vouchers/redeem/orders/{$order->id}/pay"), [
            'method' => 'corporate', 'vouchers' => [['id' => $found[0]['id']]], 'voucher_phone' => self::REP_PHONE,
        ]);

        $first = $this->openOrder(3); // 180
        $pay($first)->assertOk()->assertJsonPath('covered', 180);
        $account = $this->as($this->tenant, fn () => app(CorporateAccount::class)->summary($this->freshCompany($company)));
        $this->assertSame([180.0, 180.0, 0.0, 180.0], [$this->freshCompany($company)->direct_value, $account['direct'], $account['drawn'], $account['owed']]);
        // an allowance is not a voucher to print or to count as value left
        $this->assertSame(0.0, $this->as($this->tenant, fn () => app(CorporateVouchers::class)->stats($this->freshCompany($company))['remaining_value']));
        $this->actingAs($this->manager)->get($this->dashUrl("/corporate/{$company->id}/statement"))->assertOk()
            ->assertSee('data-ledger-row="direct"', false)->assertSee('data-statement-on-account', false);

        // the company's credit (300) covers 120 more only: nothing is taken
        $second = $this->openOrder(3);
        $pay($second)->assertStatus(422);
        $this->assertSame('unpaid', $this->as($this->tenant, fn () => Order::query()->find($second->id)->payment_status));
        $this->assertSame(180.0, $this->freshCompany($company)->direct_value);

        // a cancelled order is no longer charged, and his allowance is whole again
        $this->as($this->tenant, fn () => app(OrderService::class)->transition(Order::query()->find($first->id), 'cancelled', $this->cashier));
        $this->assertSame(0.0, $this->freshCompany($company)->direct_value);
        $this->assertSame(500.0, $this->as($this->tenant, fn () => CorporateVoucher::query()->where('direct', true)->sole()->balance));

        // a stopped rep orders nothing more
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/reps/{$ahmed->id}"), ['name' => 'Ahmed', 'phone' => self::REP_PHONE])->assertSessionHasNoErrors();
        $stopped = $this->actingAs($this->cashier)->postJson($this->dashUrl('/vouchers/redeem/check'), ['query' => self::REP_PHONE])->assertOk()->json('vouchers.0');
        $this->assertFalse($stopped['ok']);
        $pay($this->openOrder(1))->assertStatus(422);
    }

    public function test_only_who_manages_contracts_changes_reps_and_another_restaurant_reaches_none_of_them(): void
    {
        $company = $this->company(['billing' => 'account']);
        $ahmed = $this->rep($company, 'Ahmed', self::REP_PHONE);
        $this->repPost($ahmed, 'direct', ['left' => 500])->assertSessionHasNoErrors();

        $this->repPost($ahmed, 'topup', ['amount' => 100], $this->cashier)->assertForbidden();
        $this->actingAs($this->cashier)->post($this->dashUrl("/corporate/{$company->id}/reps"), ['name' => 'X'])->assertForbidden();

        $other = $this->restaurant('cafe-s', 'full');
        $them = $this->owner($other);
        $this->actingAs($them)->post($this->dashUrl("/corporate/{$company->id}/reps"), ['name' => 'X'])->assertNotFound();
        foreach (['topup' => ['amount' => 100], 'withdraw' => ['amount' => 1], 'direct' => ['left' => 0],
            'vouchers' => ['value' => 10, 'count' => 1, 'expires_on' => now()->addMonth()->format('Y-m-d'), 'mode' => 'single']] as $action => $data) {
            $this->repPost($ahmed, $action, $data, $them)->assertNotFound();
        }
        $this->actingAs($them)->post($this->dashUrl("/corporate/reps/{$ahmed->id}"), ['name' => 'Taken'])->assertNotFound();
        // the same mobile typed at the other restaurant's cashier finds nothing
        $this->assertSame([], $this->actingAs($them)->postJson($this->dashUrl('/vouchers/redeem/check'), ['query' => self::REP_PHONE])->assertOk()->json('vouchers'));

        $this->assertSame(['Ahmed', 0.0], $this->as($this->tenant, fn () => [CorporateRep::query()->sole()->name, CorporateRep::query()->sole()->balance]));
        $this->assertSame(500.0, $this->as($this->tenant, fn () => CorporateVoucher::query()->where('direct', true)->sole()->balance));
    }
}
