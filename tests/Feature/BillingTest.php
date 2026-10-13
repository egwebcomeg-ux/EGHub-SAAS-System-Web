<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Billing\Lifecycle;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Tests\TenantTestCase;

class BillingTest extends TenantTestCase
{
    private function plan(string $code): Plan
    {
        return Plan::query()->where('code', $code)->firstOrFail();
    }

    private function admin(): PlatformAdmin
    {
        return PlatformAdmin::query()->firstOrCreate(['email' => 'admin@egmenu.test'], ['name' => 'Admin', 'password' => 'Secret-pass-123']);
    }

    public function test_quote_applies_cycle_discount(): void
    {
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $q = app(Billing::class)->quote($a, $this->plan('pro'), 12);
        $this->assertSame(4788.0, $q['subtotal']);                 // 399 × 12
        $this->assertSame(3974.0, $q['total']);                    // 17% yearly discount, whole pounds
    }

    public function test_owner_picks_a_plan_claims_a_transfer_and_admin_confirms(): void
    {
        PlatformSetting::put('pay_instapay_enabled', '1');
        PlatformSetting::put('pay_instapay_handle', 'egweb@instapay');
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl('/billing/checkout'), ['plan_id' => $this->plan('basic')->id, 'months' => 6])
            ->assertRedirect();
        $invoice = Invoice::query()->where('tenant_id', 'cafe-a')->firstOrFail();
        $this->assertSame('open', $invoice->status);
        $this->assertSame(1075.0, $invoice->total);                  // 199 × 6 − 10%, rounded

        $this->actingAs($owner)->get($this->dashUrl("/billing/invoices/{$invoice->number}"))->assertOk()->assertSee('egweb@instapay');
        $this->actingAs($owner)->post($this->dashUrl("/billing/invoices/{$invoice->number}/claim"), ['method' => 'instapay', 'reference' => 'TX-123'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('/invoices'))->assertOk()->assertSee('TX-123');
        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl("/invoices/{$invoice->id}/paid"), [
            'gateway' => 'instapay', 'reference' => 'TX-123', 'amount' => $invoice->total,
        ])->assertSessionHasNoErrors();

