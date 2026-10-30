<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Billing\CustomPlan;
use App\Billing\Lifecycle;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Entitlements;
use App\Services\ModuleChoice;
use Tests\TenantTestCase;

/** «صمم باقتك»: a plan built from the modules a place ticks, priced per module (App\Billing\CustomPlan). */
class CustomPlanTest extends TenantTestCase
{
    private function custom(): Plan
    {
        return CustomPlan::plan();
    }

    /** Subscribes $tenant (on a trial) to a custom plan with these modules for $months and pays it. */
    private function subscribe(Tenant $tenant, array $modules, int $months = 12): Invoice
    {
        $this->actingAs($this->owner($tenant))->post($this->dashUrl('/billing/custom-plan'), ['modules' => $modules, 'months' => $months])
            ->assertRedirect();
        $invoice = Invoice::query()->where('tenant_id', $tenant->id)->where('status', 'open')->latest('id')->firstOrFail();
        app(Billing::class)->markPaid($invoice, 'instapay', 'ref', $invoice->total, 'pay-'.$invoice->number);

        return $invoice;
    }

    public function test_the_price_is_the_modules_at_least_the_minimum_with_the_operations_markup_and_the_complete_cycle_discount(): void
    {
        $plan = $this->custom();

        $small = CustomPlan::quote(['loyalty'], $plan);
        $this->assertSame([99.0, 299.0, 200.0], [$small['modules'], $small['monthly'], $small['topUp']]);

        // the cashier needs the Complete plan otherwise, so it costs 25% more here: 199 → 249
        $rows = collect(CustomPlan::catalog($plan))->keyBy(fn (array $row) => $row['module']['slug']);
        $this->assertSame([249.0, true], [$rows['pos']['price'], $rows['pos']['markup']]);
        $this->assertSame([99.0, false], [$rows['loyalty']['price'], $rows['loyalty']['markup']]);
        $this->assertTrue($rows['qr-menu']['included']);
        $this->assertNull($rows['corporate-vouchers']['packs']); // in the Complete plan only
        $this->assertFalse($rows->has('featured-listing'));      // sold apart, on any plan

        $big = CustomPlan::quote(['pos', 'table-ordering', 'loyalty', 'online-ordering'], $plan);
        $this->assertSame(696.0, $big['monthly']);               // 249 + 99 + 99 + 249
        $this->assertSame(0.0, $big['topUp']);
        // the Complete plan's discounts: 10% for 6 months, 20% for 12
        $this->assertSame([1 => 696.0, 6 => 3758.0, 12 => 6682.0], $big['cycles']);
    }

    public function test_a_module_brings_what_it_needs_and_says_why(): void
    {
        $plan = $this->custom();

        $kitchen = CustomPlan::quote(['kitchen-display'], $plan);
        $this->assertSame(['table-ordering', 'kitchen-display'], $kitchen['slugs']);
        $this->assertSame(['table-ordering' => 'kitchen-display'], $kitchen['added']);
        $this->assertSame([], CustomPlan::quote(['kitchen-display', 'pos'], $plan)['added']);

        $this->assertSame(['online-ordering' => 'call-center'], CustomPlan::quote(['call-center'], $plan)['added']);
        $this->assertSame(['pos' => 'kiosk'], CustomPlan::quote(['kiosk'], $plan)['added']);
        // unknown modules and the ones sold apart are ignored
        $this->assertSame(['loyalty'], CustomPlan::quote(['loyalty', 'nope', 'featured-listing'], $plan)['slugs']);

        $this->getJson($this->menuUrl('pricing/custom-plan?modules[]=kitchen-display'))->assertOk()
            ->assertJsonPath('slugs', ['table-ordering', 'kitchen-display'])
            ->assertJsonPath('monthly', 299)
            ->assertSee('data-custom-added', false);
    }

