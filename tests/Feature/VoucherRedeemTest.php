<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CorporateContract;
use App\Models\CorporateVoucher;
use App\Models\CorporateVoucherRedemption;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Entitlements;
use App\Services\Menu\MenuCatalog;
use App\Services\Orders\OrderService;
use App\Services\Reports\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * The "redeem vouchers" screen (no POS): who may use it, vouchers on an open order of the branch or on a paper bill,
 * a manager cancelling a paper bill, exceptions after expiry, the receipt and tenant isolation.
 */
class VoucherRedeemTest extends TenantTestCase
{
    private Tenant $tenant;

    private MenuItem $latte;

    private User $cashier;

    private User $manager;

    /** A Complete restaurant (corporate vouchers, no POS) with a 60 EGP latte, a cashier and a manager. */
    private function shop(string $slug = 'cafe-c', string $plan = 'full'): void
    {
        $this->tenant = $this->restaurant($slug, $plan);
        $this->latte = $this->item($this->tenant, 'Latte', 60);
        $this->cashier = $this->staff($this->tenant, 'cashier', "cashier@$slug.test");
        $this->manager = $this->staff($this->tenant, 'manager', "manager@$slug.test");
    }

    private function contract(array $overrides = []): CorporateContract
    {
        return $this->as($this->tenant, fn () => app(CorporateVouchers::class)->createContract($overrides + [
            'name' => 'Eva Pharma', 'voucher_value' => 200, 'quantity' => 3, 'expires_on' => now()->addMonth()->format('Y-m-d'), 'mode' => 'single',
        ], $this->owner($this->tenant)));
    }

    /** @return list<CorporateVoucher> */
    private function vouchers(CorporateContract $contract): array
    {
        return $this->as($this->tenant, fn () => CorporateVoucher::query()->where('corporate_contract_id', $contract->id)->orderBy('id')->get()->all());
    }

    private function fresh(CorporateVoucher $voucher): CorporateVoucher
    {
        return $this->as($this->tenant, fn () => CorporateVoucher::query()->findOrFail($voucher->id));
    }

    /** An unpaid order of $qty lattes, as the order board has it (a table order taken by the waiter). */
    private function openOrder(int $qty): Order
    {
        return $this->as($this->tenant, fn () => app(OrderService::class)->place([
            'type' => 'takeaway', 'source' => 'pos', 'items' => [['item_id' => $this->latte->id, 'qty' => $qty]],
        ], MenuCatalog::defaultBranch(), null, $this->owner($this->tenant)));
    }

    private function manual(array $vouchers, float $amount, ?User $by = null, array $extra = []): TestResponse
    {
        return $this->actingAs($by ?? $this->cashier)->postJson($this->dashUrl('/vouchers/redeem/manual'), ['amount' => $amount, 'vouchers' => $vouchers] + $extra);
    }

    public function test_the_screen_needs_the_plan_feature_and_the_redeem_permission_not_the_pos(): void
    {
        $this->shop();
        $this->assertFalse(Entitlements::for($this->tenant)->allows('pos'));
        [$voucher] = $this->vouchers($this->contract());

        $this->actingAs($this->cashier)->get($this->dashUrl('/vouchers/redeem'))->assertOk()->assertSee('صرف الفاوتشرات')->assertSee('data-redeem', false)
            ->assertSee(route('dash.redeem', [], false));
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/vouchers/redeem/check'), ['query' => $voucher->code])->assertOk()
            ->assertJsonPath('vouchers.0.serial', 'EVA-0001')->assertJsonPath('vouchers.0.ok', true);
        // the POS endpoints stay closed on this plan
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/vouchers/check'), ['query' => $voucher->code])->assertForbidden();

        // a waiter can't; the sidebar shows it to whoever may redeem
        $waiter = $this->staff($this->tenant, 'waiter', 'w@cafe-c.test');
        $this->actingAs($waiter)->get($this->dashUrl('/vouchers/redeem'))->assertForbidden();
        $this->actingAs($waiter)->postJson($this->dashUrl('/vouchers/redeem/manual'), ['amount' => 100, 'vouchers' => [['code' => $voucher->code]]])->assertForbidden();
        $this->actingAs($waiter)->get($this->dashUrl('/'))->assertOk()->assertDontSee(route('dash.redeem', [], false).'"', false);
        $this->actingAs($this->cashier)->get($this->dashUrl('/'))->assertOk()->assertSee(route('dash.redeem', [], false), false);

        // a plan without corporate vouchers: closed
        $pro = $this->restaurant('cafe-pro', 'pro');
        $this->actingAs($this->owner($pro))->get($this->dashUrl('/vouchers/redeem'))->assertForbidden();
    }

