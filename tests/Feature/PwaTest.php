<?php

namespace Tests\Feature;

use App\Services\Settings;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/**
 * Every restaurant menu installs as its own app (manifest, icons from the logo, scoped service worker, offline
 * page) and the dashboard as "EgMenu".
 */
class PwaTest extends TenantTestCase
{
    /** PNG bytes of a solid $w × $h image in $rgb. */
    private function png(int $w, int $h, array $rgb): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    public function test_each_restaurant_has_its_own_manifest_on_every_host(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->restaurant('cafe-b');
        $this->as($a, fn () => Settings::put(['brand_name' => 'كافيه أ', 'brand_name_en' => 'Cafe A', 'color_bg' => '#FAFAFA', 'color_secondary' => '#123456', 'tagline' => 'قهوة مظبوطة']));

        // canonical address egmenu.app/cafe-a: the app owns /cafe-a/
        $response = $this->get($this->pathUrl('cafe-a', 'manifest.webmanifest'))->assertOk();
        $this->assertStringStartsWith('application/manifest+json', (string) $response->headers->get('Content-Type'));
        $response->assertJsonPath('name', 'كافيه أ')->assertJsonPath('short_name', 'كافيه أ')->assertJsonPath('description', 'قهوة مظبوطة')
            ->assertJsonPath('start_url', '/cafe-a/?source=pwa')->assertJsonPath('scope', '/cafe-a/')->assertJsonPath('id', '/cafe-a/')
            ->assertJsonPath('display', 'standalone')->assertJsonPath('dir', 'rtl')->assertJsonPath('lang', 'ar')
            ->assertJsonPath('theme_color', '#123456')->assertJsonPath('background_color', '#FAFAFA')
            ->assertJsonPath('icons.0.src', $this->pathUrl('cafe-a', 'icon-192.png'))->assertJsonPath('icons.0.sizes', '192x192')
            ->assertJsonPath('icons.0.type', 'image/png')->assertJsonPath('icons.0.purpose', 'any maskable')
            ->assertJsonPath('icons.1.src', $this->pathUrl('cafe-a', 'icon-512.png'));

        // the subdomain alias owns its whole host; English when the guest picked it
        $this->get($this->subUrl('cafe-a', 'manifest.webmanifest?lang=en'))->assertOk()
            ->assertJsonPath('name', 'Cafe A')->assertJsonPath('start_url', '/?source=pwa')->assertJsonPath('scope', '/')
            ->assertJsonPath('dir', 'ltr')->assertJsonPath('icons.0.src', $this->subUrl('cafe-a', 'icon-192.png'));

        // another restaurant, its own name and defaults
        $this->get($this->pathUrl('cafe-b', 'manifest.webmanifest'))->assertOk()
            ->assertJsonPath('name', 'Restaurant cafe-b')->assertJsonPath('theme_color', '#0A2540')->assertJsonPath('start_url', '/cafe-b/?source=pwa');

        $this->get($this->menuUrl('no-such-cafe/manifest.webmanifest'))->assertNotFound();
    }

    public function test_icons_are_png_made_from_the_logo_or_a_letter_on_the_brand_color(): void
    {
        Storage::fake('public');
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Settings::put(['brand_name' => 'Rosto', 'color_primary' => '#F26522']));

