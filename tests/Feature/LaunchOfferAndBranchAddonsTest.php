<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Billing\LaunchOffer;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Entitlements;
use Tests\TenantTestCase;

/** Extra branches and users on the Complete plan, the yearly discount on add-ons, and the "second branch free" launch offer. */
class LaunchOfferAndBranchAddonsTest extends TenantTestCase
{
    /** Subscribes $tenant to Complete for $months through a paid invoice, as the dashboard does. */
    private function subscribeToComplete(Tenant $tenant, int $months = 12): Subscription
    {
        $billing = app(Billing::class);
        $invoice = $billing->invoiceForPlan($tenant, Plan::query()->where('code', 'full')->firstOrFail(), $months);
        $billing->markPaid($invoice, 'instapay', 'ref', $invoice->total, 'pay-'.$invoice->number);

        return $tenant->subscription()->firstOrFail();
    }

    public function test_an_extra_branch_brings_eight_users_and_branch_prices_and_users_come_five_at_a_time(): void
    {
        $tenant = $this->restaurant('cafe-full', 'full');
        $this->assertFalse(Entitlements::for($tenant)->allows('branch_pricing'));

        app(Billing::class)->setAddon($tenant, 'branches', 2);
        app(Billing::class)->setAddon($tenant, 'staff', 1);
        $rights = Entitlements::for($tenant->fresh());

        $this->assertSame(3, $rights->limit('branches'));
        $this->assertSame(8 + 2 * 8 + 5, $rights->limit('staff'));
        $this->assertTrue($rights->allows('branch_pricing'));
    }

    public function test_the_yearly_discount_applies_to_add_ons_on_the_plan_invoice(): void
    {
        PlatformSetting::put(LaunchOffer::ENABLED_KEY, '0');
        $tenant = $this->restaurant('cafe-full', 'full');
        app(Billing::class)->setAddon($tenant, 'branches', 1);

        $quote = app(Billing::class)->quote($tenant, Plan::query()->where('code', 'full')->firstOrFail(), 12);

        $this->assertSame(12000.0 + 5760.0, $quote['total']);
        $this->assertSame(5760.0, collect($quote['lines'])->last()['amount']);
    }

    public function test_the_first_extra_branch_is_free_for_twelve_months_then_billed(): void
    {
        $tenant = $this->restaurant('cafe-full', 'free');
        $sub = $this->subscribeToComplete($tenant);
        $this->assertSame(now()->addMonthsNoOverflow(12)->toDateString(), $sub->branch_offer_until->toDateString());

        // buying the first extra branch costs nothing and switches it on at once
        $this->actingAs($this->owner($tenant))->post($this->dashUrl('/billing/addons/branches'))
            ->assertRedirect(route('dash.billing'))->assertSessionHas('ok');
        $this->assertSame(1, (int) $sub->addons()->where('key', 'branches')->value('quantity'));
        $this->assertSame(0.0, (float) Invoice::query()->where('tenant_id', 'cafe-full')->where('kind', 'addon')->value('total'));

        // a second extra branch is billed (prorated to the period end, with the yearly discount)
        $this->actingAs($this->owner($tenant))->post($this->dashUrl('/billing/addons/branches'));
        $second = Invoice::query()->where('tenant_id', 'cafe-full')->where('kind', 'addon')->where('status', 'open')->firstOrFail();
        $this->assertGreaterThan(0, $second->total);

        // after the 12 months the branch is billed normally
        $this->travel(13)->months();
        $after = app(Billing::class)->quote($tenant, Plan::query()->where('code', 'full')->firstOrFail(), 1);
        $this->assertSame(1250.0 + 600.0, $after['total']);
    }

    public function test_subscribing_with_an_extra_branch_already_on_shows_it_free_on_the_invoice(): void
    {
        $tenant = $this->restaurant('cafe-trial', 'full', true);
        app(Billing::class)->setAddon($tenant, 'branches', 1);

        $quote = app(Billing::class)->quote($tenant, Plan::query()->where('code', 'full')->firstOrFail(), 12);

        $this->assertSame(12000.0, $quote['total']);
        $offerLine = collect($quote['lines'])->firstWhere('amount', 0.0);
        $this->assertNotNull($offerLine);
        $this->assertStringContainsString(local_time(now()->addMonthsNoOverflow(12), 'Y/m/d'), $offerLine['label']);
    }