        $sub = Subscription::query()->where('tenant_id', 'cafe-a')->first();
        $this->assertSame('basic', $sub->plan->code);
        $this->assertSame('active', $sub->status);
        $this->assertSame(6, $sub->cycle_months);
        $this->assertTrue($sub->current_period_end->between(now()->addMonths(6)->subDay(), now()->addMonths(6)->addDay()));
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, $invoice->payments()->where('status', 'succeeded')->count());
    }

    public function test_paying_twice_applies_once(): void
    {
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $billing = app(Billing::class);
        $invoice = $billing->invoiceForPlan($a, $this->plan('pro'), 1);

        $this->assertTrue($billing->markPaid($invoice, 'paymob', 'tx1', $invoice->total, 'paymob:tx1'));
        $end = Subscription::query()->where('tenant_id', 'cafe-a')->value('current_period_end');
        $this->assertFalse($billing->markPaid($invoice, 'paymob', 'tx1', $invoice->total, 'paymob:tx1'));
        $this->assertFalse($billing->markPaid($invoice->fresh(), 'manual', null, $invoice->total, 'admin:'.$invoice->id));
        $this->assertSame((string) $end, (string) Subscription::query()->where('tenant_id', 'cafe-a')->value('current_period_end'));
    }

    public function test_renewal_extends_from_the_end_and_upgrade_credits_the_rest(): void
    {
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $billing = app(Billing::class);
        $first = $billing->invoiceForPlan($a, $this->plan('basic'), 1);
        $billing->markPaid($first, 'manual', null, $first->total, 'k1');
        $end = Subscription::query()->where('tenant_id', 'cafe-a')->first()->current_period_end;

        $renew = $billing->invoiceForPlan($a->fresh(), $this->plan('basic'), 1);
        $this->assertTrue($renew->meta['renewal']);
        $billing->markPaid($renew, 'manual', null, $renew->total, 'k2');
        $this->assertSame($end->copy()->addMonthNoOverflow()->toDateString(),
            Subscription::query()->where('tenant_id', 'cafe-a')->first()->current_period_end->toDateString());

        // upgrade mid-period: the unused basic value is credited
        $quote = $billing->quote($a->fresh(), $this->plan('business'), 1);
        $this->assertFalse($quote['renewal']);
        $this->assertGreaterThan(0, $quote['discount']);
        $this->assertLessThan(799, $quote['total']);
    }

    public function test_lifecycle_trial_end_grace_then_suspension_and_payment_restores(): void
    {
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $this->item($a, 'Tea');
        $lifecycle = app(Lifecycle::class);

        $this->travel(15)->days();
        $this->assertSame(1, $lifecycle->run()['past_due']);
        $this->assertSame('past_due', $a->subscription()->first()->status);
        $this->get($this->menuUrl('cafe-a'))->assertOk(); // still online during grace

        $this->travel(6)->days();
        $this->assertSame(1, $lifecycle->run()['suspended']);
        $this->assertTrue($a->fresh()->isSuspended());
        $this->get($this->menuUrl('cafe-a'))->assertStatus(503);
        $this->actingAs($this->owner($a))->get($this->dashUrl('/billing'))->assertOk(); // can still pay

        $billing = app(Billing::class);
        $invoice = $billing->invoiceForPlan($a->fresh(), $this->plan('pro'), 1);
        $billing->markPaid($invoice, 'manual', null, $invoice->total, 'restore');
        $this->assertFalse($a->fresh()->isSuspended());
        $this->get($this->menuUrl('cafe-a'))->assertOk()->assertSee('Tea');
    }

    public function test_reminder_and_banner_before_the_end(): void
    {
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $this->travel(12)->days();
        $this->assertSame(1, app(Lifecycle::class)->run()['reminded']);
        $this->assertSame(0, app(Lifecycle::class)->run()['reminded']); // only once
        $this->actingAs($this->owner($a))->get($this->dashUrl())->assertSee(__('Choose a plan'));
    }

    public function test_only_the_owner_sees_billing_and_invoices_stay_private(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $invoiceOfB = app(Billing::class)->invoiceForPlan($b, $this->plan('pro'), 1);

        $this->actingAs($this->staff($a, 'manager', 'm@a.test'))->get($this->dashUrl('/billing'))->assertForbidden();
        $this->actingAs($this->owner($a))->get($this->dashUrl("/billing/invoices/{$invoiceOfB->number}"))->assertNotFound();
        $this->actingAs($this->owner($a))->post($this->dashUrl("/billing/invoices/{$invoiceOfB->number}/claim"), ['method' => 'instapay', 'reference' => 'x'])
            ->assertNotFound();
    }

    public function test_signup_creates_a_trial_restaurant_and_logs_the_owner_in(): void
    {
        $this->get($this->menuUrl('register/check?slug=new-cafe'))->assertJsonPath('ok', true);
        $this->get($this->menuUrl('register/check?slug=login'))->assertJsonPath('ok', false);

        $res = $this->post($this->menuUrl('register'), [
            'name' => 'كافيه جديد', 'slug' => 'new-cafe', 'owner_name' => 'Sara', 'email' => 'Sara@New.test',
            'phone' => '01066666666', 'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123', 'terms' => '1',
        ]);
        $res->assertRedirect();
        $this->assertStringStartsWith('http://app.egmenu.test/welcome/', $res->headers->get('Location'));

        $tenant = Tenant::query()->find('new-cafe');
        $this->assertSame('trialing', $tenant->subscription->status);
        $owner = User::query()->where('email', 'sara@new.test')->first();
        $this->assertTrue($owner->hasRole('owner'));

        $this->get($res->headers->get('Location'))->assertRedirect(route('dash.home'));
        $this->assertAuthenticatedAs($owner, 'web');

        // the link works once only
        $this->app['auth']->forgetGuards();
        $this->get($res->headers->get('Location'))->assertNotFound();
    }

    public function test_signup_rejects_taken_slug_and_bad_phone(): void
    {
        $this->restaurant('taken');
        $this->post($this->menuUrl('register'), [
            'name' => 'X', 'slug' => 'taken', 'owner_name' => 'X', 'email' => 'x@x.test', 'phone' => '123',
            'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123', 'terms' => '1',
        ])->assertSessionHasErrors('phone');
        $this->post($this->menuUrl('register'), [
            'name' => 'X', 'slug' => 'taken', 'owner_name' => 'X', 'email' => 'x@x.test', 'phone' => '01077777777',
            'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123', 'terms' => '1',
        ])->assertSessionHasErrors('slug');
        $this->assertNull(Tenant::query()->find('x'));
    }

    public function test_unsigned_welcome_link_is_refused(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->get($this->dashUrl('/welcome/'.$this->owner($a)->id))->assertForbidden();
        $this->get(URL::temporarySignedRoute('dash.welcome', now()->subMinute(), ['user' => $this->owner($a)->id]))->assertForbidden();
    }
}
