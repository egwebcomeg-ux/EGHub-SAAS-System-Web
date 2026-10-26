<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Services\Settings;
use Tests\TenantTestCase;

/**
 * The guest menu's platform marks and header: a "by EgHub" capsule on every restaurant that does not pay yet
 * (trial or Free) and never EgWeb, the "Powered by EgHub" link following hide_badge, and the name next to the logo
 * (header_show_name: auto hides it when a logo exists).
 */
class GuestPagesBrandingTest extends TenantTestCase
{
    public function test_the_eghub_capsule_shows_on_a_trial_and_on_free_without_egweb(): void
    {
        $trial = $this->restaurant('cafe-trial', 'full', trial: true);
        $this->item($trial);
        $this->get($this->subUrl('cafe-trial'))->assertOk()->assertSee('data-guest-brand-badge', false)
            ->assertDontSee('egweb.com.eg', false)->assertDontSee('data-egweb-badge', false);
        $this->get($this->subUrl('cafe-trial', 'about'))->assertOk()->assertSee('data-guest-brand-badge', false);

        $free = $this->restaurant('cafe-free', 'free');
        $this->item($free);
        $this->get($this->subUrl('cafe-free'))->assertOk()->assertSee('data-guest-brand-badge', false)->assertDontSee('مقدم من')
            ->assertDontSee('egweb.com.eg', false);
    }

    public function test_a_paying_restaurant_follows_hide_badge_and_has_no_capsule(): void
    {
        $paid = $this->restaurant('cafe-paid', 'full');
        $this->item($paid);
        $this->get($this->subUrl('cafe-paid'))->assertOk()->assertDontSee('data-guest-brand-badge', false)->assertDontSee('مقدم من');

        $pro = $this->restaurant('cafe-pro', 'pro');
        $this->item($pro);
        $this->get($this->subUrl('cafe-pro'))->assertOk()->assertDontSee('data-guest-brand-badge', false)->assertSee('مقدم من');

        // the same paying place whose subscription lapsed: unpaid again, the capsule is back
        Subscription::query()->where('tenant_id', 'cafe-paid')->update(['status' => 'past_due']);
        $this->get($this->subUrl('cafe-paid'))->assertOk()->assertSee('data-guest-brand-badge', false);
    }

    public function test_the_header_name_hides_next_to_a_logo_on_auto(): void
    {
        $tenant = $this->restaurant('cafe-logo', 'full');
        $this->item($tenant);
        $this->as($tenant, fn () => Settings::put(['tagline' => 'Best beans in town']));

        // no logo: the name and tagline show
        $this->get($this->subUrl('cafe-logo'))->assertOk()->assertSee('data-header-name="shown"', false)
            ->assertSee('data-header-tagline>Best beans in town', false)->assertSee('data-about-link', false);

        // a logo on auto: name only for screen readers, no tagline, the about link stays
        $this->as($tenant, fn () => Settings::put(['logo' => 'logo/cafe.png']));
        $this->get($this->subUrl('cafe-logo'))->assertOk()->assertSee('data-header-name="hidden"', false)
            ->assertDontSee('data-header-tagline', false)->assertSee('data-about-link', false);

        $this->as($tenant, fn () => Settings::put(['header_show_name' => 'always']));
        $this->get($this->subUrl('cafe-logo'))->assertOk()->assertSee('data-header-name="shown"', false)
            ->assertSee('data-header-tagline>Best beans in town', false);

        $this->as($tenant, fn () => Settings::put(['logo' => '', 'header_show_name' => 'never']));
        $this->get($this->subUrl('cafe-logo'))->assertOk()->assertSee('data-header-name="hidden"', false);
    }

    public function test_the_owner_picks_the_header_name_choice_in_settings(): void
    {
        $tenant = $this->restaurant('cafe-set', 'full');
        $owner = $this->owner($tenant);

        $form = $this->as($tenant, fn () => array_intersect_key(Settings::all(), array_flip([
            'brand_name', 'currency', 'currency_en', 'color_primary', 'color_secondary', 'color_bg', 'font', 'default_locale',
        ])));

        $this->actingAs($owner, 'web')->get($this->dashUrl('/settings?tab=menu'))->assertOk()->assertSee('name="header_show_name"', false);
        $this->actingAs($owner, 'web')->post($this->dashUrl('/settings'), $form + ['header_show_name' => 'never', 'settings_tab' => 'menu'])->assertSessionHasNoErrors();
        $this->as($tenant, fn () => $this->assertSame('never', Settings::get('header_show_name')));

        $this->actingAs($owner, 'web')->post($this->dashUrl('/settings'), $form + ['header_show_name' => 'sometimes'])->assertSessionHasErrors('header_show_name');
    }

    public function test_social_accounts_show_in_the_header_and_a_follow_us_card_only_when_set(): void
    {
        $tenant = $this->restaurant('cafe-social', 'full');
        $this->item($tenant);

        $this->get($this->subUrl('cafe-social'))->assertOk()->assertSee('data-about-link', false)
            ->assertDontSee('data-social="header"', false)->assertDontSee('data-social="footer"', false);

        $this->as($tenant, fn () => Settings::put(['instagram' => 'https://instagram.com/cafe.social', 'tiktok' => 'https://tiktok.com/@cafe.social']));
        $this->get($this->subUrl('cafe-social'))->assertOk()
            ->assertSee('data-social="header"', false)->assertSee('data-social="footer"', false)
            ->assertSee('https://instagram.com/cafe.social', false)->assertSee('https://tiktok.com/@cafe.social', false)
            ->assertDontSee('aria-label="Facebook"', false);
    }
}
