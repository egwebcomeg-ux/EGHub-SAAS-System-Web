<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Offer;
use App\Models\PagerTicket;
use App\Models\Tenant;
use App\Models\TvMedia;
use App\Models\TvScreen;
use App\Models\TvSlide;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** EgHub screens: the playlist a paired device plays, its hours, uploads, live updates and the device's health. */
class ScreenPlaylistTest extends TenantTestCase
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

    private function slide(array $values): TvSlide
    {
        return $this->as($this->cafe, fn () => $this->screen->slides()->create($values));
    }

    private function mp4(int $kb = 200, string $name = 'promo.mp4'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vid');
        file_put_contents($path, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", $kb * 1024));

        return new UploadedFile($path, $name, 'video/mp4', null, true);
    }

    public function test_the_playlist_carries_each_slide_with_live_prices_and_without_sold_out_items(): void
    {
        $latte = $this->item($this->cafe, 'Latte', 60);
        $this->item($this->cafe, 'Mocha', 70);
        $this->as($this->cafe, fn () => MenuItem::query()->where('name', 'Mocha')->update(['is_sold_out' => true]));
        $media = $this->as($this->cafe, fn () => TvMedia::query()->create(['kind' => 'video', 'path' => 'tenants/cafe-a/screens/a.mp4']));
        $this->slide(['type' => 'menu', 'sort_order' => 1, 'duration' => 20, 'layout' => 'grid', 'side_media_id' => $media->id]);
        $this->slide(['type' => 'video', 'sort_order' => 2, 'duration' => 0, 'media_id' => $media->id]);
        $this->slide(['type' => 'ready', 'sort_order' => 3, 'duration' => 15]);

        $json = $this->device('playlist')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();

        $this->assertSame(['menu', 'video', 'ready'], array_column($json['slides'], 'type'));
        $menu = $json['slides'][0];
        $this->assertSame('grid', $menu['layout']);
        $this->assertSame(['kind' => 'video', 'url' => '/storage/tenants/cafe-a/screens/a.mp4'], $menu['side']);
        $this->assertSame(['Latte'], array_column($menu['categories'][0]['items'], 'name'));
        $this->assertSame('60', $menu['categories'][0]['items'][0]['price']);
        $this->assertSame(0, $json['slides'][1]['duration']);
        $this->assertContains('/storage/tenants/cafe-a/screens/a.mp4', $json['media']);

        // a price change and a sold-out item change the fingerprint the screen polls
        $before = $this->device('version')->assertOk()->json('v');
        $this->as($this->cafe, fn () => MenuItem::query()->whereKey($latte->id)->update(['price' => 65]));
        $afterPrice = $this->device('version')->json('v');
        $this->assertNotSame($before, $afterPrice);
        $this->as($this->cafe, fn () => MenuItem::query()->whereKey($latte->id)->update(['is_sold_out' => true]));
        $this->assertNotSame($afterPrice, $this->device('version')->json('v'));
    }

    public function test_slide_hours_follow_cairo_time_and_change_the_version(): void
    {
        $this->item($this->cafe, 'Foul', 30);
        // breakfast until noon, on Fridays only
        $this->slide(['type' => 'menu', 'sort_order' => 1, 'duration' => 15, 'days' => [5], 'starts_at' => '07:00', 'ends_at' => '12:00']);

        $this->travelTo(Carbon::parse('2026-10-30 09:30', 'Africa/Cairo')); // a Friday
        $morning = $this->device('playlist')->json();
        $this->assertTrue($morning['slides'][0]['live']);
        $this->assertSame(['days' => [5], 'from' => '07:00', 'to' => '12:00'], $morning['slides'][0]['hours']);

        $this->travelTo(Carbon::parse('2026-10-30 12:30', 'Africa/Cairo'));
        $this->assertFalse($this->device('playlist')->json('slides.0.live'));
        $this->assertNotSame($morning['v'], $this->device('version')->json('v'));

        $this->travelTo(Carbon::parse('2026-10-31 09:30', 'Africa/Cairo')); // Saturday
        $this->assertFalse($this->device('playlist')->json('slides.0.live'));
    }

    public function test_hours_may_cross_midnight(): void
    {
        $slide = $this->slide(['type' => 'menu', 'duration' => 15, 'starts_at' => '22:00', 'ends_at' => '02:00', 'days' => [4]]);

        $this->assertTrue($slide->isOnAt(Carbon::parse('2026-10-29 23:00', 'Africa/Cairo'))); // Thursday night
        $this->assertTrue($slide->isOnAt(Carbon::parse('2026-10-30 01:30', 'Africa/Cairo'))); // still Thursday's window
        $this->assertFalse($slide->isOnAt(Carbon::parse('2026-10-30 02:30', 'Africa/Cairo')));
        $this->assertFalse($slide->isOnAt(Carbon::parse('2026-10-30 23:00', 'Africa/Cairo'))); // Friday night
    }

    public function test_an_offer_slide_shows_only_while_the_offer_runs(): void
    {
        $this->item($this->cafe, 'Latte', 60);
        $offer = $this->as($this->cafe, fn () => Offer::query()->create(['title' => 'Happy hour', 'discount_percent' => 25, 'starts_at' => '17:00', 'ends_at' => '19:00']));
        $this->slide(['type' => 'offer', 'duration' => 10, 'offer_id' => $offer->id]);

        $this->travelTo(Carbon::parse('2026-10-29 17:30', 'Africa/Cairo'));
        $slide = $this->device('playlist')->json('slides.0');
        $this->assertTrue($slide['live']);
        $this->assertSame(25, $slide['offers'][0]['percent']);
        $this->assertSame('45', $slide['items'][0]['price']);
        $this->assertSame('60', $slide['items'][0]['was']);

        $this->travelTo(Carbon::parse('2026-10-29 20:00', 'Africa/Cairo'));
        $this->assertFalse($this->device('playlist')->json('slides.0.live'));
    }

    public function test_ready_numbers_come_from_called_pager_tickets_and_need_the_feature(): void
    {
        $branch = $this->as($this->cafe, fn () => Branch::query()->first());
        $this->as($this->cafe, fn () => PagerTicket::query()->create(['branch_id' => $branch->id, 'number' => 42, 'service_date' => today(), 'token' => random_token(12), 'status' => 'called', 'called_at' => now()]));
        $this->as($this->cafe, fn () => PagerTicket::query()->create(['branch_id' => $branch->id, 'number' => 43, 'service_date' => today(), 'token' => random_token(12), 'status' => 'waiting']));

        $this->device('ready')->assertOk()->assertExactJson(['ready' => [42], 'preparing' => []]);

        // a plan without the pager or the customer display: no numbers, and the dashboard does not offer the slide
        $menuOnly = $this->restaurant('cafe-m', 'pro');
        app(Billing::class)->setAddon($menuOnly, 'screens', 1);
        $screen = $this->as($menuOnly, fn () => TvScreen::query()->create(['name' => 'M', 'token' => random_token(10)]));
        $this->actingAs($this->owner($menuOnly))->post($this->dashUrl("/tv-screens/{$screen->id}/slides"), ['type' => 'ready', 'duration' => 10])
            ->assertSessionHasErrors('type');
    }

    public function test_the_owner_builds_and_reorders_the_playlist(): void
    {
        $this->item($this->cafe, 'Latte', 60);
        $owner = $this->owner($this->cafe);
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$this->screen->id}/slides"), ['type' => 'menu', 'duration' => 12, 'layout' => 'featured', 'days' => [0, 1, 2, 3, 4, 5, 6]])
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$this->screen->id}/slides"), ['type' => 'ready', 'duration' => 8, 'starts_at' => '18:00', 'ends_at' => '23:00'])
            ->assertSessionHasNoErrors();
        [$menu, $ready] = $this->as($this->cafe, fn () => $this->screen->slides()->get()->all());
        $this->assertNull($menu->days); // every day ticked = no limit
        $this->assertSame('18:00', substr($ready->starts_at, 0, 5));

        $before = $this->device('version')->json('v');
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$this->screen->id}/slides/{$ready->id}/move"), ['direction' => 'up'])->assertRedirect();
        $this->assertSame([$ready->id, $menu->id], $this->as($this->cafe, fn () => $this->screen->slides()->pluck('id')->all()));
        $this->assertNotSame($before, $this->device('version')->json('v'));

        $this->actingAs($owner)->get($this->dashUrl("/tv-screens/{$this->screen->id}"))->assertOk()
            ->assertSee('id="slide-'.$ready->id.'"', false)->assertSee('data-tv-status="online"', false);
    }

    public function test_slides_and_media_of_another_restaurant_cannot_be_touched(): void
    {
        $other = $this->restaurant('cafe-b', 'business');
        $theirMedia = $this->as($other, fn () => TvMedia::query()->create(['kind' => 'image', 'path' => 'tenants/cafe-b/screens/x.webp']));
        $theirScreen = $this->as($other, fn () => TvScreen::query()->create(['name' => 'B', 'token' => random_token(10)]));
        $theirSlide = $this->as($other, fn () => $theirScreen->slides()->create(['type' => 'menu', 'duration' => 10]));
        $owner = $this->owner($this->cafe);

        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$this->screen->id}/slides"), ['type' => 'image', 'duration' => 10, 'image_id' => $theirMedia->id])
            ->assertSessionHasErrors('media_id');
        $this->actingAs($owner)->get($this->dashUrl("/tv-screens/{$theirScreen->id}"))->assertNotFound();
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$this->screen->id}/slides/{$theirSlide->id}/delete"))->assertNotFound();
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/media/{$theirMedia->id}/delete"))->assertNotFound();
        $this->assertTrue(TvSlide::query()->withoutGlobalScopes()->whereKey($theirSlide->id)->exists());
    }

    public function test_a_cashier_cannot_edit_screens(): void
    {
        $cashier = $this->staff($this->cafe, 'cashier', 'cashier@cafe-a.test');

        $this->actingAs($cashier)->post($this->dashUrl("/tv-screens/{$this->screen->id}/slides"), ['type' => 'menu', 'duration' => 10])->assertForbidden();
        $this->actingAs($cashier)->post($this->dashUrl('/tv-screens/media'), ['file' => $this->mp4()])->assertForbidden();
    }

    public function test_uploads_accept_pictures_and_videos_only(): void
    {
        Storage::fake('public');
        $owner = $this->owner($this->cafe);

        $this->actingAs($owner)->post($this->dashUrl('/tv-screens/media'), ['file' => $this->mp4(), 'screen' => $this->screen->id])
            ->assertSessionHasNoErrors()->assertRedirect(route('dash.tv.edit', $this->screen).'#media');
        $this->actingAs($owner)->post($this->dashUrl('/tv-screens/media'), ['file' => UploadedFile::fake()->image('menu.png', 1200, 800)])->assertSessionHasNoErrors();
        $media = $this->as($this->cafe, fn () => TvMedia::query()->orderBy('id')->get());
        $this->assertSame(['video', 'image'], $media->pluck('kind')->all());
        $this->assertMatchesRegularExpression('#^tenants/cafe-a/screens/[0-9a-f-]{36}\.mp4$#', $media[0]->path);
        $this->assertMatchesRegularExpression('#^tenants/cafe-a/screens/[0-9a-f-]{36}\.webp$#', $media[1]->path);
        Storage::disk('public')->assertExists($media[0]->path);

        // a script named .mp4, a file pretending to be a video, and a video over 80 MB are refused
        $script = UploadedFile::fake()->createWithContent('promo.mp4', '<?php echo "not a video"; ?>');
        $this->actingAs($owner)->post($this->dashUrl('/tv-screens/media'), ['file' => $script])->assertSessionHasErrors('file');
        $fake = UploadedFile::fake()->createWithContent('promo.mp4', str_repeat("\x01", 4096));
        $this->actingAs($owner)->post($this->dashUrl('/tv-screens/media'), ['file' => $fake])->assertSessionHasErrors();
        $this->actingAs($owner)->post($this->dashUrl('/tv-screens/media'), ['file' => UploadedFile::fake()->create('big.mp4', TvMedia::MAX_VIDEO_KB + 1, 'video/mp4')])
            ->assertSessionHasErrors('file');
        $this->assertSame(2, $this->as($this->cafe, fn () => TvMedia::query()->count()));
    }

    public function test_the_heartbeat_shows_the_screen_as_on_and_reload_reaches_the_device(): void
    {
        $this->postJson($this->menuUrl('screen/heartbeat'), ['app' => '1.2.0', 'device' => 'android-tv', 'model' => 'Mi Box S', 'screen' => '3840x2160'], ['X-Screen-Token' => $this->token])
            ->assertOk()->assertJson(['ok' => true]);
        $fresh = TvScreen::query()->withoutGlobalScopes()->find($this->screen->id);
        $this->assertSame('online', $fresh->status());
        $this->assertSame(['app' => '1.2.0', 'device' => 'android-tv', 'model' => 'Mi Box S', 'screen' => '3840x2160'], array_intersect_key($fresh->device_info, array_flip(['app', 'device', 'model', 'screen'])));
        // a heartbeat does not count as a content change
        $this->assertEquals($this->screen->updated_at, $fresh->updated_at);

        $this->actingAs($this->owner($this->cafe))->get($this->dashUrl('/devices?tab=tv'))->assertOk()->assertSee('data-tv-status="online"', false);

        $this->actingAs($this->owner($this->cafe))->post($this->dashUrl("/tv-screens/{$this->screen->id}/reload"))->assertRedirect();
        $this->assertNotNull($this->device('version')->json('reload'));

        $this->travel(TvScreen::ONLINE_MINUTES + 1)->minutes();
        $this->assertSame('offline', TvScreen::query()->withoutGlobalScopes()->find($this->screen->id)->status());
    }

    public function test_a_paused_restaurant_or_screen_does_not_play(): void
    {
        $this->as($this->cafe, fn () => TvScreen::query()->whereKey($this->screen->id)->update(['is_active' => false]));

        $this->device('playlist')->assertForbidden()->assertJson(['state' => 'paused']);
    }

    public function test_the_old_secret_link_opens_the_player_with_its_playlist(): void
    {
        $this->item($this->cafe, 'Latte', 60);

        $this->get($this->pathUrl('cafe-a', "tv/{$this->screen->token}"))->assertOk()->assertSee('"mode":"link"', false)->assertSee('Latte');
        $this->getJson($this->pathUrl('cafe-a', "tv/{$this->screen->token}/playlist"))->assertOk()->assertJsonPath('screen.id', $this->screen->id);
        $this->getJson($this->subUrl('cafe-a', "tv/{$this->screen->token}/version"))->assertOk()->assertJsonStructure(['v', 'reload']);
        // the device token is not a link token, and the link token is not a device token
        $this->getJson($this->menuUrl('screen/playlist'), ['X-Screen-Token' => $this->screen->token])->assertUnauthorized();
    }

    public function test_the_player_page_and_its_service_worker_load_without_a_session(): void
    {
        $this->get($this->menuUrl('tv'))->assertRedirect('/screen');
        $this->get($this->menuUrl('screen?device=androidtv'))->assertOk()->assertSee('"mode":"device"', false)->assertCookieMissing(config('session.cookie'));
        $this->get($this->menuUrl('screen/sw.js'))->assertOk()->assertHeader('Service-Worker-Allowed', '/screen');
    }
}
