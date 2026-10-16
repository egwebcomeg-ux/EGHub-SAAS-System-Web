<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Onboarding;
use App\Services\Settings;
use Tests\TenantTestCase;

/** The "get your menu ready" checklist on the dashboard home: steps from real data, skip, hide, and the tutorial. */
class OnboardingTest extends TenantTestCase
{
    private const SETTINGS = [
        'brand_name' => 'Cafe A', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#E8642C',
        'color_secondary' => '#1E2D4F', 'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar',
    ];

    /** @return array<string,string> step key => todo | done | skipped, as the home page renders it */
    private function states(Tenant $tenant, string $login = 'owner'): array
    {
        $user = $login === 'owner' ? $this->owner($tenant) : $this->staff($tenant, 'manager', 'm@cafe-a.test');
        $html = $this->actingAs($user)->get($this->dashUrl('/'))->assertOk()->getContent();
        preg_match_all('/data-onboarding-step="([a-z]+)" data-state="([a-z]+)"/', $html, $m, PREG_SET_ORDER);

        return array_combine(array_column($m, 1), array_column($m, 2));
    }

    public function test_steps_are_detected_from_real_data_and_the_card_hides_at_100_percent(): void
    {
        $a = $this->restaurant('cafe-a', 'pro', true);     // Pro trial: loyalty is in the plan, no plan chosen yet
        $owner = $this->owner($a);

        $page = $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk()
            ->assertSee('data-onboarding data-percent="0"', false)->assertSee(__('Get your menu ready'))->assertSee('data-help-link', false);
        $this->assertSame(
            ['brand' => 'todo', 'menu' => 'todo', 'tables' => 'todo', 'ordering' => 'todo', 'staff' => 'todo', 'loyalty' => 'todo', 'whatsapp' => 'todo', 'plan' => 'todo'],
            $this->states($a),
        );
        $page->assertSee(route('dash.settings'), false)->assertSee(route('dash.tables'), false)->assertSee(route('dash.billing'), false);

        // brand: logo + a color of their own (branding is in the Pro plan, so the default colors do not count)
        $this->as($a, fn () => Settings::put(['logo' => 'tenants/cafe-a/brand/logo.webp']));
        $this->assertSame('todo', $this->states($a)['brand']);
        $this->as($a, fn () => Settings::put(['color_primary' => '#AA0000']));
        $this->assertSame('done', $this->states($a)['brand']);

        // menu: 5 items with photos
        $this->as($a, function () {
            $cat = Category::query()->firstOrCreate(['name' => 'Coffee']);
            for ($i = 1; $i <= 5; $i++) {
                MenuItem::query()->create(['category_id' => $cat->id, 'name' => "Item $i", 'price' => 10, 'image' => $i < 5 ? "tenants/cafe-a/items/$i.webp" : null]);
            }
        });
        $this->assertSame('todo', $this->states($a)['menu']);
        $this->as($a, fn () => MenuItem::query()->where('name', 'Item 5')->update(['image' => 'tenants/cafe-a/items/5.webp']));
        $this->assertSame('done', $this->states($a)['menu']);

        // tables & QR: the starter tables exist, the print page has to be opened once
        $this->actingAs($owner)->get($this->dashUrl('/tables/print'))->assertOk();
        $this->assertNotSame('', $this->as($a, fn () => Settings::get('qr_printed_at')));
        $this->assertSame('done', $this->states($a)['tables']);

        // ordering decided: the settings form saved once (keeping the brand color chosen above)
        $this->actingAs($owner)->post($this->dashUrl('/settings'), ['color_primary' => '#AA0000'] + self::SETTINGS)->assertSessionHasNoErrors();
        $this->assertSame('done', $this->states($a)['ordering']);

        // staff invited, WhatsApp confirmed, plan chosen
        $this->staff($a, 'cashier', 'c@cafe-a.test');
        $owner->forceFill(['phone' => '01011111111'])->save();
        $owner->markPhoneVerified();
        Subscription::query()->where('tenant_id', 'cafe-a')->update(['status' => 'active']);
        $states = $this->states($a);
        $this->assertSame(['staff' => 'done', 'loyalty' => 'todo', 'whatsapp' => 'done', 'plan' => 'done'],
            array_intersect_key($states, array_flip(['staff', 'whatsapp', 'plan', 'loyalty'])));
        $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk()->assertSee('data-onboarding data-percent="88"', false);

        // the last step skipped: 100%, the card is gone, the tutorial link stays
        $this->actingAs($owner)->post($this->dashUrl('/onboarding/skip'), ['step' => 'loyalty'])->assertRedirect(route('dash.home'));
        $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk()->assertDontSee('data-onboarding', false)->assertSee('data-help-link', false);
    }

