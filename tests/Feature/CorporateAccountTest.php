<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CorporateContract;
use App\Models\CorporatePayment;
use App\Models\CorporateVoucher;
use App\Models\MenuItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * The company account of a corporate contract: payments recorded one by one (cancelled ones kept, not counted), and
 * the statement with what the company was charged, paid, owes, drew and still has on its vouchers.
 */
class CorporateAccountTest extends TenantTestCase
{
    private Tenant $tenant;

    private MenuItem $latte;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->restaurant('cafe-a', 'enterprise');
        $this->latte = $this->item($this->tenant, 'Latte', 60);
        $this->cashier = $this->staff($this->tenant, 'cashier', 'cashier@cafe-a.test');
        $this->manager = $this->staff($this->tenant, 'manager', 'manager@cafe-a.test');
        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 100])->assertSessionHasNoErrors();
    }

    /** 10 balance vouchers of 200 (2000 in all), signed with $paid paid by transfer. */
    private function signContract(float $paid, array $extra = []): CorporateContract
    {
        $this->actingAs($this->manager)->post($this->dashUrl('/corporate'), $extra + [
            'name' => 'Eva Pharma', 'voucher_value' => 200, 'quantity' => 10, 'expires_on' => local_at()->addMonths(3)->format('Y-m-d'),
            'mode' => 'balance', 'paid_amount' => $paid, 'paid_method' => 'transfer', 'reference' => 'C-77',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return $this->as($this->tenant, fn () => CorporateContract::query()->latest('id')->firstOrFail());
    }

    private function fresh(CorporateContract $contract): CorporateContract
    {
        return $this->as($this->tenant, fn () => CorporateContract::query()->findOrFail($contract->id));
    }

    private function payments(CorporateContract $contract)
    {
        return $this->as($this->tenant, fn () => CorporatePayment::query()->where('corporate_contract_id', $contract->id)->orderBy('id')->get());
    }

    public function test_payments_are_recorded_one_by_one_and_a_cancelled_one_stops_counting(): void
    {
        $contract = $this->signContract(500);
        $first = $this->payments($contract)->sole();
        $this->assertSame([500.0, 'transfer', 'C-77'], [$first->amount, $first->method, $first->reference]);
        $this->assertSame([500.0, 'partial'], [$this->fresh($contract)->paid_amount, $this->fresh($contract)->paid_status]);

        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/{$contract->id}/payments"), [
            'amount' => 1500, 'method' => 'cheque', 'reference' => 'CHQ-9', 'paid_on' => local_at()->subDay()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([2000.0, 'paid'], [$this->fresh($contract)->paid_amount, $this->fresh($contract)->paid_status]);

        // entered by mistake: cancelled with a reason, kept on the statement, no longer counted
        $cheque = $this->payments($contract)->last();
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/payments/{$cheque->id}/cancel"), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/payments/{$cheque->id}/cancel"), ['reason' => 'cheque bounced'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/payments/{$cheque->id}/cancel"), ['reason' => 'again'])->assertSessionHasErrors('reason');
        $this->assertSame([500.0, 'partial'], [$this->fresh($contract)->paid_amount, $this->fresh($contract)->paid_status]);
        $this->assertCount(2, $this->payments($contract));
        $this->assertTrue($this->as($this->tenant, fn () => AuditLog::query()->where('action', 'corporate.payment_cancelled')->exists()));

        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/{$contract->id}/payments"), ['amount' => 0, 'method' => 'cash'])->assertSessionHasErrors('amount');
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/{$contract->id}/payments"), ['amount' => 10, 'method' => 'gold'])->assertSessionHasErrors('method');
    }

    public function test_the_statement_shows_charged_paid_owed_drawn_and_what_is_left_on_the_vouchers(): void
    {
        $contract = $this->signContract(500, ['billing' => 'account', 'credit_limit' => 3000]);
        $this->assertSame(['account', 3000.0], [$contract->billing, $contract->credit_limit]);
        $voucher = $this->as($this->tenant, fn () => CorporateVoucher::query()->where('corporate_contract_id', $contract->id)->orderBy('id')->firstOrFail());

        // one of its people drew 120 of a voucher at the POS
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $this->latte->id, 'qty' => 2]],
            'pay' => 'corporate', 'vouchers' => [['code' => $voucher->code]],
        ])->assertOk();

        $page = $this->actingAs($this->manager)->get($this->dashUrl("/corporate/{$contract->id}/statement"))->assertOk();
        $account = $page->viewData('account');
        $this->assertSame(2000.0, $account['charged']);
        $this->assertSame(500.0, $account['paid']);
        $this->assertSame(1500.0, $account['owed']);
        $this->assertSame(120.0, $account['drawn']);
        $this->assertSame(1880.0, $account['voucher_balance']);
        $this->assertSame(1500.0, $account['credit_left']);

        // the ledger: the vouchers issued (a charge), then the payment, with the balance after each
        $ledger = $page->viewData('ledger');
        $this->assertSame([['issue', 2000.0, 2000.0], ['payment', 500.0, 1500.0]], $ledger->map(fn ($l) => [$l['kind'], $l['kind'] === 'issue' ? $l['charge'] : $l['credit'], $l['balance']])->all());
        $page->assertSee('data-payment-form', false)->assertSee('C-77');

        // paid ahead: the balance turns into a credit for the company
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/{$contract->id}/payments"), ['amount' => 2500, 'method' => 'transfer'])->assertSessionHasNoErrors();
        $account = $this->actingAs($this->manager)->get($this->dashUrl("/corporate/{$contract->id}/statement"))->assertOk()->assertSee(__('Paid ahead'))->viewData('account');
        $this->assertSame(-1000.0, $account['owed']);
    }

    public function test_only_who_manages_contracts_records_payments_and_another_restaurant_sees_nothing(): void
    {
        $contract = $this->signContract(0);
        $this->assertCount(0, $this->payments($contract));

        $this->actingAs($this->cashier)->post($this->dashUrl("/corporate/{$contract->id}/payments"), ['amount' => 100, 'method' => 'cash'])->assertForbidden();
        $this->actingAs($this->cashier)->get($this->dashUrl("/corporate/{$contract->id}/statement"))->assertForbidden();

        $other = $this->restaurant('cafe-b', 'enterprise');
        $this->actingAs($this->owner($other))->post($this->dashUrl("/corporate/{$contract->id}/payments"), ['amount' => 100, 'method' => 'cash'])->assertNotFound();
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/{$contract->id}/payments"), ['amount' => 100, 'method' => 'cash'])->assertSessionHasNoErrors();
        $payment = $this->payments($contract)->sole();
        $this->actingAs($this->owner($other))->post($this->dashUrl("/corporate/payments/{$payment->id}/cancel"), ['reason' => 'not ours'])->assertNotFound();
        $this->assertNull($this->payments($contract)->sole()->cancelled_at);
    }
}