    public function test_switching_the_offer_off_stops_it_for_new_subscribers_only(): void
    {
        $early = $this->restaurant('early', 'free');
        $earlySub = $this->subscribeToComplete($early);

        PlatformSetting::put(LaunchOffer::ENABLED_KEY, '0');
        $late = $this->restaurant('late', 'free');
        $lateSub = $this->subscribeToComplete($late);

        $this->assertNull($lateSub->branch_offer_until);
        $this->assertNotNull($earlySub->fresh()->branch_offer_until);
        $this->get($this->menuUrl('pricing'))->assertOk()->assertDontSee('data-launch-offer', false);

        $this->actingAs($this->owner($late))->post($this->dashUrl('/billing/addons/branches'));
        $this->assertGreaterThan(0, Invoice::query()->where('tenant_id', 'late')->where('kind', 'addon')->value('total'));

        $this->actingAs($this->owner($early))->post($this->dashUrl('/billing/addons/branches'))->assertRedirect(route('dash.billing'));
        $this->assertSame(1, (int) $earlySub->addons()->where('key', 'branches')->value('quantity'));
    }

    public function test_the_free_second_branch_counts_in_the_branch_limit_inside_the_offer_window(): void
    {
        // a Complete trial while the offer runs: the second branch can be added at once, nothing to buy
        $trial = $this->restaurant('cafe-trial', 'full', true);
        $base = Plan::query()->where('code', 'full')->firstOrFail()->entitlements()['limits']['branches'];
        $this->assertSame($base + 1, $this->as($trial, fn () => Entitlements::current()->limit('branches')));
        $this->actingAs($this->owner($trial))->post($this->dashUrl('/branches'), ['name' => 'Zamalek', 'is_active' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $this->as($trial, fn () => Branch::query()->count()));

        // subscribed: kept for the 12 months; claiming it as the first branch add-on does not count it twice
        $paid = $this->restaurant('cafe-paid', 'free');
        $sub = $this->subscribeToComplete($paid);
        $this->assertSame($base + 1, $this->as($paid, fn () => Entitlements::current()->limit('branches')));
        app(Billing::class)->setAddon($paid, 'branches', 1);
        Entitlements::flush();
        $this->assertSame($base + 1, $this->as($paid, fn () => Entitlements::current()->limit('branches')));
        app(Billing::class)->setAddon($paid, 'branches', 0);

        // after the window, or for a trial once the offer is switched off: the plan's own limit
        $this->travel(13)->months();
        Entitlements::flush();
        $this->assertFalse(LaunchOffer::coversBranchNow($sub->fresh()));
        $this->travelBack();
        PlatformSetting::put(LaunchOffer::ENABLED_KEY, '0');
        $late = $this->restaurant('late-trial', 'full', true);
        Entitlements::flush();
        $this->assertSame($base, $this->as($late, fn () => Entitlements::current()->limit('branches')));
    }

    public function test_an_offer_end_date_in_the_past_stops_it(): void
    {
        PlatformSetting::put(LaunchOffer::ENDS_KEY, now()->subDay()->toDateString());
        $this->assertFalse(LaunchOffer::isRunning());
        PlatformSetting::put(LaunchOffer::ENDS_KEY, now()->addDay()->toDateString());
        $this->assertTrue(LaunchOffer::isRunning());
    }

    public function test_self_service_stops_at_five_branches(): void
    {
        $tenant = $this->restaurant('chain', 'full');
        app(Billing::class)->setAddon($tenant, 'branches', 4);

        $this->actingAs($this->owner($tenant))->post($this->dashUrl('/billing/addons/branches'))->assertSessionHasErrors('addon');
        $this->assertSame(0, Invoice::query()->where('tenant_id', 'chain')->count());
        $this->actingAs($this->owner($tenant))->get($this->dashUrl('/billing'))->assertOk()->assertSee('data-branch-cap', false);
    }

    public function test_the_pricing_page_shows_branches_users_and_the_offer_from_the_database(): void
    {
        $this->get($this->menuUrl('pricing'))->assertOk()
            ->assertSee(__('Includes one branch and :users users', ['users' => 8]))
            ->assertSee(__('Each extra branch: :total EGP / month', ['total' => money(600)]))
            ->assertSee('data-launch-offer', false)
            ->assertSee('data-year-price="'.money(12000).'"', false);
    }
}