    public function test_loyalty_step_only_when_the_plan_has_it_and_links_follow_permissions(): void
    {
        $a = $this->restaurant('cafe-a', 'starter', false);
        $this->assertArrayNotHasKey('loyalty', $this->states($a));

        // a manager sees the checklist but has no link for what only the owner can do (team, plan)
        $html = $this->actingAs($this->staff($a, 'manager', 'm@cafe-a.test'))->get($this->dashUrl('/'))->assertOk()
            ->assertSee('data-onboarding', false)->getContent();
        $this->assertStringNotContainsString(route('dash.users'), $html);
        $this->assertStringNotContainsString(route('dash.billing'), $html);
        $this->assertStringContainsString(route('dash.settings'), $html);

        // other roles never see it and cannot touch it
        $waiter = $this->staff($a, 'waiter', 'w@cafe-a.test');
        $this->actingAs($waiter)->get($this->dashUrl('/'))->assertOk()->assertDontSee('data-onboarding', false);
        $this->actingAs($waiter)->post($this->dashUrl('/onboarding/skip'), ['step' => 'brand'])->assertForbidden();
        $this->actingAs($waiter)->post($this->dashUrl('/onboarding/dismiss'))->assertForbidden();
    }

    public function test_steps_can_be_skipped_and_the_whole_card_dismissed(): void
    {
        $a = $this->restaurant('cafe-a', 'pro', true);
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl('/onboarding/skip'), ['step' => 'nope'])->assertSessionHasErrors('step');
        $this->actingAs($owner)->post($this->dashUrl('/onboarding/skip'), ['step' => 'staff'])->assertRedirect(route('dash.home'));
        $this->actingAs($owner)->post($this->dashUrl('/onboarding/skip'), ['step' => 'staff'])->assertRedirect(route('dash.home'));   // twice is fine
        $this->assertSame(['staff'], $this->as($a, fn () => Onboarding::state()['skipped']));
        $this->assertSame('skipped', $this->states($a)['staff']);
        $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk()->assertSee('data-onboarding data-percent="13"', false);

        // a skipped step that gets done later counts as done
        $this->staff($a, 'cashier', 'c@cafe-a.test');
        $this->assertSame('done', $this->states($a)['staff']);

        $this->actingAs($owner)->post($this->dashUrl('/onboarding/dismiss'))->assertRedirect(route('dash.home'));
        $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk()->assertDontSee('data-onboarding', false);
        $this->assertTrue($this->as($a, fn () => Onboarding::state()['dismissed']));
        $this->assertSame(['staff'], $this->as($a, fn () => Onboarding::state()['skipped']));

        // a second restaurant is untouched
        $b = $this->restaurant('cafe-b');
        $this->actingAs($this->owner($b))->get($this->dashUrl('/'))->assertOk()->assertSee('data-onboarding', false);
    }

    public function test_the_tutorial_page_has_illustrated_steps_for_every_staff_member(): void
    {
        $a = $this->restaurant('cafe-a');

        $this->actingAs($this->owner($a))->get($this->dashUrl('/help'))->assertOk()
            ->assertSee(__('Dashboard tutorial'))->assertSee('data-help-steps', false)
            ->assertSee('/img/site/menu-editor-sm.webp', false)->assertSee('/img/site/tables-sm.webp', false)
            ->assertSee(route('dash.menu'), false)->assertSee(route('dash.users'), false);
        $this->assertFileExists(public_path('img/site/menu-editor-sm.webp'));

        // a waiter can read it too, without links to pages they cannot open
        $this->actingAs($this->staff($a, 'waiter', 'w@cafe-a.test'))->get($this->dashUrl('/help'))->assertOk()
            ->assertDontSee(route('dash.users'), false);
    }
}
