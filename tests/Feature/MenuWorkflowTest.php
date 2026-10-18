<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\MenuItem;
use Tests\TenantTestCase;

/** Day-to-day menu editing: the inline price edit, "save and add another", and the menu page tools. */
class MenuWorkflowTest extends TenantTestCase
{
    public function test_inline_price_edit_saves_and_returns_the_formatted_price(): void
    {
        $a = $this->restaurant('cafe-a');
        $item = $this->item($a, 'Latte', 65);

        $this->actingAs($this->owner($a))->postJson($this->dashUrl("/menu/items/{$item->id}/price"), ['price' => '1250'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('price', 1250)
            ->assertJsonPath('formatted', fn (string $formatted) => str_starts_with($formatted, '1,250 '));

        $this->assertSame(1250.0, $this->as($a, fn () => $item->fresh()->price));
        $this->assertTrue($this->as($a, fn () => AuditLog::query()->where('action', 'item.price_changed')->exists()));
    }

    public function test_inline_price_is_validated_like_the_item_form(): void
    {
        $a = $this->restaurant('cafe-a');
        $item = $this->item($a, 'Latte', 65);
        $owner = $this->owner($a);

        foreach (['', 'abc', '-5', '100001'] as $bad) {
            $this->actingAs($owner)->postJson($this->dashUrl("/menu/items/{$item->id}/price"), ['price' => $bad])
                ->assertUnprocessable()->assertJsonValidationErrors('price');
        }
        $this->assertSame(65.0, $this->as($a, fn () => $item->fresh()->price));
    }

    public function test_inline_price_needs_menu_manage(): void
    {
        $a = $this->restaurant('cafe-a');
        $item = $this->item($a, 'Latte', 65);
        $kitchen = $this->staff($a, 'kitchen', 'k@a.test'); // may mark sold out, may not edit the menu

        $this->actingAs($kitchen)->postJson($this->dashUrl("/menu/items/{$item->id}/price"), ['price' => 10])->assertForbidden();
        $this->assertSame(65.0, $this->as($a, fn () => $item->fresh()->price));
    }

    public function test_inline_price_cannot_touch_another_restaurants_item(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $itemOfB = $this->item($b, 'Mocha', 70);

        $this->actingAs($this->owner($a))->postJson($this->dashUrl("/menu/items/{$itemOfB->id}/price"), ['price' => 1])->assertNotFound();
        $this->assertSame(70.0, $this->as($b, fn () => $itemOfB->fresh()->price));
    }

    public function test_save_and_add_another_opens_a_fresh_form_in_the_same_category(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $cat = $this->as($a, fn () => Category::query()->create(['name' => 'Desserts']));

        $this->actingAs($owner)->post($this->dashUrl('/menu/items'), ['category_id' => $cat->id, 'name' => 'Cake', 'price' => 40, 'then' => 'another'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dash.items.create', ['category' => $cat->id]))
            ->assertSessionHas('ok');

        $item = $this->as($a, fn () => MenuItem::query()->where('name', 'Cake')->firstOrFail());
        $this->actingAs($owner)->get($this->dashUrl("/menu/items/create?category={$cat->id}"))->assertOk()
            ->assertSee('data-flash-toast', false)
            ->assertSee('#item-'.$item->id, false)
            ->assertSee('<option value="'.$cat->id.'" selected', false);
    }

    public function test_plain_save_goes_back_to_the_menu_at_that_item(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $item = $this->item($a, 'Latte', 65);

        $this->actingAs($owner)->post($this->dashUrl("/menu/items/{$item->id}"), ['category_id' => $item->category_id, 'name' => 'Latte', 'price' => 70])
            ->assertRedirect(route('dash.menu'))->assertSessionHas('focus_item', $item->id);
        $this->actingAs($owner)->get($this->dashUrl('/menu'))->assertOk()
            ->assertSee('data-flash-toast', false)
            ->assertSee('id="item-'.$item->id.'"', false)
            ->assertSee('data-focus', false);
    }

    public function test_the_service_worker_check_does_not_use_up_the_saved_toast(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $item = $this->item($a, 'Latte', 65);

        $this->actingAs($owner)->post($this->dashUrl("/menu/items/{$item->id}"), ['category_id' => $item->category_id, 'name' => 'Latte', 'price' => 70])
            ->assertRedirect(route('dash.menu'));
        // the browser re-checks the service worker while it follows the redirect
        $this->get($this->dashUrl('/sw.js'))->assertOk();
        $this->actingAs($owner)->get($this->dashUrl('/menu'))->assertOk()->assertSee('data-flash-toast', false);
    }

    public function test_menu_page_has_the_more_menu_settings_panel_and_quick_tools(): void
    {
        $a = $this->restaurant('cafe-a');
        $item = $this->item($a, 'Latte', 65);
        $this->as($a, fn () => Category::query()->create(['name' => 'Tea']));

        $this->actingAs($this->owner($a))->get($this->dashUrl('/menu'))->assertOk()
            ->assertSee('data-menu-more', false)
            ->assertSee('data-dropdown-panel', false)
            ->assertSee('data-menu-settings', false)
            ->assertSee('data-sold-out-until', false)
            ->assertSee('data-category-chips', false)
            ->assertSee('data-menu-search', false)
            ->assertSee('data-price-edit="'.route('dash.items.price', $item).'"', false)
            ->assertSee('aria-pressed="false"', false);
    }

    public function test_item_form_has_the_sticky_save_bar_and_unsaved_guard(): void
    {
        $a = $this->restaurant('cafe-a');
        $item = $this->item($a, 'Latte', 65);
        $owner = $this->owner($a);

        $this->actingAs($owner)->get($this->dashUrl('/menu/items/create'))->assertOk()
            ->assertSee('data-item-save', false)->assertSee('data-unsaved-guard', false)->assertSee('data-save-another', false);
        $this->actingAs($owner)->get($this->dashUrl("/menu/items/{$item->id}"))->assertOk()
            ->assertSee('data-item-save', false)->assertDontSee('data-save-another', false);
    }
}