    public function test_vouchers_pay_an_open_order_through_the_pos_pay_path(): void
    {
        $this->shop();
        [$voucher] = $this->vouchers($this->contract(['mode' => 'balance', 'voucher_value' => 100]));
        $order = $this->openOrder(3); // 180
        $older = $this->openOrder(1);
        $this->as($this->tenant, fn () => Order::query()->whereKey($older->id)->update(['created_at' => now()->subDays(2)]));

        // today's unpaid orders of the branch only
        $list = $this->actingAs($this->cashier)->getJson($this->dashUrl('/vouchers/redeem/orders'))->assertOk()->json('orders');
        $this->assertSame([$order->id], array_column($list, 'id'));
        $this->assertEquals(180, $list[0]['total']);

        // 100 by voucher, the rest needs a method; "corporate" alone is refused
        $url = $this->dashUrl("/vouchers/redeem/orders/{$order->id}/pay");
        $this->actingAs($this->cashier)->postJson($url, ['method' => 'corporate', 'vouchers' => [['code' => $voucher->code]]])->assertStatus(422);
        $this->assertSame(100.0, $this->fresh($voucher)->balance); // refused: nothing taken
        $this->actingAs($this->cashier)->postJson($url, ['method' => 'credit', 'vouchers' => [['code' => $voucher->code]]])->assertStatus(422);
        $r = $this->actingAs($this->cashier)->postJson($url, ['method' => 'card', 'vouchers' => [['code' => $voucher->code]]])->assertOk()
            ->assertJsonPath('covered', 100)->assertJsonPath('rest', 80)->assertJsonPath('order', $order->number)
            ->assertJsonPath('lines.0.serial', 'EVA-0001')->assertJsonPath('lines.0.balance', 0);

        $paid = $this->as($this->tenant, fn () => Order::query()->find($order->id));
        $this->assertSame(['paid', 'card', 100.0], [$paid->payment_status, $paid->payment_method, $paid->corporate_amount]);
        $this->assertSame(['used', 0.0], [$this->fresh($voucher)->status, $this->fresh($voucher)->balance]);
        // never twice
        $this->actingAs($this->cashier)->postJson($url, ['method' => 'cash', 'vouchers' => [['code' => $voucher->code]]])->assertStatus(422);

        // the guest's receipt and today's list
        $this->actingAs($this->cashier)->get($r->json('receipt'))->assertOk()->assertSee('data-redeem-receipt', false)->assertSee('EVA-0001')->assertSee('طلب رقم '.$order->number);
        $this->actingAs($this->cashier)->get($this->dashUrl('/vouchers/redeem'))->assertOk()->assertSee('data-recent-row="'.$r->json('batch').'"', false);
    }

    public function test_a_paper_bill_is_a_redemption_without_an_order_and_counts_in_stats_statement_and_reports(): void
    {
        $this->shop();
        $contract = $this->contract();
        [$voucher, $second] = $this->vouchers($contract);

        $this->manual([['code' => $voucher->code]], 0)->assertStatus(422);
        $r = $this->manual([['code' => $voucher->code]], 150, null, ['reference' => 'B-77', 'note' => 'Table 4'])->assertOk()
            ->assertJsonPath('covered', 150)->assertJsonPath('rest', 0)->assertJsonPath('reference', 'B-77')
            ->assertJsonPath('lines.0.lost', 50);
        $row = $this->as($this->tenant, fn () => CorporateVoucherRedemption::query()->firstOrFail());
        $this->assertNull($row->order_id);
        $this->assertSame([150.0, 50.0, 150.0, 'B-77', 'Table 4', $this->cashier->id], [$row->amount, $row->forfeited, $row->bill_amount, $row->reference, $row->note, $row->user_id]);
        $this->assertSame(['used', 0.0], [$this->fresh($voucher)->status, $this->fresh($voucher)->balance]);
        $this->assertSame(0, $this->as($this->tenant, fn () => Order::query()->count()));
        $this->assertSame(1, $this->as($this->tenant, fn () => AuditLog::query()->where('action', 'corporate.manual')->count()));

        // a bill bigger than the voucher: the rest is left to pay
        $this->manual([['code' => $second->code]], 260)->assertOk()->assertJsonPath('covered', 200)->assertJsonPath('rest', 60);

        // contract figures, the company statement and the sales report see them
        $stats = $this->as($this->tenant, fn () => app(CorporateVouchers::class)->stats($contract));
        $this->assertSame([350.0, 50.0, 2], [$stats['consumed'], $stats['forfeited'], $stats['used']]);
        $this->actingAs($this->manager)->get($this->dashUrl("/corporate/{$contract->id}/statement"))->assertOk()
            ->assertSee('data-statement-paper', false)->assertSee('فاتورة ورقية B-77')->assertSee('Table 4');
        $csv = $this->actingAs($this->manager)->get($this->dashUrl("/corporate/{$contract->id}/statement?format=csv"))->assertOk()->streamedContent();
        $this->assertStringContainsString('فاتورة ورقية B-77', $csv);
        $this->assertStringContainsString('350', $csv);
        $report = $this->as($this->tenant, fn () => (new SalesReport(CarbonImmutable::now(), CarbonImmutable::now()))->build());
        $this->assertSame(['count' => 2, 'total' => 350.0], $report['paper_vouchers']);
        $this->actingAs($this->owner($this->tenant))->get($this->dashUrl('/reports'))->assertOk()->assertSee('data-report-paper-vouchers', false);

        $this->actingAs($this->cashier)->get($this->dashUrl('/vouchers/redeem/batches/'.$r->json('batch').'/receipt'))->assertOk()
            ->assertSee('فاتورة ورقية B-77')->assertSee('EVA-0001');
    }

