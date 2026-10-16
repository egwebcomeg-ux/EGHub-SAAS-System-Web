<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\AddonPrice;
use App\Services\Entitlements;
use Tests\TenantTestCase;

/** Every module is sold on its own, on top of any plan — the free one included — and limit packs add their units. */
class ModulesSoldSeparatelyTest extends TenantTestCase
{
    public function test_a_free_restaurant_can_buy_the_loyalty_card_alone(): void
    {
        $tenant = $this->restaurant('free-cafe', 'free');
        $owner = $this->owner($tenant);
        $this->assertFalse(Entitlements::for($tenant)->allows('loyalty_stamps'));
        $this->actingAs($owner)->get($this->dashUrl('/loyalty'))->assertForbidden();

        app(Billing::class)->setAddon($tenant, 'loyalty_pack', 1);
        $rights = Entitlements::for($tenant->fresh());
        $this->assertTrue($rights->allows('loyalty_stamps') && $rights->allows('loyalty_points') && $rights->allows('customers') && $rights->allows('feedback'));
        $this->assertFalse($rights->allows('qr_ordering'));
        $this->actingAs($owner)->get($this->dashUrl('/loyalty'))->assertOk();
        $this->actingAs($owner)->get($this->dashUrl('/loyalty/setup'))->assertOk();
    }

    public function test_table_ordering_offers_branding_and_tv_each_unlock_on_their_own(): void
    {
        $tenant = $this->restaurant('free-cafe', 'free');
        foreach (['ordering_pack' => 'qr_ordering|order_board', 'offers_pack' => 'offers', 'branding_pack' => 'hide_badge', 'tv_pack' => 'tv_screens', 'team_pack' => 'audit_log', 'multibranch_pack' => 'branch_pricing'] as $pack => $feature) {
            $this->assertFalse(Entitlements::for($tenant->fresh())->allows($feature), "$feature before $pack");
            app(Billing::class)->setAddon($tenant, $pack, 1);
            $this->assertTrue(Entitlements::for($tenant->fresh())->allows($feature), "$feature after $pack");
        }
        // the free limits still apply until a limit pack is bought
        $this->assertSame(30, Entitlements::for($tenant->fresh())->limit('menu_items'));
    }

    public function test_limit_packs_add_their_units_per_pack(): void
    {
        $tenant = $this->restaurant('free-cafe', 'free');
        $this->assertSame([30, 5, 1], [Entitlements::for($tenant)->limit('menu_items'), Entitlements::for($tenant)->limit('tables'), Entitlements::for($tenant)->limit('branches')]);

        app(Billing::class)->setAddon($tenant, 'menu_items', 2);
        app(Billing::class)->setAddon($tenant, 'tables', 1);
        app(Billing::class)->setAddon($tenant, 'branches', 1);
        $rights = Entitlements::for($tenant->fresh());
        $this->assertSame([130, 15, 2], [$rights->limit('menu_items'), $rights->limit('tables'), $rights->limit('branches')]);
    }

    public function test_every_sold_feature_has_a_pack_and_the_pricing_page_lists_them(): void
    {
        $packs = AddonPrice::query()->where('type', 'feature')->get();
        $covered = $packs->flatMap(fn (AddonPrice $p) => $p->features ?? [])->unique();
        foreach (['qr_ordering', 'loyalty_stamps', 'offers', 'tv_screens', 'full_branding', 'printed_menu', 'online_ordering', 'reservations', 'wallet', 'custom_domain', 'daily_reports', 'staff_roles'] as $feature) {
            $this->assertContains($feature, $covered, "$feature is not sold by any pack");
        }
        $this->assertNull(AddonPrice::query()->where('key', 'loyalty_pack')->value('min_plan'));

        $this->get($this->menuUrl('pricing'))->assertOk()
            ->assertSee('كارت الولاء')->assertSee('الطلب من الترابيزة')->assertSee('٥٠ صنف إضافي')
            ->assertSee(route('features.show', 'loyalty'), false);
    }
}
