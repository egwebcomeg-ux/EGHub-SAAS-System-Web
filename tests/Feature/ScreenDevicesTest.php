<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TvScreen;
use App\Support\ScreenDevices;
use App\Support\ScreenShape;
use Tests\TenantTestCase;

/** «نوع الشاشة»: the owner picks the kind of TV, sees its own steps, and the screen keeps the kind for later help. */
class ScreenDevicesTest extends TenantTestCase
{
    private const TIZEN = 'Mozilla/5.0 (SMART-TV; LINUX; Tizen 6.5) AppleWebKit/537.36 (KHTML, like Gecko) 85.0.4183.93/6.5 TV Safari/537.36';

    private function screen(Tenant $tenant, string $name = 'Counter'): TvScreen
    {
        return $this->as($tenant, fn () => TvScreen::query()->create(['name' => $name, 'token' => random_token(10)]));
    }

    private function startPairing(string $userAgent): string
    {
        return $this->withHeader('User-Agent', $userAgent)->postJson($this->menuUrl('screen/pair'))->assertOk()->json('code');
    }

    public function test_the_pairing_page_shows_the_type_picker_and_each_type_has_its_own_steps(): void
    {
        $cafe = $this->restaurant('cafe-a', 'business');
        $this->screen($cafe);

        $page = $this->actingAs($this->owner($cafe))->get($this->dashUrl('/tv-screens/pair?type=xiaomi-box'))->assertOk()
            ->assertSee('data-device-picker', false)
            ->assertSee('data-device-tile="samsung"', false)
            ->assertSee('data-device-tile="haier"', false)->assertSee('data-device-tile="unionaire"', false)->assertSee('data-device-tile="tornado"', false)
            ->assertSee('data-device-tile="'.ScreenDevices::UNKNOWN.'"', false)
            ->assertSee('value="xiaomi-box" class="sr-only" checked', false)
            ->assertSee('eghub.app/tv.apk')
            ->assertSee('Install unknown apps', false);
        foreach (ScreenDevices::keys() as $key) {
            $page->assertSee('data-guide-for="'.$key.'"', false);
        }
    }

    public function test_the_picked_type_is_saved_at_pairing_and_shown_on_the_screen_card_with_its_help(): void
    {
        $cafe = $this->restaurant('cafe-a', 'business');
        $screen = $this->screen($cafe);
        $owner = $this->owner($cafe);
        $code = $this->startPairing('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0');

        $this->actingAs($owner)->post($this->dashUrl('/tv-screens/pair'), ['code' => $code, 'screen_id' => $screen->id, 'device_type' => 'lg'])
            ->assertSessionHasNoErrors()->assertRedirect(route('dash.tv.edit', $screen));
        $this->assertSame('lg', TvScreen::query()->withoutGlobalScopes()->find($screen->id)->device_type);

        $this->actingAs($owner)->get($this->dashUrl('/devices?tab=tv'))->assertOk()
            ->assertSee('data-device-type="lg"', false)
            ->assertSee(ScreenDevices::title('lg'))
            ->assertSee(route('dash.tv.device', $screen), false)
            ->assertSee(route('dash.tv.device', ['screen' => $screen, 'change' => 1]), false);
        $this->actingAs($owner)->get($this->dashUrl("/tv-screens/{$screen->id}/device"))->assertOk()
            ->assertSee('data-device-guide="lg"', false)->assertSee('Energy Saving', false)->assertDontSee('data-device-picker', false);

        // a type that is not in the list is refused
        $this->actingAs($owner)->post($this->dashUrl('/tv-screens/pair'), ['code' => '123456', 'screen_id' => $screen->id, 'device_type' => 'toaster'])
            ->assertSessionHasErrors('device_type');
    }

    public function test_changing_the_type_does_not_reload_the_screen(): void
    {
        $cafe = $this->restaurant('cafe-a', 'business');
        $screen = $this->screen($cafe);
        $before = TvScreen::query()->withoutGlobalScopes()->find($screen->id)->updated_at;
        $this->travel(5)->minutes();

        $this->actingAs($this->owner($cafe))->get($this->dashUrl("/tv-screens/{$screen->id}/device?change=1"))->assertOk()->assertSee('data-device-picker', false);
        $this->actingAs($this->owner($cafe))->post($this->dashUrl("/tv-screens/{$screen->id}/device"), ['device_type' => 'fire-tv'])
            ->assertRedirect(route('dash.tv.device', $screen));

        $stored = TvScreen::query()->withoutGlobalScopes()->find($screen->id);
        $this->assertSame('fire-tv', $stored->device_type);
        $this->assertTrue($stored->updated_at->equalTo($before));
        $this->actingAs($this->owner($cafe))->post($this->dashUrl("/tv-screens/{$screen->id}/device"), ['device_type' => ''])->assertSessionHasErrors('device_type');
    }

    public function test_the_type_is_suggested_from_the_device_that_shows_the_code(): void
    {
        $cafe = $this->restaurant('cafe-a', 'business');
        $screen = $this->screen($cafe);
        $owner = $this->owner($cafe);
        $code = $this->startPairing(self::TIZEN);

        // the QR opens the page with the code: Samsung is picked and said so
        $this->actingAs($owner)->get($this->dashUrl("/tv-screens/pair?code=$code"))->assertOk()
            ->assertSee('data-suggested="samsung"', false)
            ->assertSee('value="samsung" class="sr-only" checked', false);

        // the owner pairs without touching the picker: the screen keeps the suggestion
        $this->actingAs($owner)->post($this->dashUrl('/tv-screens/pair'), ['code' => $code, 'screen_id' => $screen->id])->assertSessionHasNoErrors();
        $this->assertSame('samsung', TvScreen::query()->withoutGlobalScopes()->find($screen->id)->device_type);
    }