    public function test_a_manager_cancels_a_paper_bill_and_the_balance_comes_back_once(): void
    {
        $this->shop();
        $contract = $this->contract(['mode' => 'balance']);
        [$voucher] = $this->vouchers($contract);
        $batch = $this->manual([['code' => $voucher->code]], 120)->assertOk()->json('batch');
        $this->assertSame(80.0, $this->fresh($voucher)->balance);
        $url = $this->dashUrl("/vouchers/redeem/batches/$batch/cancel");

        // the cashier alone can't; the manager's PIN on the cashier's device can
        $this->actingAs($this->cashier)->postJson($url, ['reason' => 'Paid in cash in the end'])->assertStatus(422);
        $this->as($this->tenant, fn () => app(CorporateVouchers::class)->setPin(User::query()->find($this->manager->id), '4321'));
        $this->actingAs($this->cashier)->postJson($url, ['reason' => 'Paid in cash in the end', 'approver_id' => $this->manager->id, 'pin' => '0000'])->assertStatus(422);
        $this->assertSame(80.0, $this->fresh($voucher)->balance);
        $this->actingAs($this->cashier)->postJson($url, ['reason' => 'Paid in cash in the end', 'approver_id' => $this->manager->id, 'pin' => '4321'])->assertOk();

        $this->assertSame(['active', 200.0], [$this->fresh($voucher)->status, $this->fresh($voucher)->balance]);
        $row = $this->as($this->tenant, fn () => CorporateVoucherRedemption::query()->firstOrFail());
        $this->assertNotNull($row->refunded_at);
        $this->assertSame([$this->manager->id, 'Paid in cash in the end'], [$row->refunded_by, $row->refund_reason]);
        $log = $this->as($this->tenant, fn () => AuditLog::query()->where('action', 'corporate.manual_cancelled')->firstOrFail());
        $this->assertSame($this->manager->id, $log->user_id);

        // once only; not counted any more
        $this->actingAs($this->manager)->postJson($url, ['reason' => 'Again please'])->assertStatus(422);
        $this->assertSame(200.0, $this->fresh($voucher)->balance);
        $this->assertSame(0.0, $this->as($this->tenant, fn () => app(CorporateVouchers::class)->stats($contract)['consumed']));
        $this->actingAs($this->manager)->get($this->dashUrl("/corporate/{$contract->id}/statement"))->assertOk()->assertSee('Paid in cash in the end');

        // a use on an order is given back by cancelling the order, not here
        $order = $this->openOrder(1);
        $paid = $this->actingAs($this->cashier)->postJson($this->dashUrl("/vouchers/redeem/orders/{$order->id}/pay"), ['method' => 'corporate', 'vouchers' => [['code' => $voucher->code]]])
            ->assertOk()->json('batch');
        $this->actingAs($this->manager)->postJson($this->dashUrl("/vouchers/redeem/batches/$paid/cancel"), ['reason' => 'Not this way'])->assertStatus(422);
    }

