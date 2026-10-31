<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Billing\Lifecycle;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\SubscriptionChange;
use App\Models\Tenant;
use App\Services\AdminSubscriptionReport;
use Tests\TenantTestCase;

/**
 * Every subscription status or plan change is recorded with its cause (sign-up, payment, the lifecycle, an admin),
 * and the admin report reads «started / stopped paying» from that history for the months it covers, falling back to
 * the invoices for the months before it.
 */
class SubscriptionHistoryTest extends TenantTestCase
{
    private function admin(string $role = 'finance'): PlatformAdmin
    {
        return PlatformAdmin::query()->create(['name' => 'Fady', 'email' => "fady-$role@egweb.test", 'password' => 'Secret-pass-123', 'role' => $role]);
    }

    private function plan(string $code): Plan
    {
        return Plan::query()->where('code', $code)->firstOrFail();
    }

    private function pay(Tenant $tenant, string $plan): void
    {
        $billing = app(Billing::class);
        $invoice = $billing->invoiceForPlan($tenant->fresh(), $this->plan($plan), 1);
        $billing->markPaid($invoice, 'manual', null, $invoice->total, 'pay:'.$invoice->id);
    }

    /** @return list<array{0: ?string, 1: string, 2: ?string, 3: ?string, 4: string}> [from status, to status, from plan, to plan, cause] */
    private function history(Tenant $tenant): array
    {
        $codes = Plan::query()->pluck('code', 'id');

        return SubscriptionChange::query()->where('tenant_id', $tenant->id)->orderBy('id')->get()
            ->map(fn (SubscriptionChange $c) => [$c->from_status, $c->to_status, $codes[$c->from_plan_id] ?? null, $codes[$c->to_plan_id] ?? null, $c->cause])->all();
    }

    public function test_every_status_and_plan_change_is_recorded_with_its_cause(): void
    {
        $tenant = $this->restaurant('cafe-a', 'pro', trial: true);
        $this->pay($tenant, 'basic');
        $this->pay($tenant, 'basic'); // a renewal: same status and plan, nothing recorded

        $this->travel(63)->days(); // past the two paid months
        app(Lifecycle::class)->run();
        $this->travel(6)->days();
        app(Lifecycle::class)->run();

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/tenants/cafe-a/subscription'), [
            'plan_id' => $this->plan('pro')->id, 'status' => 'active', 'cycle_months' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame([
            [null, 'trialing', null, 'pro', 'signup'],
            ['trialing', 'active', 'pro', 'basic', 'payment'],
            ['active', 'past_due', 'basic', 'basic', 'period_ended'],
            ['past_due', 'active', 'basic', 'free', 'grace_ended'],
            ['active', 'active', 'free', 'pro', 'admin'],
        ], $this->history($tenant));
        $this->assertSame($admin->id, SubscriptionChange::query()->where('cause', 'admin')->sole()->admin_id);

        $trial = $this->restaurant('cafe-b', 'pro', trial: true);
        $this->travel(15)->days();
        app(Lifecycle::class)->run();
        $this->assertSame(['trialing', 'active', 'pro', 'free', 'trial_ended'], $this->history($trial)[1]);
    }

    public function test_the_report_reads_the_history_for_the_months_it_covers(): void
    {
        $this->travelTo(local_at('2026-09-01 10:00'));
        $starts = $this->restaurant('starts', 'pro', trial: true);
        $byHand = $this->restaurant('by-hand', 'pro', trial: true);
        $this->restaurant('stops', 'basic');

        // 2 October: the trials end on Free, the basic month ends unpaid
        $this->travelTo(local_at('2026-10-02 10:00'));
        app(Lifecycle::class)->run();
        $this->travelTo(local_at('2026-10-05 10:00'));
        $this->pay($starts, 'pro');
        // set active on a paid plan by the admin, no invoice: paying in the history, invisible to the invoices
        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl("/tenants/{$byHand->id}/subscription"), [
            'plan_id' => $this->plan('pro')->id, 'status' => 'active', 'cycle_months' => 1,
        ])->assertSessionHasNoErrors();

        $movement = app(AdminSubscriptionReport::class)->movement('2026-10');
        $this->assertSame('history', $movement['source']);
        $this->assertSame(['by-hand', 'starts'], $movement['new']->pluck('id')->sort()->values()->all());
        $this->assertSame(['stops'], $movement['churned']->pluck('id')->all());

        $this->get($this->adminUrl('/subscriptions?month=2026-10'))->assertOk()->assertSee('data-movement-source="history"', false);

        // August is before the history began: the invoices' estimate, said on the page
        $this->assertSame('invoices', app(AdminSubscriptionReport::class)->movement('2026-08')['source']);
        $this->get($this->adminUrl('/subscriptions?month=2026-08'))->assertOk()->assertSee('data-movement-source="invoices"', false)
            ->assertSee(__('Estimated from the invoices: subscription changes are recorded only since :date.', ['date' => '2026-09-01']));
    }
}
