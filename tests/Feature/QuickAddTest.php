<?php

namespace Tests\Feature;

use App\Models\ItemOptionGroup;
use App\Services\Settings;
use App\Support\MenuThemes;
use Tests\TenantTestCase;

/** The quick "+" of the menu items: one tap adds, choices open the sheet, never when the menu cannot order. */
class QuickAddTest extends TenantTestCase
{
    public function test_orderable_items_get_a_quick_add_and_choices_open_the_sheet(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte', 60);
        $tea = $this->item($a, 'Tea', 20);
        $juice = $this->item($a, 'Juice', 40);
        $cake = $this->item($a, 'Cake', 50);
        $this->as($a, function () use ($tea, $juice, $cake) {
            ItemOptionGroup::query()->create(['menu_item_id' => $tea->id, 'name' => 'Size', 'type' => 'single']);
            ItemOptionGroup::query()->create(['menu_item_id' => $juice->id, 'name' => 'Sugar', 'type' => 'multiple', 'is_required' => true]);
            $cake->update(['is_sold_out' => true]);
        });

        foreach ([...MenuThemes::keys(), 'list', 'grid', 'cards', 'text'] as $look) {
            $this->as($a, fn () => Settings::put(in_array($look, MenuThemes::keys(), true) ? ['menu_theme' => $look, 'menu_layout' => 'auto'] : ['menu_layout' => $look]));
            $html = $this->get($this->subUrl('cafe-a'))->assertOk()->getContent();
            $this->assertStringContainsString('data-quick-add="add" data-quick-item="'.$latte->id.'"', $html, $look);
            $this->assertStringContainsString('data-quick-add="sheet" data-quick-item="'.$tea->id.'"', $html, $look);
            $this->assertStringContainsString('data-quick-add="sheet" data-quick-item="'.$juice->id.'"', $html, $look);
            $this->assertStringNotContainsString('data-quick-item="'.$cake->id.'"', $html, $look);
            $this->assertStringContainsString('pb-32" data-menu-main', $html, $look);
        }
    }

    public function test_no_quick_add_when_the_menu_cannot_order(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a, 'Latte', 60);

        // menu only: no ordering, nothing in the hall
        $this->as($a, fn () => Settings::put(['ordering_enabled' => '0', 'dine_in_mode' => 'off']));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-quick-add=', false)->assertSee('"mode":"none"', false);

        // a list for the waiter without prices: no quick add either
        $this->as($a, fn () => Settings::put(['dine_in_mode' => 'list']));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-quick-add=', false)->assertSee('"mode":"list"', false);

        // with prices the list gets it
        $this->as($a, fn () => Settings::put(['dine_in_show_prices' => '1']));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-quick-add="add"', false)->assertSee('pb-32" data-menu-main', false);
    }
}
