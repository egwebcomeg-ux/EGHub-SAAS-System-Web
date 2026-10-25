<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Tenant;
use App\Models\TvAlert;
use App\Models\TvScreen;
use App\Models\TvSlide;
use App\Services\Screens\ScreenAlerts;
use Illuminate\Support\Carbon;
use Tests\TenantTestCase;

/** EgHub screens: ready designs, urgent messages, what a screen shows now, and the «امسح واطلب» QR. */
class ScreenLiveTest extends TenantTestCase
{
    private Tenant $cafe;

    private TvScreen $screen;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cafe = $this->restaurant('cafe-a', 'business');
        $this->screen = $this->as($this->cafe, fn () => TvScreen::query()->create(['name' => 'Counter', 'token' => random_token(10)]));
        $this->token = $this->as($this->cafe, fn () => $this->screen->issueDeviceToken());
    }

    private function device(string $path)
    {
        return $this->getJson($this->menuUrl('screen/'.$path), ['X-Screen-Token' => $this->token]);
    }

    private function slides(): array
    {
        return $this->device('playlist')->assertOk()->json('slides');
    }

    public function test_a_ready_design_takes_the_items_live_price_and_hides_while_it_is_sold_out(): void
    {
        $latte = $this->item($this->cafe, 'Latte', 60);
        $owner = $this->owner($this->cafe);
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$this->screen->id}/slides"), [
            'type' => 'promo', 'duration' => 10, 'template' => 'spotlight', 'promo_item_id' => $latte->id, 'bg' => 'brand', 'badge' => 'New',
        ])->assertSessionHasNoErrors();

        $slide = $this->slides()[0];
        $this->assertSame(['promo', 'spotlight', 'brand', 'Latte', '60', 'New'], [$slide['type'], $slide['template'], $slide['bg'], $slide['title'], $slide['price'], $slide['badge']]);
        $this->as($this->cafe, fn () => MenuItem::query()->whereKey($latte->id)->update(['price' => 65]));
        $this->assertSame('65', $this->slides()[0]['price']);
        $this->as($this->cafe, fn () => MenuItem::query()->whereKey($latte->id)->update(['is_sold_out' => true]));
        $this->assertSame([], $this->slides());
    }

    public function test_a_ready_design_with_free_text_counts_down_and_stops_at_its_end(): void
    {
        $owner = $this->owner($this->cafe);
        $this->travelTo(Carbon::parse('2026-10-29 10:00', 'Africa/Cairo'));
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$this->screen->id}/slides"), [
            'type' => 'promo', 'duration' => 10, 'template' => 'countdown', 'title' => 'Breakfast deal', 'title_en' => 'Breakfast deal',
            'price' => '85', 'was' => '120', 'promo_ends_at' => '2026-10-29T12:00',
        ])->assertSessionHasNoErrors();

        $slide = $this->slides()[0];
        $this->assertSame(['Breakfast deal', '85', '120', true], [$slide['title'], $slide['price'], $slide['was'], $slide['live']]);
        $this->assertSame(Carbon::parse('2026-10-29 12:00', 'Africa/Cairo')->getTimestampMs(), $slide['ends']);
        $this->travelTo(Carbon::parse('2026-10-29 12:01', 'Africa/Cairo'));
        $this->assertFalse($this->slides()[0]['live']);

        // a design needs an item or a title, and one of the known templates
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$this->screen->id}/slides"), ['type' => 'promo', 'duration' => 10, 'template' => 'spotlight'])
            ->assertSessionHasErrors('title');
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$this->screen->id}/slides"), ['type' => 'promo', 'duration' => 10, 'template' => 'gradient-glow', 'title' => 'X'])
            ->assertSessionHasErrors('template');
    }

    public function test_the_design_picker_previews_draw_with_this_restaurants_brand(): void
    {
        $this->item($this->cafe, 'Latte', 60);
        $owner = $this->owner($this->cafe);

        $this->actingAs($owner)->get($this->dashUrl("/tv-screens/{$this->screen->id}?add=1"))->assertOk()
            ->assertSee('data-design-option="countdown"', false)->assertSee('data-design-option="scan"', false);
        $this->actingAs($owner)->get($this->dashUrl('/tv-screens/design?key=poster&o=portrait'))->assertOk()
            ->assertSee('window.EgDesigns', false)->assertSee('"key":"poster"', false)->assertSee('Restaurant cafe-a');
    }

    public function test_an_urgent_message_reaches_only_the_chosen_screens_until_it_is_cleared(): void
    {
        $other = $this->as($this->cafe, fn () => TvScreen::query()->create(['name' => 'Kitchen', 'token' => random_token(10)]));
        $otherToken = $this->as($this->cafe, fn () => $other->issueDeviceToken());
        $owner = $this->owner($this->cafe);

        $this->device('live')->assertOk()->assertJson(['alert' => null, 'poll' => ScreenAlerts::POLL_SECONDS]);
        $this->actingAs($owner)->post($this->dashUrl('/tv-screens/urgent'), ['text' => 'Order 42 is ready', 'style' => 'full', 'minutes' => 0, 'screen_ids' => [$this->screen->id]])
            ->assertSessionHasNoErrors()->assertRedirect(route('dash.tv.alerts'));

        $alert = $this->device('live')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('alert');
        $this->assertSame(['Order 42 is ready', 'full', null], [$alert['text'], $alert['style'], $alert['until']]);
        $this->getJson($this->menuUrl('screen/live'), ['X-Screen-Token' => $otherToken])->assertJson(['alert' => null]);
        // the old secret link gets it too
        $this->getJson($this->pathUrl('cafe-a', "tv/{$this->screen->token}/live"))->assertOk()->assertJsonPath('alert.text', 'Order 42 is ready');

        $this->actingAs($owner)->get($this->dashUrl('/tv-screens/urgent'))->assertOk()->assertSee('Order 42 is ready');
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/urgent/{$alert['id']}/clear"))->assertRedirect();
        $this->device('live')->assertJson(['alert' => null]);

        // a timed message ends by itself
        $this->actingAs($owner)->post($this->dashUrl('/tv-screens/urgent'), ['text' => 'Closing at 11', 'style' => 'banner', 'minutes' => 15, 'screen_ids' => [$this->screen->id, $other->id]]);
        $this->assertSame('Closing at 11', $this->device('live')->json('alert.text'));
        $this->travel(16)->minutes();
        $this->device('live')->assertJson(['alert' => null]);
    }

    public function test_urgent_messages_stay_inside_their_restaurant_and_branch(): void
    {
        $other = $this->restaurant('cafe-b', 'business');
        $theirScreen = $this->as($other, fn () => TvScreen::query()->create(['name' => 'B', 'token' => random_token(10)]));
        $theirToken = $this->as($other, fn () => $theirScreen->issueDeviceToken());
        $theirAlert = $this->as($other, fn () => TvAlert::query()->create(['text' => 'B only', 'style' => 'banner', 'screen_ids' => [$theirScreen->id]]));
        $owner = $this->owner($this->cafe);

        // their screen id is dropped: nothing is sent
        $this->actingAs($owner)->post($this->dashUrl('/tv-screens/urgent'), ['text' => 'Hi', 'style' => 'banner', 'minutes' => 5, 'screen_ids' => [$theirScreen->id]])
            ->assertSessionHasErrors('screen_ids');
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/urgent/{$theirAlert->id}/clear"))->assertNotFound();
        $this->getJson($this->menuUrl('screen/live'), ['X-Screen-Token' => $theirToken])->assertJsonPath('alert.text', 'B only');
        $this->device('live')->assertJson(['alert' => null]);
        $this->actingAs($owner)->get($this->dashUrl('/tv-screens/urgent'))->assertOk()->assertDontSee('B only');

        // staff tied to a branch send only to that branch's screens
        $second = $this->as($this->cafe, fn () => Branch::query()->create(['name' => 'Maadi', 'slug' => 'maadi', 'is_active' => true]));
        $maadi = $this->as($this->cafe, fn () => TvScreen::query()->create(['name' => 'Maadi', 'token' => random_token(10), 'branch_id' => $second->id]));
        $this->as($this->cafe, fn () => $this->screen->update(['branch_id' => Branch::query()->where('slug', '!=', 'maadi')->value('id')]));
        $manager = $this->staff($this->cafe, 'manager', 'm@cafe-a.test');
        $manager->forceFill(['branch_id' => $second->id])->save();
        $this->actingAs($manager)->post($this->dashUrl('/tv-screens/urgent'), ['text' => 'Maadi only', 'style' => 'banner', 'minutes' => 5, 'screen_ids' => [$maadi->id, $this->screen->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame([$maadi->id], $this->as($this->cafe, fn () => TvAlert::query()->latest('id')->first()->screen_ids));
        $ownerAlert = $this->as($this->cafe, fn () => TvAlert::query()->create(['text' => 'All', 'style' => 'banner', 'screen_ids' => [$maadi->id, $this->screen->id]]));
        $this->actingAs($manager)->post($this->dashUrl("/tv-screens/urgent/{$ownerAlert->id}/clear"))->assertNotFound();
    }

    public function test_the_device_reports_what_it_shows_and_the_dashboard_sees_it(): void
    {
        $this->item($this->cafe, 'Latte', 60);
        $slide = $this->as($this->cafe, fn () => $this->screen->slides()->create(['type' => 'promo', 'duration' => 10, 'template' => 'headline', 'options' => ['title' => 'We open at 8']]));
        $foreign = $this->as($this->restaurant('cafe-b', 'business'), fn () => TvSlide::query()->create(['type' => 'menu', 'duration' => 10, 'tv_screen_id' => null]));

        $this->getJson($this->menuUrl('screen/live?showing='.rawurlencode("main:{$slide->id},side:{$foreign->id}")), ['X-Screen-Token' => $this->token])->assertOk();
        $fresh = TvScreen::query()->withoutGlobalScopes()->find($this->screen->id);
        $this->assertSame($slide->id, $fresh->now_showing['main']);
        $this->assertArrayNotHasKey('side', $fresh->now_showing); // another restaurant's slide id is ignored
        $this->assertEquals($this->screen->updated_at, $fresh->updated_at); // not a content change
        $this->assertSame('online', $fresh->status());

        $json = $this->actingAs($this->owner($this->cafe))->getJson($this->dashUrl('/tv-screens/live'))->assertOk()->json('screens');
        $this->assertStringContainsString('data-now-slide="'.$slide->id.'"', $json[$this->screen->id]);
        $this->assertStringContainsString('data-tv-status="online"', $json[$this->screen->id]);
    }

    public function test_the_scan_to_order_corner_carries_the_menu_qr_of_the_screens_branch(): void
    {
        $this->item($this->cafe, 'Latte', 60);
        $this->assertNull($this->device('playlist')->json('brand.qr'));

        $second = $this->as($this->cafe, fn () => Branch::query()->create(['name' => 'Maadi', 'slug' => 'maadi', 'is_active' => true]));
        $this->as($this->cafe, fn () => $this->screen->update(['show_qr' => true, 'branch_id' => $second->id]));
        $json = $this->device('playlist')->json();
        $this->assertTrue($json['screen']['show_qr']);
        $this->assertStringStartsWith('<svg', (string) $json['brand']['qr']);
    }
}
