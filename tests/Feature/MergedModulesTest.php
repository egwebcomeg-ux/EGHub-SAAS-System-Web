<?php

namespace Tests\Feature;

use App\Services\ModuleChoice;
use App\Services\Settings;
use App\Support\SiteModules;
use Tests\TenantTestCase;

/** "Advanced cards" joined the wallet and "advanced reports" joined the reports: one module each, old links and picks follow. */
class MergedModulesTest extends TenantTestCase
{
    public function test_old_module_pages_redirect_permanently(): void
    {
        $this->get($this->menuUrl('features/advanced-cards'))->assertStatus(301)->assertRedirect(route('features.show', 'wallet'));
        $this->get($this->menuUrl('features/advanced-reports'))->assertStatus(301)->assertRedirect(route('features.show', 'reports'));
        $this->assertNull(SiteModules::find('advanced-cards'));
        $this->assertNull(SiteModules::find('advanced-reports'));

        $this->get($this->menuUrl('features/wallet'))->assertOk()->assertSee(__('Prepaid packages'))->assertSee(__('Cashback to the wallet'));
        $this->get($this->menuUrl('features/reports'))->assertOk()->assertSee(__('Busy-hours heat map'))->assertSee(__('CSV and a weekly email'));
        $this->get($this->menuUrl('features/loyalty'))->assertOk()->assertSee(__('Membership tiers'));
        $this->get($this->menuUrl('sitemap.xml'))->assertOk()->assertDontSee('advanced-cards')->assertDontSee('advanced-reports');
    }

    public function test_saved_picks_with_the_old_slugs_map_to_the_merged_modules(): void
    {
        $tenant = $this->restaurant('cafe-a', 'full', true);
        $this->as($tenant, fn () => Settings::put(['modules' => json_encode(['advanced-cards', 'advanced-reports'])]));

        $this->assertSame(['qr-menu', 'wallet', 'reports'], $this->as($tenant, fn () => ModuleChoice::chosen()));
        $this->actingAs($this->owner($tenant))->get($this->dashUrl('/'))->assertOk();
        $this->actingAs($this->owner($tenant))->get($this->dashUrl('/modules'))->assertOk();

        $this->actingAs($this->owner($tenant))->post($this->dashUrl('/modules'), ['modules' => ['advanced-cards']])->assertSessionHasNoErrors();
        $this->assertSame(['qr-menu', 'wallet'], json_decode($this->as($tenant, fn () => Settings::get('modules')), true));
    }

    public function test_the_dashboard_has_one_wallet_entry_with_the_packages_tab_and_tiers_from_the_loyalty_card(): void
    {
        $tenant = $this->restaurant('cafe-a', 'full');
        $owner = $this->owner($tenant);

        $this->actingAs($owner)->get($this->dashUrl('/wallet'))->assertOk()
            ->assertSee('data-wallet-tab="dash.cards"', false);
        $this->actingAs($owner)->get($this->dashUrl('/loyalty/cards'))->assertOk()->assertSee('data-wallet-tab="dash.wallet"', false);
        $this->actingAs($owner)->get($this->dashUrl('/loyalty'))->assertOk()->assertSee('data-tiers-link', false);
    }

    public function test_an_operations_pick_points_to_enterprise_on_the_summary(): void
    {
        $tenant = $this->restaurant('cafe-free', 'free');

        $this->actingAs($this->owner($tenant))->get($this->dashUrl('/modules/summary?modules[]=pos'))->assertOk()
            ->assertSee('data-summary-missing-module="pos"', false)
            ->assertSee('data-summary-contact="enterprise"', false)
            ->assertSee('data-contact-sales-link', false);
    }
}
