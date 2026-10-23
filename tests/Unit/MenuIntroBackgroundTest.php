<?php

namespace Tests\Unit;

use App\Support\MenuIntro;
use PHPUnit\Framework\TestCase;

/** The intro screen turns light when a bare (untiled) logo would vanish on the dark brand colour. */
class MenuIntroBackgroundTest extends TestCase
{
    public function test_auto_turns_light_for_a_bare_logo_on_a_light_page(): void
    {
        $settings = ['intro_background' => 'auto', 'logo' => 'tenants/dukes/brand/logo.webp'];

        $this->assertSame('light', MenuIntro::background($settings, false, '#FDFBE6'));
        // a logo on its white tile shows on the brand colour
        $this->assertSame('brand', MenuIntro::background($settings, true, '#FDFBE6'));
        // a dark page (dark template) keeps the brand screen
        $this->assertSame('brand', MenuIntro::background($settings, false, '#0F1420'));
        // no logo: the name is written in white on the brand colour
        $this->assertSame('brand', MenuIntro::background(['logo' => ''] + $settings, false, '#FDFBE6'));
    }

    public function test_the_owner_choice_wins(): void
    {
        $this->assertSame('brand', MenuIntro::background(['intro_background' => 'brand', 'logo' => 'x.webp'], false, '#FFFFFF'));
        $this->assertSame('light', MenuIntro::background(['intro_background' => 'light', 'logo' => ''], true, '#0F1420'));
        $this->assertSame('brand', MenuIntro::background(['intro_background' => 'weird', 'logo' => ''], true, '#FFFFFF'));
    }
}
