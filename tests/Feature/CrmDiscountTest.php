<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\TenantDiscount;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/** A discount given on a sales call: within the caller's limit, applied to the next subscription invoice(s), expiring. */
class CrmDiscountTest extends TenantTestCase
{
    private function admin(string $role, ?int $max = null): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'name' => ucfirst($role), 'email' => "$role@egweb.test", 'password' => 'Secret-pass-123', 'role' => $role, 'crm_discount_max' => $max,
        ]);
    }

    private function full(): Plan
    {
        return Plan::query()->where('code', 'full')->firstOrFail();
    }

    private function give(PlatformAdmin $admin, Tenant $tenant, array $data = []): TestResponse
    {
        return $this->actingAs($admin, 'admin')->post($this->adminUrl('/crm/leads/'.Lead::for($tenant)->id.'/discount'), $data + [
            'percent' => 10, 'invoices' => 1, 'expires_at' => local_at()->addDays(7)->toDateString(), 'note' => 'Subscribes this week',
        ]);
    }

    public function test_a_discount_comes_off_the_next_invoice_and_is_used_up_by_paying_it(): void
    {
        $tenant = $this->restaurant('cafe-a', 'full', true);
        $this->give($this->admin('sales'), $tenant)->assertRedirect()->assertSessionHasNoErrors();

        // the restaurant is told on its subscription page
        $this->actingAs($this->owner($tenant), 'web')->get($this->dashUrl('/billing'))->assertOk()->assertSee('data-team-discount', false);

        $billing = app(Billing::class);
        $invoice = $billing->invoiceForPlan($tenant, $this->full(), 1);
        $price = $this->full()->priceFor(1);
        $this->assertEqualsWithDelta(round($price * 0.9), $invoice->total, 0.01);
        // the discount is a line of the invoice, so the restaurant sees it
        $this->assertSame(__('EgHub team discount :pct%', ['pct' => '10']), collect($invoice->lines)->last()['label']);
        $this->assertSame(-round($price * 0.1), (float) collect($invoice->lines)->last()['amount']);
        $this->assertSame(TenantDiscount::query()->value('id'), $invoice->meta['tenant_discount_id']);

        $billing->markPaid($invoice, 'manual', null, $invoice->total, 'admin:'.$invoice->id);
        $this->assertSame(1, TenantDiscount::query()->first()->invoices_used);
        $this->assertNull(TenantDiscount::usableFor($tenant));

        $renewal = $billing->invoiceForPlan($tenant->fresh(), $this->full(), 1);
        $this->assertEqualsWithDelta($price, $renewal->total, 0.01);
    }

    public function test_an_open_invoice_is_reissued_with_the_discount(): void
    {
        $tenant = $this->restaurant('cafe-a', 'full', true);
        $old = app(Billing::class)->invoiceForPlan($tenant, $this->full(), 12);

        $this->give($this->admin('owner'), $tenant, ['percent' => 20]);

        $this->assertSame('void', $old->fresh()->status);
        $new = Invoice::query()->where('tenant_id', $tenant->id)->where('status', 'open')->firstOrFail();
        $this->assertEqualsWithDelta(round($this->full()->priceFor(12) * 0.8), $new->total, 0.01);
    }

    public function test_an_expired_discount_is_not_applied(): void
    {
        $tenant = $this->restaurant('cafe-a', 'full', true);
        $this->give($this->admin('sales'), $tenant);

        $this->travel(8)->days();

        $invoice = app(Billing::class)->invoiceForPlan($tenant, $this->full(), 1);
        $this->assertEqualsWithDelta($this->full()->priceFor(1), $invoice->total, 0.01);
    }

    public function test_a_discount_above_the_callers_limit_is_rejected(): void
    {
        $tenant = $this->restaurant('cafe-a', 'full', true);

        $this->give($this->admin('sales'), $tenant, ['percent' => 11])->assertSessionHasErrors('percent');
        $this->give($this->admin('manager', 5), $tenant, ['percent' => 6])->assertSessionHasErrors('percent');
        $this->give(PlatformAdmin::query()->create(['name' => 'Boss', 'email' => 'boss@egweb.test', 'password' => 'Secret-pass-123']), $tenant, ['percent' => TenantDiscount::CAP + 1])
            ->assertSessionHasErrors('percent');

        $this->assertSame(0, TenantDiscount::query()->count());
    }

    public function test_crm_manage_without_crm_discount_cannot_give_one(): void
    {
        $tenant = $this->restaurant('cafe-a', 'full', true);

        $this->give($this->admin('support'), $tenant)->assertForbidden();
        $this->assertSame(0, TenantDiscount::query()->count());
    }

    public function test_granting_is_audited_shown_on_the_lead_and_the_restaurant_and_replaces_the_previous_one(): void
    {
        $owner = $this->admin('owner');
        $tenant = $this->restaurant('cafe-a', 'full', true);
        $this->give($owner, $tenant, ['percent' => 10]);
        $this->give($owner, $tenant, ['percent' => 15, 'invoices' => 3]);

        $this->assertSame(1, TenantDiscount::query()->usable()->count());
        $this->assertSame(15.0, TenantDiscount::usableFor($tenant)->percent);
        $this->assertSame(2, PlatformAuditLog::query()->where('action', 'crm.discount_granted')->count());
        $this->actingAs($owner, 'admin')->get($this->adminUrl('/crm/leads/'.Lead::for($tenant)->id))->assertOk()->assertSee('data-active-discount', false);
        $this->actingAs($owner, 'admin')->get($this->adminUrl('/tenants/cafe-a'))->assertOk()->assertSee('data-tenant-discount', false);
    }

    public function test_a_cancelled_discount_stops_applying(): void
    {
        $owner = $this->admin('owner');
        $tenant = $this->restaurant('cafe-a', 'full', true);
        $this->give($owner, $tenant);

        $this->actingAs($owner, 'admin')->post($this->adminUrl('/crm/discounts/'.TenantDiscount::query()->first()->id.'/revoke'))->assertRedirect();

        $this->assertNull(TenantDiscount::usableFor($tenant));
        $this->assertEqualsWithDelta($this->full()->priceFor(1), app(Billing::class)->invoiceForPlan($tenant, $this->full(), 1)->total, 0.01);
    }
}
