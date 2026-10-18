<?php

namespace Tests\Feature;

use App\Models\AddonPrice;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Settings;
use Tests\TenantTestCase;

/** "What will you use?": the module step after signup, what saving it changes (focus only) and who may change it. */
class ModulePickerTest extends TenantTestCase
{
    /** @return list<string> the module slugs the step shows ticked */
    private function ticked(string $html): array
    {
        preg_match_all('/value="([a-z-]+)" data-module-box\s+(?:checked disabled|name="modules\[\]" checked)/', $html, $m);

        return $m[1];
    }

    /** @return array<string,string> onboarding step key => state, as the home page renders it */
    private function steps(Tenant $tenant): array
    {
        $html = $this->actingAs($this->owner($tenant))->get($this->dashUrl('/'))->assertOk()->getContent();
        preg_match_all('/data-onboarding-step="([a-z]+)" data-state="([a-z]+)"/', $html, $m, PREG_SET_ORDER);

        return array_combine(array_column($m, 1), array_column($m, 2));
    }

    private function savedModules(Tenant $tenant): string
    {
        return $this->as($tenant, fn () => Settings::get('modules'));
    }

    public function test_signup_lands_on_the_module_step_with_picks_that_suit_the_place(): void
    {
        $res = $this->post($this->menuUrl('register'), [
            'name' => 'مطعم جديد', 'slug' => 'new-grill', 'owner_name' => 'Sara', 'email' => 'sara@grill.test', 'business_type' => 'restaurant',
            'phone' => '01066666666', 'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123', 'terms' => '1',
        ]);
        $this->get($res->headers->get('Location'))->assertRedirect(route('dash.modules', ['welcome' => 1]));

        $tenant = Tenant::query()->findOrFail('new-grill');
        $html = $this->actingAs($this->owner($tenant))->get($this->dashUrl('/modules?welcome=1'))->assertOk()
            ->assertSee(__('One quick step before you start'))->assertSee('data-modules-skip', false)->getContent();
        $this->assertSame(['qr-menu', 'table-ordering', 'online-ordering', 'reservations', 'kitchen-display', 'reports'], $this->ticked($html));
        $this->assertSame('', $this->savedModules($tenant), 'showing the step saves nothing');
    }

    public function test_skipping_saves_nothing_and_keeps_the_full_dashboard(): void
    {
        $a = $this->restaurant('cafe-a', 'pro', true);

        $this->actingAs($this->owner($a))->post($this->dashUrl('/modules/skip'), ['welcome' => '1', 'modules' => ['pos']])
            ->assertRedirect(route('dash.home'));

        $this->assertSame('', $this->savedModules($a));
        $this->actingAs($this->owner($a))->get($this->dashUrl('/'))->assertOk()
            ->assertDontSee('data-nav-more', false)->assertDontSee('data-home-modules', false)->assertSee('data-modules-invite', false);
    }

    public function test_saving_puts_the_picked_modules_first_while_every_page_stays_open(): void
    {
        $a = $this->restaurant('cafe-a', 'pro', true);
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl('/modules'), ['modules' => ['table-ordering', 'unknown-module']])
            ->assertSessionHasErrors('modules.1');
        $this->assertSame('', $this->savedModules($a));

        $this->actingAs($owner)->post($this->dashUrl('/modules'), ['modules' => ['loyalty', 'table-ordering']])
            ->assertRedirect(route('dash.modules'));
        $this->assertSame(['qr-menu', 'table-ordering', 'loyalty'], json_decode($this->savedModules($a), true), 'the QR menu is always kept');

        $home = $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk()
            ->assertSee('data-nav-more', false)
            ->assertSee('data-nav-more-item="dash.reservations"', false)
            ->assertDontSee('data-nav-more-item="dash.orders"', false)
            ->assertDontSee('data-nav-more-item="dash.loyalty.desk"', false)
            ->assertSee('data-home-module="table-ordering"', false)
            ->assertSee('data-home-module="loyalty"', false);
        $home->assertDontSee('data-home-module="reservations"', false);

        // hidden from the main list, not from the restaurant: the page still opens
        $this->actingAs($owner)->get($this->dashUrl('/reservations'))->assertOk();

