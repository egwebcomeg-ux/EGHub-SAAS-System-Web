<?php

namespace Tests\Feature;

use App\Models\CampaignAutomation;
use App\Services\Entitlements;
use App\Services\Settings;
use Tests\TenantTestCase;

/**
 * The rewards page: every reward setting on one page, a section per plan feature and permission, the old setup pages
 * sending to their section, and the two welcomes side by side, each saved where it always was and coming back here.
 */
class RewardsPageTest extends TenantTestCase
{
    public function test_the_old_setup_pages_open_their_section(): void
    {
        $owner = $this->owner($this->restaurant('cafe-a', 'full'));

        $this->actingAs($owner)->get($this->dashUrl('/loyalty/setup'))->assertRedirect($this->dashUrl('/rewards#program'));
        $this->actingAs($owner)->get($this->dashUrl('/loyalty/cards'))->assertRedirect($this->dashUrl('/rewards#cashback'));
    }

    public function test_the_owner_of_a_complete_restaurant_sees_every_section_and_both_welcomes(): void
    {
        $owner = $this->owner($this->restaurant('cafe-a', 'full'));

        $page = $this->actingAs($owner)->get($this->dashUrl('/rewards'))->assertOk()
            ->assertSee('data-welcome-compare', false)
            ->assertSee('data-welcome-form', false)
            ->assertSee('data-automation="welcome"', false)
            ->assertDontSee('data-automation="winback"', false);
        foreach (['program', 'welcome', 'cashback', 'tiers', 'packages', 'wallet'] as $section) {
            $page->assertSee('data-rewards-section="'.$section.'"', false);
        }
    }

    public function test_each_section_needs_its_plan_feature_and_permission(): void
    {
        $tenant = $this->restaurant('cafe-a', 'pro');
        $rights = Entitlements::for($tenant);
        $this->assertTrue($rights->allows('loyalty_stamps'));
        $this->assertFalse($rights->allows('cashback|wallet'));

        $this->actingAs($this->owner($tenant))->get($this->dashUrl('/rewards'))->assertOk()
            ->assertSee('data-rewards-section="program"', false)
            ->assertDontSee('data-rewards-section="cashback"', false)
            ->assertDontSee('data-rewards-section="wallet"', false);

        // the cashier sets no reward: the route lets the wallet permission in, nothing is left to show
        $this->actingAs($this->staff($tenant, 'cashier', 'cashier@a.test'))->get($this->dashUrl('/rewards'))->assertForbidden();
        $this->actingAs($this->staff($tenant, 'callcenter', 'calls@a.test'))->get($this->dashUrl('/rewards'))->assertForbidden();

        // in a Complete restaurant the cashier sees the wallet's section only
        $complete = $this->restaurant('cafe-b', 'full');
        $this->actingAs($this->staff($complete, 'cashier', 'cashier@b.test'))->get($this->dashUrl('/rewards'))->assertOk()
            ->assertSee('data-rewards-section="wallet"', false)
            ->assertDontSee('data-rewards-section="program"', false)
            ->assertDontSee('data-welcome-form', false);
    }

    public function test_both_welcomes_save_where_they_always_did_and_come_back_to_the_rewards_page(): void
    {
        $tenant = $this->restaurant('cafe-a', 'full');
        $owner = $this->owner($tenant);
        $this->actingAs($owner)->get($this->dashUrl('/rewards'))->assertOk();

        $this->actingAs($owner)->post($this->dashUrl('/loyalty/welcome'), ['enabled' => '1', 'type' => 'percent', 'value' => 10])
            ->assertRedirect($this->dashUrl('/rewards#welcome'));
        $this->assertSame('1', $this->as($tenant, fn () => Settings::get('welcome_reward_enabled')));

        $this->actingAs($owner)->post($this->dashUrl('/campaigns/automations/welcome/toggle'), ['return' => 'rewards'])
            ->assertRedirect($this->dashUrl('/rewards#welcome'));
        $this->assertTrue($this->as($tenant, fn () => CampaignAutomation::query()->where('kind', 'welcome')->value('is_enabled')));
        $this->actingAs($owner)->post($this->dashUrl('/campaigns/automations/welcome'), [
            'return' => 'rewards', 'is_enabled' => 1, 'message' => 'Thanks {name}', 'send_from' => 10, 'send_to' => 22,
        ])->assertRedirect($this->dashUrl('/rewards#welcome'));

        // from the campaigns page they still go back to its automations tab
        $this->actingAs($owner)->post($this->dashUrl('/campaigns/automations/welcome/toggle'))
            ->assertRedirect($this->dashUrl('/campaigns?tab=automations'));
    }
}