    public function test_the_complete_plan_is_suggested_once_the_pick_costs_about_as_much(): void
    {
        $plan = $this->custom();

        $this->assertFalse(CustomPlan::quote(['loyalty', 'offers'], $plan)['nudge']);

        $many = CustomPlan::quote(['online-ordering', 'table-ordering', 'loyalty', 'reservations', 'campaigns', 'whatsapp', 'reports', 'wallet', 'offers'], $plan);
        $this->assertSame(1250.0, $many['complete']['total']);  // every one of them is in Complete
        $this->assertGreaterThanOrEqual(CustomPlan::COMPLETE_NUDGE_RATIO * 1250, $many['monthly']);
        $this->assertTrue($many['nudge']);

        // the cashier is not in Complete nor sold on top of it today: Complete can't stand in for the pick
        $this->assertNull(CustomPlan::quote(['pos', 'online-ordering', 'table-ordering', 'loyalty', 'reservations', 'campaigns'], $plan)['complete']);

        $this->get($this->menuUrl('pricing'))->assertOk()->assertSee('data-custom-plan', false)->assertSee('data-custom-module="pos"', false);
    }

    public function test_a_trial_subscribes_to_its_own_plan_and_gets_exactly_those_modules(): void
    {
        $a = $this->restaurant('cafe-a', 'full', trial: true);
        $this->actingAs($this->owner($a))->get($this->dashUrl('/billing'))->assertOk()->assertSee('data-billing-custom', false);

        $invoice = $this->subscribe($a, ['loyalty', 'offers']);
        // (99 + 49 → the 299 minimum) × 12 months − 20%
        $this->assertEqualsWithDelta(2870.4, $invoice->total, 0.01);
        $this->assertSame(['loyalty_pack', 'offers_pack'], $invoice->meta['packs']);

        $sub = Subscription::query()->where('tenant_id', 'cafe-a')->with('plan', 'addons')->first();
        $this->assertSame([CustomPlan::CODE, 'active', 12], [$sub->plan->code, $sub->status, $sub->cycle_months]);
        $this->assertEqualsCanonicalizing(['loyalty_pack', 'offers_pack'], $sub->addons->pluck('key')->all());
        Entitlements::flush();
        $rights = Entitlements::for($a);
        $this->assertTrue($rights->isPaid());
        $this->assertTrue($rights->allows('loyalty_stamps') && $rights->allows('offers'));
        $this->assertFalse($rights->allows('pos') || $rights->allows('qr_ordering') || $rights->allows('reservations'));
    }

    public function test_modules_are_added_until_the_period_ends_and_removed_at_once(): void
    {
        $a = $this->restaurant('cafe-a', 'full', trial: true);
        $this->subscribe($a, ['loyalty', 'offers']);
        $owner = $this->owner($a);

        // adding the cashier: only what goes over the 299 minimum is billed, until the period ends
        $this->actingAs($owner)->post($this->dashUrl('/billing/custom-plan'), ['modules' => ['loyalty', 'offers', 'pos'], 'months' => 1])->assertRedirect();
        $add = Invoice::query()->where('tenant_id', 'cafe-a')->where('kind', 'addon')->latest('id')->firstOrFail();
        $sub = Subscription::query()->where('tenant_id', 'cafe-a')->first();
        $months = max(0.1, round(now()->diffInDays($sub->current_period_end) / 30, 2));
        $this->assertEqualsWithDelta(round((99 + 49 + 249 - 299) * $months * 0.8), $add->total, 1);
        $this->assertFalse(Entitlements::for($a)->allows('pos'));
        app(Billing::class)->markPaid($add, 'instapay', 'ref', $add->total, 'pay-'.$add->number);
        Entitlements::flush();
        $this->assertTrue(Entitlements::for($a)->allows('pos'));
        $this->assertSame(249.0, (float) $sub->addons()->where('key', 'pos_pack')->value('unit_price'));

        // dropping the offers: off now, no invoice
        $this->actingAs($owner)->post($this->dashUrl('/billing/custom-plan'), ['modules' => ['loyalty', 'pos'], 'months' => 1])
            ->assertRedirect($this->dashUrl('/billing'));
        Entitlements::flush();
        $this->assertFalse(Entitlements::for($a)->allows('offers'));
        $this->assertSame(0, Invoice::query()->where('tenant_id', 'cafe-a')->where('status', 'open')->count());

        // renewing keeps the modules it has now: (99 + 249) × 12 − 20%
        $renewal = app(Billing::class)->invoiceForPlan($a, $this->custom(), 12);
        $this->assertTrue($renewal->meta['renewal']);
        $this->assertSame(['loyalty_pack', 'pos_pack'], $renewal->meta['packs']);
        $this->assertEqualsWithDelta(3340.8, $renewal->total, 0.01);
    }

