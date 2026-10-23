<?php

namespace Tests\Feature;

use App\Services\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** The "mascot" intro (brand character rolling in) and the header pattern with the peeking character. */
class MascotIntroTest extends TenantTestCase
{
    private const BEAN = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)"><script>alert(2)</script><circle cx="5" cy="5" r="4" fill="#28387E"/></svg>';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function settingsForm(array $extra = []): array
    {
        return [
            'brand_name' => 'Scratch', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#28387E', 'color_secondary' => '#F26522',
            'color_bg' => '#FFF8EE', 'font' => 'Cairo', 'default_locale' => 'ar', 'settings_tab' => 'menu', 'mascot_sent' => '1',
        ] + $extra;
    }

    public function test_the_style_is_validated_and_the_pieces_are_stored_cleaned_in_the_restaurants_folder(): void
    {
        $a = $this->restaurant('scratch');
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl('/settings'), $this->settingsForm(['menu_intro' => 'dance']))->assertSessionHasErrors('menu_intro');
        $this->actingAs($owner)->post($this->dashUrl('/settings'), $this->settingsForm(['intro_mascot' => UploadedFile::fake()->create('x.exe', 10)]))
            ->assertSessionHasErrors('intro_mascot');

        $this->actingAs($owner)->post($this->dashUrl('/settings'), $this->settingsForm([
            'menu_intro' => 'mascot', 'menu_header_brand' => '1',
            'intro_mascot' => UploadedFile::fake()->createWithContent('bean.svg', self::BEAN),
            'intro_pattern' => UploadedFile::fake()->image('pattern.png', 120, 120),
        ]))->assertSessionHasNoErrors();

        $s = $this->as($a, fn () => Settings::all());
        $this->assertSame('mascot', $s['menu_intro']);
        $this->assertSame('1', $s['menu_header_brand']);
        $this->assertMatchesRegularExpression('#^tenants/scratch/brand/[0-9a-f-]{36}\.svg$#', $s['intro_mascot']);
        $this->assertMatchesRegularExpression('#^tenants/scratch/brand/[0-9a-f-]{36}\.webp$#', $s['intro_pattern']);
        $svg = Storage::disk('public')->get($s['intro_mascot']);
        $this->assertStringNotContainsString('script', $svg);
        $this->assertStringNotContainsString('onload', $svg);
        $this->assertStringContainsString('<circle', $svg);

        // a later save without new files keeps them
        $this->actingAs($owner)->post($this->dashUrl('/settings'), $this->settingsForm(['menu_intro' => 'mascot', 'menu_header_brand' => '1']));
        $this->assertSame($s['intro_mascot'], $this->as($a, fn () => Settings::get('intro_mascot')));
    }

    public function test_the_svg_is_served_with_a_no_script_policy_and_only_by_its_own_restaurant(): void
    {
        $a = $this->restaurant('scratch');
        $b = $this->restaurant('other');
        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings'), $this->settingsForm([
            'intro_mascot' => UploadedFile::fake()->createWithContent('bean.svg', self::BEAN),
        ]));
        $file = basename($this->as($a, fn () => Settings::get('intro_mascot')));

        $this->get($this->subUrl('scratch', 'brand/'.$file))->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'; frame-ancestors 'none'; sandbox");
        // another restaurant's address never serves it
        $this->get($this->subUrl('other', 'brand/'.$file))->assertNotFound();
        $this->get($this->subUrl('scratch', 'brand/..%2F..%2Fx.svg'))->assertNotFound();
    }

    public function test_the_intro_plays_with_the_pieces_it_has(): void
    {
        $a = $this->restaurant('scratch');
        $this->as($a, fn () => Settings::put(['menu_intro' => 'mascot', 'intro_mascot' => 'tenants/scratch/brand/bean.webp']));

        $html = $this->get($this->subUrl('scratch'))->assertOk()
            ->assertSee('data-intro-style="mascot"', false)->assertSee('data-mascot', false)
            ->assertSee('/storage/tenants/scratch/brand/bean.webp', false)
            ->assertDontSee('data-mascot-speed', false)->assertDontSee('data-mascot-slogan', false)->assertDontSee('data-mascot-pattern', false)
            // the header option is off by default
            ->assertDontSee('data-brand-peek', false)
            ->getContent();
        // never under reduced motion; once per browser session
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce) { .m-intro { display: none !important; } }', $html);
        $this->assertStringContainsString('var again = false ? !internal', $html);

        // all pieces, and the header brand
        $this->as($a, fn () => Settings::put(['intro_mascot_speed' => 'tenants/scratch/brand/speed.webp', 'intro_slogan' => 'tenants/scratch/brand/slogan.webp',
            'intro_pattern' => 'tenants/scratch/brand/pattern.webp', 'menu_header_brand' => '1']));
        $this->get($this->subUrl('scratch'))->assertOk()
            ->assertSee('data-mascot-speed', false)->assertSee('data-mascot-slogan', false)->assertSee('data-mascot-pattern', false)
            ->assertSee('data-brand-peek', false)->assertSee('data-brand-pattern', false);
    }

    public function test_without_the_character_the_logo_intro_plays_and_bots_get_none(): void
    {
        $a = $this->restaurant('scratch');
        $this->as($a, fn () => Settings::put(['menu_intro' => 'mascot']));

        $this->get($this->subUrl('scratch'))->assertOk()->assertSee('m-intro--logo', false)->assertDontSee('data-intro-style="mascot"', false);

        $this->as($a, fn () => Settings::put(['intro_mascot' => 'tenants/scratch/brand/bean.webp']));
        $this->withHeader('User-Agent', 'facebookexternalhit/1.1')->get($this->subUrl('scratch'))->assertOk()->assertDontSee('data-intro', false);
    }
}