    public function test_size_and_orientation_are_saved_and_the_playlist_carries_the_text_scale(): void
    {
        $cafe = $this->restaurant('cafe-a', 'business');
        $this->item($cafe, 'Latte', 60);
        $screen = $this->screen($cafe);
        $owner = $this->owner($cafe);
        $settings = ['name' => 'Door', 'is_active' => 1, 'theme' => 'dark', 'language' => '', 'shape' => 'portrait', 'turn' => 'left', 'screen_size' => '32', 'viewing_distance' => 'far'];

        // automatic: a small screen far from the guests gets bigger text
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$screen->id}/settings"), $settings + ['text_scale' => 90])->assertSessionHasNoErrors();
        $stored = TvScreen::query()->withoutGlobalScopes()->find($screen->id);
        $this->assertSame(['portrait2', '32', 'far', null], [$stored->orientation, $stored->screen_size, $stored->viewing_distance, $stored->text_scale]);
        $this->getJson($this->subUrl('cafe-a', "tv/{$screen->token}/playlist"))->assertOk()
            ->assertJsonPath('screen.orientation', 'portrait2')->assertJsonPath('screen.scale', 1.4);

        // «حجم الكلام» by hand wins over the size
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$screen->id}/settings"), $settings + ['text_scale' => 90, 'text_scale_manual' => 1])->assertSessionHasNoErrors();
        $this->getJson($this->subUrl('cafe-a', "tv/{$screen->token}/playlist"))->assertJsonPath('screen.scale', 0.9);
        $this->actingAs($owner)->get($this->dashUrl('/devices?tab=tv'))->assertOk()
            ->assertSee(__('Portrait, turned left').' · '.ScreenShape::sizeLabels()['32'].' · '.__('Text :percent%', ['percent' => 90]), false);

        // out of range or unknown values are refused
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$screen->id}/settings"), ['screen_size' => '99', 'text_scale' => 300, 'text_scale_manual' => 1] + $settings)
            ->assertSessionHasErrors(['screen_size', 'text_scale']);
    }

    public function test_the_pairing_page_saves_size_and_orientation_with_the_code(): void
    {
        $cafe = $this->restaurant('cafe-a', 'business');
        $screen = $this->screen($cafe);
        $owner = $this->owner($cafe);
        $code = $this->startPairing(self::TIZEN);

        $this->actingAs($owner)->get($this->dashUrl('/tv-screens/pair'))->assertOk()->assertSee('data-shape-fields', false)->assertSee('name="screen_size"', false);
        $this->actingAs($owner)->post($this->dashUrl('/tv-screens/pair'), ['code' => $code, 'screen_id' => $screen->id, 'shape' => 'portrait', 'turn' => 'right', 'screen_size' => '65', 'viewing_distance' => 'near'])
            ->assertSessionHasNoErrors();

        $stored = TvScreen::query()->withoutGlobalScopes()->find($screen->id);
        $this->assertSame(['portrait', '65', 'near'], [$stored->orientation, $stored->screen_size, $stored->viewing_distance]);
        $this->assertSame(0.85, ScreenShape::scale($stored));
    }

    public function test_user_agents_map_to_device_types(): void
    {
        $this->assertSame('samsung', ScreenDevices::suggest(self::TIZEN));
        $this->assertSame('lg', ScreenDevices::suggest('Mozilla/5.0 (Web0S; Linux/SmartTV) AppleWebKit/537.36 Chrome/94.0 Safari/537.36 WebAppManager'));
        $this->assertSame('fire-tv', ScreenDevices::suggest('Mozilla/5.0 (Linux; Android 9; AFTKA Build/PS7633) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36'));
        $this->assertSame('xiaomi-box', ScreenDevices::suggest('Mozilla/5.0 (Linux; Android 9; MIBOX4 Build/PI; wv) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 EgHubScreens/1.0.2 (AndroidTV)'));
        $this->assertSame('chromecast', ScreenDevices::suggest('Mozilla/5.0 (Linux; Android 12; Chromecast Build/STTE.230319.008; wv) AppleWebKit/537.36 Chrome/120.0 Safari/537.36'));
        $this->assertSame('android-box', ScreenDevices::suggest('Mozilla/5.0 (Linux; Android 11; X96Q Build/RP1A; wv) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 EgHubScreens/1.0.2 (AndroidTV)'));
        $this->assertSame('haier', ScreenDevices::suggest('Mozilla/5.0 (Linux; Android 11; Haier TV Build/RTT2; wv) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 EgHubScreens/1.0.2 (AndroidTV)'));
        $this->assertSame('tablet', ScreenDevices::suggest('Mozilla/5.0 (Linux; Android 14; SM-X210) AppleWebKit/537.36 Chrome/130.0 Safari/537.36'));
        $this->assertSame('pc', ScreenDevices::suggest('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36'));
        $this->assertNull(ScreenDevices::suggest(''));
    }

    public function test_the_public_page_lists_every_type_and_the_feature_page_links_to_it(): void
    {
        $page = $this->get($this->menuUrl('supported-screens'))->assertOk()->assertSee(__('Screens that work with EgHub'));
        foreach (ScreenDevices::all() as $key => $device) {
            $page->assertSee('data-device-row="'.$key.'"', false)->assertSee('id="'.$key.'"', false);
        }
        $page->assertSee('/og/ar/supported-screens.png', false);

        $this->get($this->menuUrl('features/tv-screens'))->assertOk()->assertSee(route('screens.supported'), false);
        $this->assertStringContainsString(route('screens.supported'), $this->get($this->menuUrl('sitemap.xml'))->assertOk()->getContent());
    }
}
