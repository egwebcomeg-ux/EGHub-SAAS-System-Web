<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CorporateContract;
use App\Models\CorporateVoucher;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Menu\MenuCatalog;
use App\Services\Orders\OrderService;
use App\Services\Settings;
use Tests\TenantTestCase;

/**
 * The vouchers report: issued, redeemed, outstanding, lost and expired values across both modes, paper bills
 * counted, cancelled orders and cancelled paper bills left out, the filters, exports, who may see it and isolation.
 */
class CorporateReportTest extends TenantTestCase
{
    private Tenant $tenant;

    private User $cashier;

    private User $manager;

    private MenuItem $latte;

    private CorporateContract $eva;

    private CorporateContract $bank;

    private function contract(array $overrides, ?Tenant $tenant = null): CorporateContract
    {
        $tenant ??= $this->tenant;

        return $this->as($tenant, fn () => app(CorporateVouchers::class)->createContract($overrides + [
            'voucher_value' => 200, 'quantity' => 3, 'expires_on' => now()->addMonth()->format('Y-m-d'), 'mode' => 'single',
        ], $this->owner($tenant)));
    }

    /** @return list<CorporateVoucher> */
    private function vouchers(CorporateContract $contract): array
    {
        return $this->as($this->tenant, fn () => CorporateVoucher::query()->where('corporate_contract_id', $contract->id)->orderBy('id')->get()->all());
    }

    private function manual(string $code, float $amount, ?User $by = null, ?string $approval = null): string
    {
        return $this->actingAs($by ?? $this->cashier)->postJson($this->dashUrl('/vouchers/redeem/manual'), [
            'amount' => $amount, 'vouchers' => [['code' => $code, 'approval' => $approval]],
        ])->assertOk()->json('batch');
    }

    /**
     * Eva (single, 3 × 200), Nile Bank (balance, 2 × 100, ends in 20 days), Old Co (balance, 1 × 50, ended 2 days
     * ago, never used). Uses: Eva-1 on a 150 bill (50 lost), Bank-1 60, Eva-3 expired 200 with the manager's
     * exception; given back: Eva-2 on an order that was cancelled, Bank-2 on a paper bill a manager cancelled.
     */
    private function scenario(): void
    {
        $this->tenant = $this->restaurant('cafe-r', 'full');
        // these sales go through the kitchen (they are moved and cancelled below), not done once paid (OrderFlow)
        $this->as($this->tenant, fn () => Settings::put(['order_flow' => 'kitchen']));
        $this->latte = $this->item($this->tenant, 'Latte', 60);
        $this->cashier = $this->staff($this->tenant, 'cashier', 'cashier@cafe-r.test');
        $this->manager = $this->staff($this->tenant, 'manager', 'manager@cafe-r.test');
        $this->eva = $this->contract(['name' => 'Eva Pharma']);
        $this->bank = $this->contract(['name' => 'Nile Bank', 'prefix' => 'NB', 'voucher_value' => 100, 'quantity' => 2, 'mode' => 'balance', 'expires_on' => now()->addDays(20)->format('Y-m-d')]);
        $old = $this->contract(['name' => 'Old Co', 'prefix' => 'OLD', 'voucher_value' => 50, 'quantity' => 1, 'mode' => 'balance']);
        $this->as($this->tenant, fn () => CorporateVoucher::query()->where('corporate_contract_id', $old->id)->update(['expires_on' => now()->subDays(2)->format('Y-m-d')]));

        [$eva1, $eva2, $eva3] = $this->vouchers($this->eva);
        [$bank1, $bank2] = $this->vouchers($this->bank);
        $this->manual($eva1->code, 150);
        $this->manual($bank1->code, 60);

        // given back: an order paid by a voucher then cancelled, and a paper bill a manager cancelled
        $order = $this->as($this->tenant, fn () => app(OrderService::class)->place([
            'type' => 'takeaway', 'source' => 'pos', 'items' => [['item_id' => $this->latte->id, 'qty' => 2]],
        ], MenuCatalog::defaultBranch(), null, $this->owner($this->tenant)));
        $this->actingAs($this->cashier)->postJson($this->dashUrl("/vouchers/redeem/orders/{$order->id}/pay"), ['method' => 'corporate', 'vouchers' => [['code' => $eva2->code]]])->assertOk();
        $this->as($this->tenant, fn () => app(OrderService::class)->transition(Order::query()->find($order->id), 'cancelled', $this->manager));
        $batch = $this->manual($bank2->code, 30);
        $this->actingAs($this->manager)->postJson($this->dashUrl("/vouchers/redeem/batches/$batch/cancel"), ['reason' => 'Typed by mistake'])->assertOk();

        // an expired voucher taken with the manager's exception
        $this->as($this->tenant, fn () => CorporateVoucher::query()->whereKey($eva3->id)->update(['expires_on' => now()->subDay()->format('Y-m-d')]));
        $token = $this->actingAs($this->manager)->postJson($this->dashUrl("/vouchers/redeem/{$eva3->id}/exception"), ['reason' => 'Staff came on the last day'])->assertOk()->json('token');
        $this->manual($eva3->code, 200, $this->manager, $token);
    }

    private function report(array $query = []): array
    {
        return $this->actingAs($this->manager)->get($this->dashUrl('/corporate/report?'.http_build_query($query + [
            'range' => 'custom', 'from' => now()->subDays(10)->format('Y-m-d'), 'to' => now()->format('Y-m-d'),
        ])))->assertOk()->viewData('r');
    }

