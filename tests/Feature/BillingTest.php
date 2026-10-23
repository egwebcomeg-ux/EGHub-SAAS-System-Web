<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Billing\Lifecycle;
use App\Billing\PaymentMethods;
use App\Models\AddonPrice;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Entitlements;
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

        // the older Basic plan is hidden: only the public line-up (or the plan you are on) can be picked
        $this->actingAs($owner)->post($this->dashUrl('/billing/checkout'), ['plan_id' => $this->plan('basic')->id, 'months' => 6])
            ->assertSessionHasErrors('plan_id');
        $this->actingAs($owner)->post($this->dashUrl('/billing/checkout'), ['plan_id' => $this->plan('full')->id, 'months' => 6])
            ->assertRedirect();
        $invoice = Invoice::query()->where('tenant_id', 'cafe-a')->firstOrFail();
        $this->assertSame('open', $invoice->status);
        $this->assertSame(6750.0, $invoice->total);                  // 1,250 × 6 − 10%

        $this->actingAs($owner)->get($this->dashUrl("/billing/invoices/{$invoice->number}"))->assertOk()->assertSee('egweb@instapay');
        $this->actingAs($owner)->post($this->dashUrl("/billing/invoices/{$invoice->number}/claim"), ['method' => 'instapay', 'reference' => 'TX-123'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('/invoices'))->assertOk()->assertSee('TX-123');
        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl("/invoices/{$invoice->id}/paid"), [
            'gateway' => 'instapay', 'reference' => 'TX-123', 'amount' => $invoice->total,
        ])->assertSessionHasNoErrors();

        $sub = Subscription::query()->where('tenant_id', 'cafe-a')->first();
        $this->assertSame('full', $sub->plan->code);
        $this->assertSame('active', $sub->status);
        $this->assertSame(6, $sub->cycle_months);
        $this->assertTrue($sub->current_period_end->between(now()->addMonths(6)->subDay(), now()->addMonths(6)->addDay()));
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, $invoice->payments()->where('status', 'succeeded')->count());
    }

    public function test_a_manual_method_without_its_account_is_never_offered_nor_switched_on(): void
    {
        PlatformSetting::put('pay_instapay_enabled', '1');
        PlatformSetting::put('pay_instapay_handle', '  ');
        PlatformSetting::put('pay_vodafone_enabled', '1');
        PlatformSetting::put('pay_vodafone_number', '01000000000');
        $this->assertSame(['vodafone_cash'], array_column(PaymentMethods::manual(), 'key'));

        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $invoice = app(Billing::class)->invoiceForPlan($a, $this->plan('pro'), 1);
        $this->actingAs($this->owner($a))->post($this->dashUrl("/billing/invoices/{$invoice->number}/claim"), ['method' => 'instapay', 'reference' => 'TX-1'])
            ->assertSessionHasErrors('method');

        // the admin cannot switch InstaPay on without the address restaurants pay to
        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl('/settings'), ['pay_instapay_enabled' => '1', 'pay_instapay_handle' => ''])
            ->assertSessionHasErrors(['pay_instapay_handle' => __('Write the InstaPay address restaurants pay to before switching InstaPay on.')]);
        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl('/settings'), ['pay_vodafone_enabled' => '1'])
            ->assertSessionHasErrors('pay_vodafone_number');
    }

    public function test_a_trial_subscribes_to_its_plan_and_add_on_names_use_latin_digits(): void
    {
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        AddonPrice::query()->where('type', 'credits')->first()?->update(['name' => '٥٠٠ رسالة واتساب']);

        $this->actingAs($this->owner($a))->get($this->dashUrl('/billing'))->assertOk()
            ->assertSee('>اشترك</button>', false)->assertDontSee('>تجديد</button>', false)
            ->assertDontSee('٥٠٠');

        // a paid plan renews
        Subscription::query()->where('tenant_id', 'cafe-a')->update(['status' => 'active', 'current_period_end' => now()->addMonth()]);
        Entitlements::flush();
        $this->actingAs($this->owner($a))->get($this->dashUrl('/billing'))->assertOk()->assertSee('>تجديد</button>', false);
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

    public function test_lifecycle_trial_end_moves_to_free_and_payment_restores(): void
    {
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $this->item($a, 'Tea');
        $lifecycle = app(Lifecycle::class);

        $this->travel(15)->days();
        $this->assertSame(1, $lifecycle->run()['downgraded']);
        $sub = $a->subscription()->with('plan')->first();
        $this->assertSame(['free', 'active'], [$sub->plan->code, $sub->status]);
        $this->assertNull($sub->endsAt());
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('Tea'); // the menu never goes offline
        $this->assertFalse(Entitlements::for($a->fresh())->allows('qr_ordering'));
        $this->assertSame(0, $lifecycle->run()['downgraded']); // nothing fires again for the free plan

        $billing = app(Billing::class);
        $invoice = $billing->invoiceForPlan($a->fresh(), $this->plan('pro'), 1);
        $billing->markPaid($invoice, 'manual', null, $invoice->total, 'restore');
        $this->assertSame('pro', $a->subscription()->with('plan')->first()->plan->code);
        $this->assertTrue(Entitlements::for($a->fresh())->allows('qr_ordering'));
    }

    public function test_lifecycle_paid_period_end_grace_then_free(): void
    {
        $a = $this->restaurant('cafe-a', 'basic');
        $this->item($a, 'Tea');
        $lifecycle = app(Lifecycle::class);

        $this->travel(32)->days();
        $this->assertSame(1, $lifecycle->run()['past_due']);
        $this->assertSame('past_due', $a->subscription()->first()->status);
        $this->assertTrue(Entitlements::for($a->fresh())->allows('qr_ordering')); // grace: everything still works

        $this->travel(6)->days();
        $this->assertSame(1, $lifecycle->run()['downgraded']);
        $this->assertSame('free', $a->subscription()->with('plan')->first()->plan->code);
        $this->assertFalse($a->fresh()->isSuspended());
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('Tea');
        $this->actingAs($this->owner($a))->get($this->dashUrl('/billing'))->assertOk();
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

        // first stop after signup: "what will you use?" (ModulePickerTest)
        $this->get($res->headers->get('Location'))->assertRedirect(route('dash.modules', ['welcome' => 1]));
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
