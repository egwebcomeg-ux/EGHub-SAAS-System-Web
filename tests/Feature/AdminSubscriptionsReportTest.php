<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\AdminSubscriptionReport;
use Tests\TenantTestCase;

/**
 * «الاشتراكات والإيرادات» (billing.manage): counts by plan and status, the MRR from what was invoiced, who started and
 * stopped paying in a month, the paid invoices per month, what ends in 14 days, and the CSV exports of the
 * subscriptions and the restaurants list.
 */
class AdminSubscriptionsReportTest extends TenantTestCase
{
    private function admin(string $role, string $name = 'Fady Finance'): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name))."-$role@egweb.test", 'password' => 'Secret-pass-123', 'role' => $role,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function subscription(Tenant $tenant, array $attributes): void
    {
        Subscription::query()->where('tenant_id', $tenant->id)->update($attributes);
    }

    private function paidInvoice(Tenant $tenant, string $paidAt, float $total = 399, string $kind = 'subscription'): Invoice
    {
        return Invoice::query()->create([
            'tenant_id' => $tenant->id, 'number' => 'EGM-'.fake()->unique()->numerify('#####'), 'kind' => $kind,
            'plan_id' => $kind === 'subscription' ? Plan::query()->where('code', 'pro')->value('id') : null, 'cycle_months' => 1,
            'lines' => [], 'subtotal' => $total, 'total' => $total, 'status' => 'paid', 'paid_at' => $paidAt,
        ]);
    }

    public function test_the_mrr_counts_only_paying_subscriptions_at_what_they_were_invoiced(): void
    {
        $yearly = $this->restaurant('cafe-a');
        $this->subscription($yearly, ['cycle_months' => 12, 'period_value' => 2400]);
        $this->restaurant('cafe-b'); // active by hand, no invoice: the plan's monthly price
        $this->restaurant('cafe-c', trial: true);
        $this->restaurant('cafe-d', plan: 'free');
        $this->subscription($this->restaurant('cafe-e'), ['status' => 'past_due']);

        $report = app(AdminSubscriptionReport::class);
        $this->assertSame(599.0, $report->mrr()); // 2400 / 12 + 399
        $this->assertSame(['paying' => 2, 'trialing' => 1, 'past_due' => 1, 'free' => 1, 'canceled' => 0], $report->totals());
        $pro = collect($report->byPlan())->firstWhere(fn (array $row) => $row['plan']->code === 'pro');
        $this->assertSame(['trialing' => 1, 'active' => 2, 'past_due' => 1, 'canceled' => 0], $pro['counts']);
        $this->assertSame(599.0, $pro['mrr']);

        $this->actingAs($this->admin('finance'), 'admin')->get($this->adminUrl('/subscriptions'))->assertOk()
            ->assertSee('data-nav="admin.subscriptions"', false)->assertSee('data-plan-row="pro"', false)->assertSee(money(599));
    }

    public function test_the_report_is_kept_for_the_billing_admins(): void
    {
        $support = $this->admin('support', 'Soha Support');
        $this->actingAs($support, 'admin')->get($this->adminUrl('/subscriptions'))->assertForbidden();
        $this->actingAs($support, 'admin')->get($this->adminUrl('/subscriptions/export.csv'))->assertForbidden();
        $this->actingAs($support, 'admin')->get($this->adminUrl('/'))->assertOk()
            ->assertDontSee('data-nav="admin.subscriptions"', false);
    }

    public function test_the_month_lists_who_started_paying_and_who_stopped(): void
    {
        $this->travelTo(local_at('2026-10-15 12:00'));
        $free = Plan::query()->where('code', 'free')->value('id');

        $first = $this->restaurant('first-pay');
        $this->paidInvoice($first, '2026-10-05 10:00');
        $renewed = $this->restaurant('renewed');
        $this->paidInvoice($renewed, '2026-09-05 10:00');
        $this->paidInvoice($renewed, '2026-10-05 10:00');

        $lapsed = $this->restaurant('lapsed');
        $this->paidInvoice($lapsed, '2026-08-01 10:00');
        $this->subscription($lapsed, ['status' => 'past_due', 'current_period_end' => '2026-10-03 10:00:00']);
        $dropped = $this->restaurant('dropped');
        $this->paidInvoice($dropped, '2026-07-01 10:00');
        $this->subscription($dropped, ['plan_id' => $free, 'current_period_start' => '2026-10-10 10:00:00', 'current_period_end' => null]);
        // a trial that ended on Free never paid: not churn
        $trial = $this->restaurant('trial-over', trial: true);
        $this->subscription($trial, ['status' => 'active', 'plan_id' => $free, 'current_period_start' => '2026-10-04 10:00:00', 'current_period_end' => null]);
        // paid access that ended last month is last month's churn
        $older = $this->restaurant('older-lapse');
        $this->paidInvoice($older, '2026-08-01 10:00');
        $this->subscription($older, ['status' => 'past_due', 'current_period_end' => '2026-09-20 10:00:00']);

        $movement = app(AdminSubscriptionReport::class)->movement('2026-10');
        $this->assertSame(['first-pay'], $movement['new']->pluck('id')->all());
        $this->assertSame(['dropped', 'lapsed'], $movement['churned']->pluck('id')->sort()->values()->all());

        $page = $this->actingAs($this->admin('finance'), 'admin')->get($this->adminUrl('/subscriptions?month=2026-10'))->assertOk();
        $this->assertMatchesRegularExpression('/data-movement-list="new".*Restaurant first-pay.*data-movement-list="churned"/s', $page->getContent());
        $this->assertSame(['older-lapse'], app(AdminSubscriptionReport::class)->movement('2026-09')['churned']->pluck('id')->all());
    }

    public function test_what_ends_in_the_next_14_days_and_the_revenue_per_month(): void
    {
        $this->subscription($this->restaurant('trial-soon', trial: true), ['trial_ends_at' => now()->addDays(3)]);
        $this->subscription($this->restaurant('renew-soon'), ['current_period_end' => now()->addDays(10)]);
        $this->subscription($this->restaurant('renew-later'), ['current_period_end' => now()->addDays(20)]);
        $paying = Tenant::query()->findOrFail('renew-soon');
        $this->paidInvoice($paying, now()->toDateTimeString(), 500);
        $this->paidInvoice($paying, now()->subMonthsNoOverflow(2)->toDateTimeString(), 120, 'addon');
        $this->paidInvoice($paying, now()->subMonthsNoOverflow(13)->toDateTimeString(), 999); // older than the 12 months

        $report = app(AdminSubscriptionReport::class);
        $this->assertSame(['trial-soon', 'renew-soon'], $report->expiring()->pluck('tenant_id')->all());
        $revenue = $report->revenueByMonth();
        $this->assertCount(12, $revenue);
        $this->assertSame(500.0, $revenue[11]['value']);
        $this->assertSame(120.0, $revenue[9]['value']);
        $this->assertSame(620.0, array_sum(array_column($revenue, 'value')));

        $this->actingAs($this->admin('finance'), 'admin')->get($this->adminUrl('/subscriptions'))->assertOk()
            ->assertSee('data-expiring-row="trial-soon"', false)->assertDontSee('data-expiring-row="renew-later"', false)
            ->assertSee('data-revenue-month="'.local_at()->format('Y-m').'"', false);
    }

    public function test_the_subscriptions_export_keeps_the_filters_and_opens_in_arabic(): void
    {
        $trial = $this->restaurant('cafe-t', trial: true);
        $trial->update(['name' => 'كافيه التجربة']);
        $this->restaurant('cafe-a');

        $csv = $this->actingAs($this->admin('finance'), 'admin')->get($this->adminUrl('/subscriptions/export.csv?status=trialing'))->assertOk()->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('كافيه التجربة', $csv);
        $this->assertStringNotContainsString('cafe-a', $csv);
    }

    public function test_the_restaurants_export_keeps_the_search_and_needs_the_restaurants_right(): void
    {
        $this->restaurant('cafe-a')->update(['name' => 'مطعم الأصيل']);
        $this->restaurant('other-b');

        $csv = $this->actingAs($this->admin('support', 'Soha Support'), 'admin')->get($this->adminUrl('/tenants/export.csv?q=cafe'))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('مطعم الأصيل', $csv);
        $this->assertStringNotContainsString('other-b', $csv);

        $this->actingAs($this->admin('content', 'Nour Content'), 'admin')->get($this->adminUrl('/tenants/export.csv'))->assertForbidden();
    }
}
