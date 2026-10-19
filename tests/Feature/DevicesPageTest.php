<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\Kiosk;
use App\Models\TvScreen;
use Tests\TenantTestCase;

/** "Screens & devices": TV screens, kiosks, the order board / customer display and the kitchen links, by plan and right. */
class DevicesPageTest extends TenantTestCase
{
    public function test_each_device_type_works_only_with_its_feature_and_shows_a_locked_card_otherwise(): void
    {
        // Pro: no TV screens, kiosk, customer display or kitchen display in the plan
        $pro = $this->restaurant('cafe-pro', 'pro');
        $owner = $this->owner($pro);
        $page = $this->actingAs($owner)->get($this->dashUrl('/devices'))->assertOk();
        foreach (['tv', 'kiosk', 'display', 'kitchen'] as $type) {
            $page->assertSee('data-device-locked="'.$type.'"', false);
        }
        $page->assertSee(route('dash.billing'), false)->assertDontSee('data-device="tv"', false);
        // no device in the plan: no sidebar entry (the page still explains what each one needs)
        $this->actingAs($owner)->get($this->dashUrl('/'))->assertDontSee('href="'.route('dash.devices').'"', false);

        // Business + kitchen pack: TV screens, the kitchen display; kiosk and customer display stay locked
        $biz = $this->restaurant('cafe-biz', 'business');
        app(Billing::class)->setAddon($biz, 'kitchen_pack', 1);
        $this->as($biz, fn () => TvScreen::query()->create(['name' => 'Counter', 'token' => str_repeat('a', 20)]));
        $this->actingAs($this->owner($biz))->get($this->dashUrl('/devices'))->assertOk()
            ->assertSee('data-device="tv"', false)->assertSee(str_repeat('a', 20))
            ->assertSee('data-device="kitchen"', false)->assertSee(route('dash.kitchen'), false)
            ->assertSee('data-device-locked="kiosk"', false)->assertDontSee('data-device-locked="tv"', false);
        $this->actingAs($this->owner($biz))->get($this->dashUrl('/'))->assertSee('href="'.route('dash.devices').'"', false);

        // the kitchen staff only sees the kitchen screens; a waiter's plan view has no locked upsell cards
        $this->actingAs($this->staff($biz, 'kitchen', 'k@biz.test'))->get($this->dashUrl('/devices'))->assertOk()
            ->assertSee('data-device="kitchen"', false)->assertDontSee('data-device="tv"', false)->assertDontSee('data-device-locked', false);
        $this->actingAs($this->staff($pro, 'kitchen', 'k@pro.test'))->get($this->dashUrl('/devices'))->assertForbidden();
    }

    public function test_a_new_link_retires_the_old_one_and_the_page_shows_the_new_one(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        app(Billing::class)->setAddon($a, 'kiosk_pack', 1);
        $owner = $this->owner($a);
        $kiosk = $this->as($a, fn () => Kiosk::query()->create(['name' => 'Tablet', 'branch_id' => Branch::query()->value('id'), 'token' => str_repeat('b', 20)]));
        $this->get($this->subUrl('cafe-a', 'kiosk/'.$kiosk->token))->assertOk();

        $this->actingAs($owner)->from($this->dashUrl('/devices?tab=kiosk'))->post($this->dashUrl("/kiosks/{$kiosk->id}/link"))
            ->assertRedirect($this->dashUrl('/devices?tab=kiosk'));
        $fresh = $kiosk->fresh();
        $this->assertNotSame($kiosk->token, $fresh->token);
        $this->get($this->subUrl('cafe-a', 'kiosk/'.$kiosk->token))->assertNotFound();
        $this->actingAs($owner)->get($this->dashUrl('/devices?tab=kiosk'))->assertOk()
            ->assertSee($fresh->token)->assertDontSee($kiosk->token)->assertSee('id="add-kiosk"', false);
    }

    public function test_devices_stay_inside_their_restaurant_and_old_addresses_open_the_right_tab(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $b = $this->restaurant('cafe-b', 'business');
        $theirs = $this->as($b, fn () => TvScreen::query()->create(['name' => 'Theirs', 'token' => str_repeat('c', 20)]));
        $owner = $this->owner($a);

        $this->actingAs($owner)->get($this->dashUrl('/devices'))->assertOk()->assertDontSee(str_repeat('c', 20))->assertDontSee('Theirs');
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$theirs->id}/link"))->assertNotFound();
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$theirs->id}/delete"))->assertNotFound();
        $this->assertSame(str_repeat('c', 20), $theirs->fresh()->token);

        $this->actingAs($owner)->get($this->dashUrl('/tv-screens'))->assertRedirect(route('dash.devices', ['tab' => 'tv']));
        $this->actingAs($owner)->get($this->dashUrl('/devices?tab=tv'))->assertOk()->assertSee('data-devices-tab="tv"', false)->assertSee('id="add-tv"', false);
        // a tab the user cannot open falls back to all
        $this->actingAs($owner)->get($this->dashUrl('/devices?tab=nope'))->assertOk()->assertSee('data-devices-tab="all"', false);
    }

    public function test_sidebar_moves_shifts_into_the_pos_area_zones_next_to_ordering_and_stations_under_the_kitchen(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        app(Billing::class)->setAddon($a, 'ops_bundle', 1);
        $owner = $this->owner($a);

        $home = $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('dash.pos').'"', $home);
        $this->assertStringNotContainsString('href="'.route('dash.shifts').'"', $home);
        $this->assertStringNotContainsString('href="'.route('dash.stations').'"', $home);
        $this->assertStringNotContainsString('href="'.route('dash.tv').'"', $home);
        $this->assertStringNotContainsString('href="'.route('dash.kiosks').'"', $home);
        // delivery zones sit right after the call center, in Orders & service
        $this->assertMatchesRegularExpression('#'.preg_quote(route('dash.callcenter'), '#').'.*?'.preg_quote(route('dash.zones'), '#').'.*?'.preg_quote(route('dash.kitchen'), '#').'#s', $home);

        // the shifts page is a tab of the POS area, and the POS entry stays lit there
        $shifts = $this->actingAs($owner)->get($this->dashUrl('/shifts'))->assertOk()
            ->assertSee('data-tab="pos"', false)->getContent();
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('dash.pos'), '#').'"\s+aria-current="page"#', $shifts);
        $this->assertMatchesRegularExpression('/data-tab="shifts"\s+aria-current="page"/', $shifts);
        // the stations are the kitchen screen's settings
        $this->actingAs($owner)->get($this->dashUrl('/kitchen'))->assertOk()->assertSee('data-kitchen-settings', false)->assertSee(route('dash.stations'), false);
        $this->actingAs($this->staff($a, 'kitchen', 'k@cafe-a.test'))->get($this->dashUrl('/kitchen'))->assertOk()->assertDontSee('data-kitchen-settings', false);
    }
}
