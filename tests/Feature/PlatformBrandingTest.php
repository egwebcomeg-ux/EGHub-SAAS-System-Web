<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformSetting;
use App\Support\PlatformBrand;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** admin › الهوية والشكل: platform name, logos, favicon and colors, injected as CSS variables (guest menus excluded). */
class PlatformBrandingTest extends TenantTestCase
{
    private function platformOwner(): PlatformAdmin
    {
        return PlatformAdmin::query()->create(['name' => 'Owner', 'email' => 'owner@egweb.test', 'password' => 'Secret-pass-123']);
    }

    /** @return array<string, string> */
    private function valid(array $overrides = []): array
    {
        return $overrides + [
            'brand_site_name' => 'EgHub', 'brand_color_primary' => '#1F7A4D',
            'brand_color_secondary' => '#3355AA', 'brand_color_navy' => '#112233',
        ];
    }

    public function test_defaults_change_nothing(): void
    {
        $this->get($this->menuUrl())->assertOk()->assertDontSee('data-platform-brand', false)->assertSee('favicon.svg', false);
        $this->actingAs($this->platformOwner(), 'admin')->get($this->adminUrl('/branding'))->assertOk()
            ->assertSee('value="#F26522"', false)->assertSee(__('Reset to EgHub defaults'));
    }

    public function test_saved_colors_reach_the_site_auth_pages_and_dashboard_but_not_guest_menus(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $this->item($tenant);
        $this->actingAs($this->platformOwner(), 'admin')->post($this->adminUrl('/branding'), $this->valid(['brand_site_name' => 'MenuPro']))->assertSessionHas('ok');
        PlatformBrand::flush();

        $this->assertSame('#1F7A4D', PlatformSetting::get('brand_color_primary'));
        foreach ([$this->menuUrl(), $this->dashUrl('/login'), $this->adminUrl('/branding')] as $url) {
            $this->get($url)->assertOk()->assertSee('data-platform-brand', false)
                ->assertSee('--color-brand-600:#1f7a4d', false)->assertSee('--color-navy-600:#3355aa', false)->assertSee('--color-navy-800:#112233', false);
        }
        $this->actingAs($this->owner($tenant))->followingRedirects()->get($this->dashUrl('/'))->assertOk()->assertSee('--color-brand-600:#1f7a4d', false);
        // the wordmark follows the colors; the restaurant's own menu keeps its colors
        $this->get($this->menuUrl())->assertSee('fill="#1F7A4D"', false);
        $this->get($this->pathUrl('cafe-a'))->assertOk()->assertDontSee('data-platform-brand', false);
    }

    public function test_colors_are_validated_and_a_light_primary_gets_a_warning(): void
    {
        $owner = $this->platformOwner();

        $this->actingAs($owner, 'admin')->post($this->adminUrl('/branding'), $this->valid(['brand_color_primary' => 'orange']))
            ->assertSessionHasErrors('brand_color_primary');
        $this->actingAs($owner, 'admin')->post($this->adminUrl('/branding'), $this->valid(['brand_color_navy' => '#12345']))
            ->assertSessionHasErrors('brand_color_navy');
        $this->actingAs($owner, 'admin')->post($this->adminUrl('/branding'), $this->valid(['brand_site_name' => '']))
            ->assertSessionHasErrors('brand_site_name');
        $this->assertNull(PlatformSetting::get('brand_color_primary'));

        $this->actingAs($owner, 'admin')->post($this->adminUrl('/branding'), $this->valid(['brand_color_primary' => '#FFE066']))
            ->assertSessionHas('brand_warning');
        $this->assertLessThan(3, PlatformBrand::contrast('#FFE066', '#ffffff'));
    }

    public function test_uploaded_logos_replace_the_wordmark_and_reset_restores_everything(): void
    {
        Storage::fake('public');
        $owner = $this->platformOwner();

        $this->actingAs($owner, 'admin')->post($this->adminUrl('/branding'), $this->valid() + [
            'logo' => UploadedFile::fake()->image('logo.png', 600, 160),
            'logo_on_dark' => UploadedFile::fake()->image('logo-white.png', 600, 160),
            'favicon' => UploadedFile::fake()->image('icon.png', 128, 128),
        ])->assertSessionHas('ok');
        PlatformBrand::flush();

        $logo = PlatformBrand::get('brand_logo');
        $this->assertStringStartsWith('platform/brand/', $logo);
        Storage::disk('public')->assertExists($logo);
        $this->get($this->menuUrl())->assertSee('src="/storage/'.$logo.'"', false)
            ->assertSee('<link rel="icon" href="/storage/'.PlatformBrand::get('brand_favicon').'"', false);
        // the navy admin sidebar uses the logo made for dark backgrounds
        $this->actingAs($owner, 'admin')->get($this->adminUrl('/'))->assertSee('src="/storage/'.PlatformBrand::get('brand_logo_on_dark').'"', false);

        $this->actingAs($owner, 'admin')->post($this->adminUrl('/branding/reset'))->assertSessionHas('ok');
        PlatformBrand::flush();
        Storage::disk('public')->assertMissing($logo);
        $this->assertSame(PlatformBrand::DEFAULTS, PlatformBrand::all());
        $this->get($this->menuUrl())->assertDontSee('data-platform-brand', false)->assertDontSee('/storage/platform/brand', false);
    }

    public function test_only_settings_managers_open_the_page(): void
    {
        $content = PlatformAdmin::query()->create(['name' => 'C', 'email' => 'c@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'content']);

        $this->actingAs($content, 'admin')->get($this->adminUrl('/branding'))->assertForbidden();
        $this->actingAs($content, 'admin')->post($this->adminUrl('/branding'), $this->valid())->assertForbidden();
        $this->actingAs($content, 'admin')->post($this->adminUrl('/branding/reset'))->assertForbidden();
    }
}