    public function test_moving_to_complete_drops_the_packs_it_includes_and_prices_the_rest_for_it(): void
    {
        $a = $this->restaurant('cafe-a', 'full', trial: true);
        $this->subscribe($a, ['loyalty', 'pos'], 1);

        $quote = app(Billing::class)->quote($a, Plan::query()->where('code', 'full')->first(), 1);
        $labels = implode(' | ', array_column($quote['lines'], 'label'));
        $this->assertStringNotContainsString('Loyalty card', $labels);
        $this->assertContains(199.0, array_column($quote['lines'], 'amount')); // the cashier, without the custom markup

        $invoice = app(Billing::class)->invoiceForPlan($a, Plan::query()->where('code', 'full')->first(), 1);
        app(Billing::class)->markPaid($invoice, 'instapay', 'ref', $invoice->total, 'pay-'.$invoice->number);
        $sub = Subscription::query()->where('tenant_id', 'cafe-a')->with('addons')->first();
        $this->assertSame(['pos_pack'], $sub->addons->pluck('key')->all());
        $this->assertSame(199.0, (float) $sub->addons->first()->unit_price);
    }

    public function test_an_unpaid_custom_plan_falls_back_to_the_free_plan_like_any_other(): void
    {
        $a = $this->restaurant('cafe-a', 'full', trial: true);
        $this->subscribe($a, ['loyalty'], 1);

        $this->travel(32)->days();
        app(Lifecycle::class)->run();
        $this->assertSame('past_due', Subscription::query()->where('tenant_id', 'cafe-a')->value('status'));

        $this->travel(6)->days();
        app(Lifecycle::class)->run();
        $sub = Subscription::query()->where('tenant_id', 'cafe-a')->with('plan', 'addons')->first();
        $this->assertSame(['free', 0], [$sub->plan->code, $sub->addons->count()]);
        $this->assertFalse(Entitlements::for($a)->allows('loyalty_stamps'));
    }

    public function test_the_pick_on_the_pricing_page_follows_the_signup(): void
    {
        $this->get($this->menuUrl('register?modules=loyalty,offers'))->assertOk()->assertSee('data-signup-modules', false);
        $this->post($this->menuUrl('register'), [
            'name' => 'كافيه جديد', 'slug' => 'new-cafe', 'owner_name' => 'Sara', 'email' => 'sara@new.test', 'phone' => '01066666666',
            'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123', 'terms' => '1', 'modules' => 'loyalty,offers',
        ])->assertRedirect();

        $tenant = Tenant::query()->findOrFail('new-cafe');
        $this->assertSame(['qr-menu', 'loyalty', 'offers'], $this->as($tenant, fn () => ModuleChoice::chosen()));
        // still a trial of everything; the billing page ticks the pick for when it subscribes
        $this->assertSame('trialing', $tenant->subscription()->value('status'));
        $this->actingAs($this->owner($tenant))->get($this->dashUrl('/billing'))->assertOk()
            ->assertSee('name="modules[]" value="loyalty" class="mt-1" checked', false);
    }
}