    public function test_the_figures_across_modes_with_paper_bills_and_without_what_was_given_back(): void
    {
        $this->scenario();
        $r = $this->report();
        $k = $r['kpis'];

        $this->assertSame([850.0, 6], [$k['issued'], $k['issued_count']]);
        $this->assertSame([410.0, 3, 410.0], [$k['redeemed'], $k['uses'], $k['paper']]);
        $this->assertSame(50.0, $k['lost']);
        // Eva-2 (200, given back), Bank-1 (40), Bank-2 (100, given back): Old Co has ended
        $this->assertSame([340.0, 3], [$k['outstanding'], $k['outstanding_count']]);
        $this->assertSame([50.0, 1], [$k['expired'], $k['expired_count']]);
        $this->assertSame([3, 3], [$k['used_vouchers'], $k['unused_vouchers']]);
        $this->assertSame([1, 200.0], [$k['exceptions'], $k['exceptions_value']]);

        $eva = collect($r['contracts'])->firstWhere('contract.id', $this->eva->id);
        $this->assertSame([3, 600.0, 350.0, 50.0, 200.0, 0.0, 67], [$eva['vouchers'], $eva['issued'], $eva['redeemed'], $eva['lost'], $eva['remaining'], $eva['expired'], $eva['usage']]);
        $this->assertSame(410.0, array_sum($r['days']));
        $this->assertSame(['Nile Bank'], array_map(fn ($e) => $e['contract']->name, $r['expiring']));
        $this->assertSame(140.0, $r['expiring'][0]['value']);
        $this->assertSame('Staff came on the last day', $r['exceptions'][0]['reason']);
        $this->assertSame([['Cashier', 210.0], ['Manager', 200.0]], array_map(fn ($c) => [$c['name'], $c['amount']], $r['cashiers']));

        $this->actingAs($this->manager)->get($this->dashUrl('/corporate/report'))->assertOk()->assertSee('تقرير الفاوتشرات')
            ->assertSee('data-report-contract="'.$this->eva->id.'"', false)->assertSee('data-chart', false)->assertSee('Staff came on the last day');
    }

    public function test_the_filters(): void
    {
        $this->scenario();
        $this->assertSame(60.0, $this->report(['mode' => 'balance'])['kpis']['redeemed']);
        $this->assertSame(350.0, $this->report(['contract' => $this->eva->id])['kpis']['redeemed']);
        $this->assertSame(200.0, $this->report(['cashier' => $this->manager->id])['kpis']['redeemed']);
        $other = $this->as($this->tenant, fn () => Branch::query()->create(['name' => 'Second', 'slug' => 'second', 'is_active' => true]));
        $this->assertSame(0.0, $this->report(['branch' => $other->id])['kpis']['redeemed']);
        // a range before any use
        $empty = $this->actingAs($this->manager)->get($this->dashUrl('/corporate/report?range=custom&from='.now()->subDays(40)->format('Y-m-d').'&to='.now()->subDays(30)->format('Y-m-d')))
            ->assertOk()->assertSee('data-chart-empty', false);
        $this->assertSame(0.0, $empty->viewData('r')['kpis']['redeemed']);
        $this->assertSame(410.0, $this->actingAs($this->manager)->get($this->dashUrl('/corporate/report?range=today'))->viewData('r')['kpis']['redeemed']);
    }

    public function test_exports_and_who_may_see_it(): void
    {
        $this->scenario();
        $csv = $this->actingAs($this->manager)->get($this->dashUrl('/corporate/report/export?what=summary&format=csv&range=today'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Eva Pharma', $csv);
        $this->assertStringContainsString('410', $csv);
        $uses = $this->actingAs($this->manager)->get($this->dashUrl('/corporate/report/export?what=uses&format=csv&range=today'))->assertOk()->streamedContent();
        $this->assertSame(4, count(array_filter(explode("\n", trim($uses))))); // header + 3 uses, nothing given back
        $this->assertStringContainsString('فاتورة ورقية', $uses);
        $this->actingAs($this->manager)->get($this->dashUrl('/corporate/report/export?what=uses&format=xlsx'))->assertOk()->assertDownload();

        // contracts.manage or reports.view; the cashier has neither; the links are on the contracts list and the reports
        $this->actingAs($this->cashier)->get($this->dashUrl('/corporate/report'))->assertForbidden();
        $this->actingAs($this->owner($this->tenant))->get($this->dashUrl('/corporate'))->assertOk()->assertSee('data-vouchers-report-link', false);
        $this->actingAs($this->owner($this->tenant))->get($this->dashUrl('/reports'))->assertOk()->assertSee('data-report-vouchers-link', false);
        $pro = $this->restaurant('cafe-pro', 'pro');
        $this->actingAs($this->owner($pro))->get($this->dashUrl('/corporate/report'))->assertForbidden();
    }

    public function test_another_restaurants_vouchers_never_reach_the_report(): void
    {
        $this->scenario();
        $b = $this->restaurant('cafe-s', 'full');
        // these sales go through the kitchen (they are moved and cancelled below), not done once paid (OrderFlow)
        $this->as($b, fn () => Settings::put(['order_flow' => 'kitchen']));
        $theirs = $this->contract(['name' => 'Their Co', 'voucher_value' => 900, 'quantity' => 2], $b);
        $code = $this->as($b, fn () => CorporateVoucher::query()->where('corporate_contract_id', $theirs->id)->value('code'));
        $this->actingAs($this->owner($b))->postJson($this->dashUrl('/vouchers/redeem/manual'), ['amount' => 900, 'vouchers' => [['code' => $code]]])->assertOk();

        $r = $this->report();
        $this->assertSame([850.0, 410.0], [$r['kpis']['issued'], $r['kpis']['redeemed']]);
        $this->assertNull(collect($r['contracts'])->firstWhere('contract.name', 'Their Co'));
        $this->actingAs($this->manager)->get($this->dashUrl('/corporate/report?contract='.$theirs->id))->assertOk()->assertDontSee('Their Co');
    }
}
