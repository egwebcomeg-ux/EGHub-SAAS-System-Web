<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Support\Tutorials;
use Tests\TenantTestCase;

/** "▶ شرح بالفيديو": every recorded tutorial on its page and in the help, only for who may open that page. */
class TutorialsTest extends TenantTestCase
{
    public function test_every_tutorial_has_its_video_and_a_page_that_exists(): void
    {
        foreach (Tutorials::all() as $key => $tutorial) {
            $this->assertFileExists(public_path("tutorials/$key.mp4"), $key);
            $this->assertTrue(app('router')->has($tutorial['route']), $key);
            $this->assertNotEmpty($tutorial['steps'], $key);
        }
    }

    public function test_a_page_offers_its_video_to_who_may_open_it_and_the_help_lists_them(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $owner = $this->owner($a);
        $waiter = $this->staff($a, 'waiter', 'w@cafe-a.test');

        $this->actingAs($owner)->get($this->dashUrl('/orders'))->assertOk()
            ->assertSee('data-tutorial="order-board"', false)->assertSee('tutorials/order-board.mp4', false);
        // the settings page has its own, not the board's
        $this->actingAs($owner)->get($this->dashUrl('/settings'))->assertOk()
            ->assertSee('data-tutorial="order-flow-settings"', false)->assertDontSee('data-tutorial="order-board"', false);

        // the help: the videos this user may watch (no cashier screen in this plan, so no cashier video)
        $help = $this->actingAs($owner)->get($this->dashUrl('/help'))->assertOk()->assertSee('data-help-videos', false);
        $help->assertSee('data-tutorial="order-board"', false)->assertDontSee('data-tutorial="pos-sale"', false);
        $this->actingAs($waiter)->get($this->dashUrl('/help'))->assertOk()->assertDontSee('data-tutorial="order-flow-settings"', false);

        app(Billing::class)->setAddon($a, 'pos_pack', 1);
        $this->actingAs($owner)->get($this->dashUrl('/help'))->assertSee('data-tutorial="pos-sale"', false);
    }
}
