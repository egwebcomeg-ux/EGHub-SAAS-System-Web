<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use Tests\TenantTestCase;

/** Allergen flags and the spicy level: saved from the item form, shown to guests and on the printed menu. */
class AllergensTest extends TenantTestCase
{
    public function test_saved_from_the_form_and_shown_on_the_menu_and_the_printed_menu(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $item = $this->item($a, 'Shrimp pasta', 180);

        $this->actingAs($owner)->get($this->dashUrl("/menu/items/{$item->id}"))->assertOk()->assertSee('name="allergens[]"', false)->assertSee('name="spicy_level"', false);

        $this->actingAs($owner)->post($this->dashUrl("/menu/items/{$item->id}"), [
            'category_id' => $item->category_id, 'name' => 'Shrimp pasta', 'price' => 180, 'is_active' => 1,
            'allergens' => ['shellfish', 'gluten', 'dairy', 'shellfish'], 'spicy_level' => 2,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $saved = $this->as($a, fn () => MenuItem::query()->findOrFail($item->id));
        $this->assertSame(['shellfish', 'gluten', 'dairy'], $saved->allergens);
        $this->assertSame(2, $saved->spicy_level);
        $this->assertSame('🌶🌶', $saved->spicyIcons());
        $this->assertSame(['shellfish', 'gluten', 'dairy'], array_column($saved->allergenList(), 'key'));

        // the guest's item sheet gets the list (JSON data of the page) and the card shows the peppers
        $res = $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('🌶🌶');
        $data = json_decode(str_replace('<', '<', preg_match('/<script type="application\/json" id="menu-data">(.*?)<\/script>/s', $res->getContent(), $m) ? $m[1] : '{}'), true);
        $this->assertSame('🌶🌶', $data['items'][$item->id]['spicy']);
        $this->assertSame(['shellfish', 'gluten', 'dairy'], array_column($data['items'][$item->id]['allergens'], 'key'));
        $this->assertSame('قشريات بحرية', $data['items'][$item->id]['allergens'][0]['label']);

        $this->actingAs($owner)->get($this->dashUrl('/menu/print'))->assertOk()->assertSee('🦐 قشريات بحرية');
    }

    public function test_unknown_keys_and_levels_are_refused(): void
    {
        $a = $this->restaurant('cafe-a');
        $item = $this->item($a);
        $base = ['category_id' => $item->category_id, 'name' => 'Latte', 'price' => 60, 'is_active' => 1];

        $this->actingAs($this->owner($a))->post($this->dashUrl("/menu/items/{$item->id}"), $base + ['allergens' => ['plutonium']])->assertSessionHasErrors('allergens.0');
        $this->actingAs($this->owner($a))->post($this->dashUrl("/menu/items/{$item->id}"), $base + ['spicy_level' => 7])->assertSessionHasErrors('spicy_level');

        $this->assertSame(['gluten', 'dairy', 'eggs'], MenuItem::allergenKeys('Gluten, ألبان | eggs ; unknown'));
    }
}