    public function test_several_vouchers_earliest_first_and_an_expired_one_needs_a_manager(): void
    {
        $this->shop();
        [$late] = $this->vouchers($this->contract(['name' => 'Late Co', 'voucher_value' => 100, 'mode' => 'balance', 'expires_on' => now()->addMonths(2)->format('Y-m-d')]));
        [$early] = $this->vouchers($this->contract(['name' => 'Early Co', 'voucher_value' => 100, 'mode' => 'balance', 'expires_on' => now()->addDays(3)->format('Y-m-d')]));
        [$expired] = $this->vouchers($this->contract(['name' => 'Old Co', 'voucher_value' => 50, 'mode' => 'balance']));
        $this->as($this->tenant, fn () => CorporateVoucher::query()->whereKey($expired->id)->update(['expires_on' => now()->subDays(2)->format('Y-m-d')]));

        $this->manual([['code' => $late->code], ['code' => $early->code]], 150)->assertOk()
            ->assertJsonPath('lines.0.serial', $early->serial)->assertJsonPath('lines.0.amount', 100)->assertJsonPath('lines.1.amount', 50);
        $this->assertSame([0.0, 50.0], [$this->fresh($early)->balance, $this->fresh($late)->balance]);

        // expired: refused until a manager approves on the screen
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/vouchers/redeem/check'), ['query' => $expired->code])->assertOk()
            ->assertJsonPath('vouchers.0.expired', true);
        $this->manual([['code' => $expired->code]], 50)->assertStatus(422);
        $token = $this->actingAs($this->manager)->postJson($this->dashUrl("/vouchers/redeem/{$expired->id}/exception"), ['reason' => 'Company asked to extend'])
            ->assertOk()->json('token');
        // the approval is for whoever asked for it (here the manager)
        $this->manual([['code' => $expired->code, 'approval' => $token]], 50)->assertStatus(422);
        $this->manual([['code' => $expired->code, 'approval' => $token]], 50, $this->manager)->assertOk()->assertJsonPath('covered', 50);
        $row = $this->as($this->tenant, fn () => CorporateVoucherRedemption::query()->where('corporate_voucher_id', $expired->id)->firstOrFail());
        $this->assertSame([$this->manager->id, 'Company asked to extend'], [$row->approved_by, $row->exception_reason]);
    }

    public function test_another_restaurants_vouchers_orders_and_redemptions_are_out_of_reach(): void
    {
        $this->shop();
        [$mine] = $this->vouchers($this->contract());
        $batch = $this->manual([['code' => $mine->code]], 100)->assertOk()->json('batch');

        $b = $this->restaurant('cafe-d', 'full');
        $theirs = $this->as($b, fn () => app(CorporateVouchers::class)->createContract([
            'name' => 'Other Co', 'voucher_value' => 500, 'quantity' => 1, 'expires_on' => now()->addMonth()->format('Y-m-d'), 'mode' => 'single',
        ], $this->owner($b)));
        $code = $this->as($b, fn () => CorporateVoucher::query()->where('corporate_contract_id', $theirs->id)->value('code'));
        $theirItem = $this->item($b, 'Tea', 30);
        $theirOrder = $this->as($b, fn () => app(OrderService::class)->place([
            'type' => 'takeaway', 'source' => 'pos', 'items' => [['item_id' => $theirItem->id, 'qty' => 1]],
        ], MenuCatalog::defaultBranch(), null, $this->owner($b)));

        $this->manual([['code' => $code]], 100)->assertStatus(422)->assertJsonFragment(['message' => 'مفيش فاوتشر بالكود ده.']);
        $this->actingAs($this->cashier)->postJson($this->dashUrl("/vouchers/redeem/orders/{$theirOrder->id}/pay"), ['method' => 'cash', 'vouchers' => [['code' => $mine->code]]])->assertNotFound();
        $other = $this->owner($b);
        $this->actingAs($other)->get($this->dashUrl("/vouchers/redeem/batches/$batch/receipt"))->assertNotFound();
        $this->actingAs($other)->postJson($this->dashUrl("/vouchers/redeem/batches/$batch/cancel"), ['reason' => 'Trying it'])->assertStatus(422);
        $this->assertSame(500.0, $this->as($b, fn () => CorporateVoucher::query()->first()->balance));
        $this->assertSame(1, $this->as($this->tenant, fn () => CorporateVoucherRedemption::query()->whereNull('refunded_at')->count()));
    }
}
