<?php

namespace Tests\Feature;

use App\Services\Settings;
use App\Support\MenuThemes;
use Tests\TenantTestCase;

/** Every menu template gives the guest pages a full set of colors; sheets, inputs and buttons read them. */
class MenuThemeTokensTest extends TenantTestCase
{
    private const TOKENS = ['--menu-bg', '--menu-surface', '--menu-surface-2', '--menu-text', '--menu-muted', '--menu-border', '--menu-accent', '--menu-accent-text'];

    public function test_each_theme_renders_its_tokens_and_the_sheets_use_them(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a);

        foreach (MenuThemes::keys() as $theme) {
            $this->as($a, fn () => Settings::put(['menu_theme' => $theme]));
            $html = $this->get($this->subUrl('cafe-a'))->assertOk()->getContent();
            $palette = MenuThemes::PALETTES[$theme];

            foreach (self::TOKENS as $token) {
                $this->assertStringContainsString("$token:", $html, "$theme misses $token");
            }
            $this->assertStringContainsString("--menu-surface: {$palette['surface']};", $html, $theme);
            $this->assertStringContainsString("--menu-accent: {$palette['accent']};", $html, $theme);
            // cart, item sheet and the "how will you order?" card are painted by the tokens, never a fixed white
            $this->assertStringContainsString('class="m-surface max-h-[90dvh] overflow-y-auto rounded-t-3xl p-5 sm:rounded-3xl" data-cart-panel', $html);
            $this->assertStringContainsString('class="m-surface flex flex-col max-h-[90dvh] overflow-y-auto overscroll-contain rounded-t-3xl sm:rounded-3xl" data-item-form', $html);
            $this->assertStringContainsString('m-choice-card m-surface', $html);
            $this->assertDoesNotMatchRegularExpression('/<(form|dialog)[^>]*\bbg-white\b/', $html, "$theme has a white sheet");
        }

        // dark templates: dark surfaces, readable text on them, tinted notices switch to their dark variants
        $this->as($a, fn () => Settings::put(['menu_theme' => 'bistro']));
        $this->get($this->subUrl('cafe-a'))->assertSee('--menu-accent: #C9A45C;', false)->assertSee('--menu-accent-text: #141414;', false)
            ->assertSee('color-scheme: dark', false);
    }

    public function test_the_restaurants_own_main_color_is_the_accent(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Settings::put(['menu_theme' => 'bistro', 'color_primary' => '#1E88E5']));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('--menu-accent: #1E88E5;', false)->assertSee('--menu-accent-text: #FFFFFF;', false);

        // a light own color gets dark text
        $this->assertSame('#111111', MenuThemes::textOn('#FFE066'));
        $this->assertSame('#FFFFFF', MenuThemes::textOn('#0A2540'));
    }

    public function test_the_other_guest_pages_share_the_theme(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Settings::put(['menu_theme' => 'bistro', 'reservations_enabled' => '1']));

        foreach (['links', 'about', 'reserve'] as $path) {
            $this->get($this->subUrl('cafe-a', $path))->assertOk()->assertSee('--menu-surface: #1E1E1E;', false);
        }
    }
}