        // no logo yet: a letter on the brand color
        $response = $this->get($this->pathUrl('cafe-a', 'icon-192.png'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith("\x89PNG", $response->getContent());
        $img = imagecreatefromstring($response->getContent());
        $this->assertSame([192, 192], [imagesx($img), imagesy($img)]);
        $this->assertSame(0xF26522, imagecolorat($img, 4, 4));   // brand color fills the corners (maskable safe)
        $etag = $response->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->get($this->pathUrl('cafe-a', 'icon-192.png'), ['If-None-Match' => $etag])->assertStatus(304);

        // with a logo: the logo centered on the menu background color, 512 px
        Storage::disk('public')->put('tenants/cafe-a/brand/logo.png', $this->png(100, 60, [255, 0, 0]));
        $this->as($a, fn () => Settings::put(['logo' => 'tenants/cafe-a/brand/logo.png', 'color_bg' => '#FFFFFF']));
        $response = $this->get($this->pathUrl('cafe-a', 'icon-512.png'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertNotSame($etag, $response->headers->get('ETag'));
        $img = imagecreatefromstring($response->getContent());
        $this->assertSame([512, 512], [imagesx($img), imagesy($img)]);
        $this->assertSame(0xFFFFFF, imagecolorat($img, 4, 4));
        $this->assertSame(0xFF0000, imagecolorat($img, 256, 256));

        $this->get($this->pathUrl('cafe-a', 'icon-300.png'))->assertNotFound();
    }

    public function test_the_service_worker_is_served_per_restaurant_with_its_scope_and_an_offline_page(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Settings::put(['brand_name' => 'كافيه أ']));

        $response = $this->get($this->pathUrl('cafe-a', 'sw.js'))->assertOk()
            ->assertHeader('Service-Worker-Allowed', '/cafe-a/')->assertHeader('Cache-Control', 'no-cache, private');
        $this->assertStringStartsWith('application/javascript', (string) $response->headers->get('Content-Type'));
        $response->assertSee("'egmenu-cafe-a-v1'", false)->assertSee('"/cafe-a/"', false)->assertSee($this->pathUrl('cafe-a', 'offline'), false)
            ->assertSee("request.mode === 'navigate'", false);

        $this->get($this->subUrl('cafe-a', 'sw.js'))->assertOk()->assertHeader('Service-Worker-Allowed', '/')->assertSee('"/"', false);

        $this->get($this->pathUrl('cafe-a', 'offline'))->assertOk()->assertSee('كافيه أ')->assertSee(__('You are offline'))
            ->assertSee('href="'.$this->pathUrl('cafe-a').'"', false);
    }

    public function test_menu_pages_declare_the_app_and_carry_the_install_prompt(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Settings::put(['brand_name' => 'كافيه أ', 'color_secondary' => '#123456']));

        $this->get($this->pathUrl('cafe-a'))->assertOk()
            ->assertSee('<link rel="manifest" href="'.$this->pathUrl('cafe-a', 'manifest.webmanifest').'">', false)
            ->assertSee('<meta name="theme-color" content="#123456">', false)
            ->assertSee('<meta name="apple-mobile-web-app-capable" content="yes">', false)
            ->assertSee('<meta name="apple-mobile-web-app-title" content="كافيه أ">', false)
            ->assertSee('<link rel="apple-touch-icon" href="'.$this->pathUrl('cafe-a', 'icon-192.png').'">', false)
            ->assertSee('serviceWorker.register("'.$this->pathUrl('cafe-a', 'sw.js').'"', false)
            ->assertSee('id="pwa-install-prompt"', false)->assertSee('beforeinstallprompt', false)->assertSee(__('Add the menu to your home screen'))
            // and a button the guest can use any time, not only when the banner decides to show
            ->assertSee('data-pwa-open hidden', false)->assertSee(__('Add the menu to your phone'));

        // the profile page is part of the app but never prompts; the landing page is not an app
        $this->get($this->pathUrl('cafe-a', 'about'))->assertOk()->assertSee('rel="manifest"', false)->assertDontSee('id="pwa-install-prompt"', false);
        $this->get($this->menuUrl())->assertOk()->assertDontSee('rel="manifest"', false);
    }

    public function test_the_dashboard_installs_as_egmenu_with_a_service_worker_that_only_keeps_the_pos_page(): void
    {
        $a = $this->restaurant('cafe-a');

        $this->get($this->dashUrl('/manifest.webmanifest'))->assertOk()
            ->assertJsonPath('name', 'EgMenu')->assertJsonPath('short_name', 'EgMenu')->assertJsonPath('display', 'standalone')
            ->assertJsonPath('start_url', '/?source=pwa')->assertJsonPath('theme_color', '#0A2540')->assertJsonPath('background_color', '#FFFFFF')
            ->assertJsonPath('icons.0.src', versioned_asset('icons/egmenu-192.png'))->assertJsonPath('icons.1.src', versioned_asset('icons/egmenu-512.png'))
            ->assertJsonPath('icons.2.purpose', 'maskable');
        foreach (['egmenu-192.png' => 192, 'egmenu-512.png' => 512, 'egmenu-maskable-192.png' => 192, 'egmenu-maskable-512.png' => 512] as $file => $size) {
            $this->assertFileExists(public_path("icons/$file"));
            $this->assertSame([$size, $size], array_slice((array) getimagesize(public_path("icons/$file")), 0, 2));
        }

        $response = $this->get($this->dashUrl('/sw.js'))->assertOk()->assertHeader('Service-Worker-Allowed', '/')
            ->assertSee("'egmenu-dashboard-v2'", false)->assertSee("startsWith('/build/')", false)->assertDontSee('OFFLINE', false)
            // the only page it keeps is the POS (offline sales); every other navigation goes to the server
            ->assertSee("if (url.pathname === '/pos') event.respondWith(posPage(request));", false)->assertSee("'egmenu-pos-shell-v1'", false);
        $this->assertStringStartsWith('application/javascript', (string) $response->headers->get('Content-Type'));

        $this->actingAs($this->owner($a))->get($this->dashUrl('/'))->assertOk()
            ->assertSee('<link rel="manifest" href="'.$this->dashUrl('/manifest.webmanifest').'">', false)
            ->assertSee('<meta name="apple-mobile-web-app-title" content="EgMenu">', false)
            ->assertSee('serviceWorker.register("'.$this->dashUrl('/sw.js').'"', false)
            ->assertDontSee('id="pwa-install-prompt"', false);
        // the admin host is not an app
        $this->get($this->adminUrl('/login'))->assertOk()->assertDontSee('rel="manifest"', false);
    }
}