        // the step shows the saved pick next time
        $html = $this->actingAs($owner)->get($this->dashUrl('/modules'))->assertOk()->assertDontSee('data-modules-skip', false)->getContent();
        $this->assertSame(['qr-menu', 'table-ordering', 'loyalty'], $this->ticked($html));
    }

    public function test_the_checklist_keeps_only_the_steps_of_the_picked_modules(): void
    {
        $a = $this->restaurant('cafe-a', 'pro', true);
        $before = array_keys($this->steps($a));
        $this->assertSame(['brand', 'menu', 'tables', 'ordering', 'staff', 'loyalty', 'whatsapp', 'plan'], $before);

        $this->actingAs($this->owner($a))->post($this->dashUrl('/modules'), ['modules' => ['reservations', 'online-ordering']]);

        $this->assertSame(['brand', 'menu', 'tables', 'ordering', 'staff', 'reservations', 'zones', 'whatsapp', 'plan'], array_keys($this->steps($a)));
        $this->as($a, fn () => Settings::put(['reservations_enabled' => '1']));
        $this->assertSame('done', $this->steps($a)['reservations']);

        $this->actingAs($this->owner($a))->post($this->dashUrl('/modules'), ['modules' => ['reports']]);
        $this->assertSame(['brand', 'menu', 'tables', 'staff', 'whatsapp', 'plan'], array_keys($this->steps($a)));
    }

    public function test_the_summary_names_what_is_outside_the_plan_and_the_cheapest_way_to_get_it(): void
    {
        $a = $this->restaurant('cafe-a', 'starter');
        $owner = $this->owner($a);
        $planBefore = Subscription::query()->where('tenant_id', 'cafe-a')->value('plan_id');
        $expected = Plan::query()->where('code', 'basic')->value('monthly_price') + AddonPrice::query()->where('key', 'pos_pack')->value('monthly_price');

        $this->actingAs($owner)->get($this->dashUrl('/modules/summary?modules[]=pos'))->assertOk()
            ->assertSee('data-summary-missing-module="pos"', false)
            ->assertDontSee('data-summary-missing-module="qr-menu"', false)
            ->assertSee('data-summary-best="basic"', false)
            ->assertSee('data-summary-addon="pos_pack"', false)
            ->assertSee(__('About :price EGP / month', ['price' => money($expected)]))
            ->assertSee(route('dash.billing'), false);

        // what is already in the plan needs no advice
        $this->actingAs($owner)->get($this->dashUrl('/modules/summary?modules[]=menu-import'))->assertOk()
            ->assertDontSee('data-summary-missing', false)->assertSee(__('Your current plan covers everything you picked.'));

        // informing only: saving the pick neither subscribes nor bills
        $this->actingAs($owner)->post($this->dashUrl('/modules'), ['modules' => ['pos']])->assertRedirect(route('dash.modules'));
        $this->assertSame($planBefore, Subscription::query()->where('tenant_id', 'cafe-a')->value('plan_id'));
        $this->assertSame(0, Invoice::query()->where('tenant_id', 'cafe-a')->count());
        $this->actingAs($owner)->get($this->dashUrl('/pos'))->assertForbidden();
        $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk()
            ->assertSee('href="'.route('dash.billing').'" data-home-module="pos"', false);
    }

    public function test_staff_without_the_settings_permission_never_see_or_change_the_step(): void
    {
        $a = $this->restaurant('cafe-a', 'pro', true);
        $cashier = $this->staff($a, 'cashier', 'c@cafe-a.test');

        $this->actingAs($cashier)->get($this->dashUrl('/modules'))->assertForbidden();
        $this->actingAs($cashier)->get($this->dashUrl('/modules/summary'))->assertForbidden();
        $this->actingAs($cashier)->post($this->dashUrl('/modules'), ['modules' => ['pos']])->assertForbidden();
        $this->actingAs($cashier)->post($this->dashUrl('/modules/skip'))->assertForbidden();
        $this->assertSame('', $this->savedModules($a));
        $this->actingAs($cashier)->get($this->dashUrl('/'))->assertOk()->assertDontSee('data-nav-modules', false);

        $manager = $this->staff($a, 'manager', 'm@cafe-a.test');
        $this->actingAs($manager)->post($this->dashUrl('/modules'), ['modules' => ['pos']])->assertRedirect(route('dash.modules'));
        $this->assertSame(['qr-menu', 'pos'], json_decode($this->savedModules($a), true));
    }

    public function test_one_restaurants_pick_never_reaches_another(): void
    {
        $a = $this->restaurant('cafe-a', 'pro', true);
        $b = $this->restaurant('cafe-b', 'pro', true);

        $this->actingAs($this->owner($a))->post($this->dashUrl('/modules'), ['modules' => ['loyalty']]);

        $this->assertNotSame('', $this->savedModules($a));
        $this->assertSame('', $this->savedModules($b));
        $this->actingAs($this->owner($b))->get($this->dashUrl('/'))->assertOk()->assertDontSee('data-nav-more', false);
        $this->actingAs($this->owner($a))->get($this->dashUrl('/'))->assertOk()->assertSee('data-nav-more', false);
    }
}
