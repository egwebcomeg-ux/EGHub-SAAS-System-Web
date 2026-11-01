<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Shift;
use App\Notifications\ScheduledTaskFailed;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;
use Tests\TenantTestCase;

/** Money and status changes leave a trace, a paid period needs an end, the guest list and the shifts stay with who may see them. */
class AdminTraceAndGuardsTest extends TenantTestCase
{
    private function admin(): PlatformAdmin
    {
        return PlatformAdmin::query()->create(['name' => 'Owner', 'email' => 'owner@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'owner']);
    }

    public function test_a_subscription_change_is_logged_and_a_paid_active_period_needs_an_end(): void
    {
        $this->restaurant('cafe-a');
        $plan = Plan::query()->where('code', 'full')->firstOrFail();
        $admin = $this->admin();
        $payload = ['plan_id' => $plan->id, 'status' => 'active', 'cycle_months' => 1];

        $this->actingAs($admin, 'admin')->post($this->adminUrl('/tenants/cafe-a/subscription'), $payload)->assertSessionHasErrors('current_period_end');
        $this->assertSame(0, PlatformAuditLog::query()->where('action', 'tenant.subscription_changed')->count());

        $this->post($this->adminUrl('/tenants/cafe-a/subscription'), $payload + ['current_period_end' => now()->addMonth()->toDateString()])->assertSessionHasNoErrors();
        $log = PlatformAuditLog::query()->where('action', 'tenant.subscription_changed')->sole();
        $this->assertSame([$admin->id, 'cafe-a', 'full'], [$log->admin_id, $log->subject, $log->meta['plan']]);
    }

    public function test_credits_and_suspending_are_logged(): void
    {
        $this->restaurant('cafe-a');
        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl('/tenants/cafe-a/credits'), ['delta' => 50, 'note' => 'gift'])->assertSessionHasNoErrors();
        $this->post($this->adminUrl('/tenants/cafe-a/suspend'))->assertRedirect();

        $this->assertSame(50, PlatformAuditLog::query()->where('action', 'tenant.credits_adjusted')->sole()->meta['delta']);
        $this->assertSame(1, PlatformAuditLog::query()->where('action', 'tenant.suspended')->count());
    }

    public function test_an_invoice_is_paid_only_at_its_total_and_voiding_is_logged(): void
    {
        $tenant = $this->restaurant('cafe-a', 'pro', trial: true);
        $billing = app(Billing::class);
        $plan = Plan::query()->where('code', 'pro')->firstOrFail();
        $invoice = $billing->invoiceForPlan($tenant, $plan, 1);
        $this->actingAs($this->admin(), 'admin');

        $this->post($this->adminUrl("/invoices/{$invoice->id}/paid"), ['gateway' => 'manual', 'amount' => 0])->assertSessionHasErrors('amount');
        $this->assertSame('open', $invoice->refresh()->status);

        $this->post($this->adminUrl("/invoices/{$invoice->id}/paid"), ['gateway' => 'manual', 'amount' => $invoice->total])->assertSessionHasNoErrors();
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame(1, PlatformAuditLog::query()->where('action', 'invoice.marked_paid')->count());

        $next = $billing->invoiceForPlan($tenant, $plan, 1);
        $this->post($this->adminUrl("/invoices/{$next->id}/void"))->assertRedirect();
        $this->assertSame(1, PlatformAuditLog::query()->where('action', 'invoice.voided')->count());
    }

    public function test_changing_where_restaurants_pay_is_logged_with_the_new_value(): void
    {
        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl('/settings'), ['pay_vodafone_enabled' => '1', 'pay_vodafone_number' => '01000000000'])->assertSessionHasNoErrors();

        $this->assertSame('01000000000', PlatformAuditLog::query()->where('action', 'settings.updated')->sole()->meta['changed']['pay_vodafone_number']);
    }

    public function test_a_cashier_cannot_export_the_guest_list_and_an_owners_export_is_logged(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $owner = $this->owner($tenant);
        $cashier = $this->staff($tenant, 'cashier', 'cashier@cafe.test');

        $this->actingAs($cashier)->get($this->dashUrl('/customers/export'))->assertForbidden();
        $this->get($this->dashUrl('/customers'))->assertOk()->assertDontSee('data-customers-export', false);

        $this->actingAs($owner)->get($this->dashUrl('/customers/export'))->assertOk();
        $this->as($tenant, fn () => $this->assertDatabaseHas('audit_logs', ['tenant_id' => 'cafe-a', 'action' => 'customers.exported']));
    }

    public function test_a_branch_manager_sees_only_their_branchs_shifts(): void
    {
        $tenant = $this->restaurant('cafe-a', 'enterprise');
        $this->as($tenant, function () use ($tenant) {
            $mine = Branch::query()->firstOrFail();
            $other = Branch::query()->create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]);
            $user = $this->owner($tenant);
            $shift = ['user_id' => $user->id, 'opening_cash' => 0, 'opened_at' => now()->subHour(), 'closed_at' => now()];
            Shift::query()->create($shift + ['branch_id' => $mine->id, 'closing_cash' => 111.5]);
            Shift::query()->create($shift + ['branch_id' => $other->id, 'closing_cash' => 222.5]);
            $this->branchIds = [$mine->id, $other->id];
        });
        $manager = $this->staff($tenant, 'manager', 'manager@cafe.test');
        $manager->forceFill(['branch_id' => $this->branchIds[0]])->save();

        $this->actingAs($manager)->get($this->dashUrl('/shifts'))->assertOk()
            ->assertViewHas('history', fn ($history) => $history->pluck('shift.branch_id')->all() === [$this->branchIds[0]]);
    }

    public function test_a_failed_scheduled_command_emails_the_team_once(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $event = collect(Schedule::events())->first(fn ($e) => str_contains((string) $e->command, 'egmenu:billing'));

        $event->exitCode = 1;
        $event->finish(app(), 1);
        $event->finish(app(), 1);

        Notification::assertSentToTimes($admin, ScheduledTaskFailed::class, 1);
    }

    /** @var array<int, int> */
    private array $branchIds = [];
}
