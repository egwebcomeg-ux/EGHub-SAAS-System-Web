<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Plan;
use App\Services\Entitlements;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

class MenuEditorTest extends TenantTestCase
{
    public function test_owner_creates_an_item_with_sizes_extras_and_a_photo(): void
    {
        Storage::fake('public');
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $this->actingAs($owner)->post($this->dashUrl('/menu/categories'), ['name' => 'قهوة'])->assertSessionHasNoErrors();
        $cat = $this->as($a, fn () => Category::query()->first());

        $this->actingAs($owner)->post($this->dashUrl('/menu/items'), [
            'category_id' => $cat->id, 'name' => 'لاتيه', 'name_en' => 'Latte', 'price' => 65, 'is_active' => '1',
            'image' => UploadedFile::fake()->image('latte.jpg', 1600, 1200),
            'groups' => [
                ['name' => 'الحجم', 'type' => 'single', 'is_required' => '1', 'options' => [['name' => 'صغير', 'price' => 0], ['name' => 'كبير', 'price' => 15]]],
                ['name' => 'إضافات', 'type' => 'multiple', 'max_choices' => 2, 'options' => [['name' => 'شوت', 'price' => 10]]],
            ],
        ])->assertRedirect(route('dash.menu'))->assertSessionHasNoErrors();

        $item = $this->as($a, fn () => MenuItem::query()->with('optionGroups.options')->first());
        $this->assertSame('Latte', $item->name_en);
        $this->assertSame(['الحجم', 'إضافات'], $item->optionGroups->pluck('name')->all());
        $this->assertTrue($item->optionGroups[0]->is_required);
        $this->assertSame(15.0, $item->optionGroups[0]->options[1]->price);
        $this->assertStringStartsWith('tenants/cafe-a/items/', $item->image);
        $this->assertStringEndsWith('.webp', $item->image);
        Storage::disk('public')->assertExists($item->image);
        [$w] = getimagesizefromstring(Storage::disk('public')->get($item->image));
        $this->assertSame(900, $w); // resized

        // the public menu shows it with its options
        $this->get($this->menuUrl('cafe-a'))->assertOk()->assertSee('لاتيه')->assertSee('كبير', false);
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');
        $a = $this->restaurant('cafe-a');
        $cat = $this->as($a, fn () => Category::query()->create(['name' => 'X']));
        $this->actingAs($this->owner($a))->post($this->dashUrl('/menu/items'), [
            'category_id' => $cat->id, 'name' => 'Bad', 'price' => 1,
            'image' => UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;'),
        ])->assertSessionHasErrors('image');
        $this->assertSame(0, $this->as($a, fn () => MenuItem::query()->count()));
    }

    public function test_item_limit_of_the_plan(): void
    {
        $a = $this->restaurant('cafe-a', 'basic');
        Plan::query()->where('code', 'basic')->first()->features()->where('key', 'menu_items')->update(['value' => 1]);
        Entitlements::flush();
        $cat = $this->as($a, fn () => Category::query()->create(['name' => 'X']));
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl('/menu/items'), ['category_id' => $cat->id, 'name' => 'One', 'price' => 1])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($this->dashUrl('/menu/items'), ['category_id' => $cat->id, 'name' => 'Two', 'price' => 1])->assertSessionHasErrors('limit');
        $this->assertSame(1, $this->as($a, fn () => MenuItem::query()->count()));
    }

    public function test_category_of_another_restaurant_is_refused(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $catOfB = $this->as($b, fn () => Category::query()->create(['name' => 'B']));
        $this->actingAs($this->owner($a))->post($this->dashUrl('/menu/items'), ['category_id' => $catOfB->id, 'name' => 'X', 'price' => 1])
            ->assertSessionHasErrors('category_id');
    }

    public function test_kitchen_can_mark_sold_out_but_not_hide_or_edit(): void
    {
        $a = $this->restaurant('cafe-a');
        $item = $this->item($a);
        $kitchen = $this->staff($a, 'kitchen', 'k@a.test');

        $this->actingAs($kitchen)->postJson($this->dashUrl("/menu/items/{$item->id}/toggle"), ['field' => 'sold_out'])
            ->assertOk()->assertJsonPath('value', true);
        $this->actingAs($kitchen)->postJson($this->dashUrl("/menu/items/{$item->id}/toggle"), ['field' => 'active'])->assertForbidden();
        $this->actingAs($kitchen)->get($this->dashUrl('/menu'))->assertForbidden();
    }

    public function test_hidden_and_inactive_items_are_not_on_the_menu(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a, 'Shown');
        $hidden = $this->item($a, 'HiddenOne');
        $this->as($a, fn () => $hidden->update(['is_active' => false]));
        $this->get($this->menuUrl('cafe-a'))->assertSee('Shown')->assertDontSee('HiddenOne');
    }

    public function test_tables_range_limit_rotate_and_print(): void
    {
        $a = $this->restaurant('cafe-a', 'basic'); // 15 tables, 5 created at signup
        $owner = $this->owner($a);
        $branch = $this->as($a, fn () => Branch::query()->value('id'));

        $this->actingAs($owner)->post($this->dashUrl('/tables'), ['branch_id' => $branch, 'from' => 6, 'to' => 15])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($this->dashUrl('/tables'), ['branch_id' => $branch, 'from' => 16, 'to' => 16])->assertSessionHasErrors('limit');
        $this->assertSame(15, $this->as($a, fn () => DiningTable::query()->count()));

        $table = $this->as($a, fn () => DiningTable::query()->first());
        $old = $table->qr_token;
        $this->actingAs($owner)->post($this->dashUrl("/tables/{$table->id}/rotate"))->assertSessionHasNoErrors();
        $this->assertNotSame($old, $table->fresh()->qr_token);
        $this->get($this->menuUrl("cafe-a/t/$old"))->assertNotFound();

        $this->actingAs($owner)->get($this->dashUrl('/tables/print'))->assertOk()->assertSee('<svg', false);
    }

    public function test_settings_change_the_menu_look(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings'), [
            'brand_name' => 'Kahwa', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#112233',
            'color_secondary' => '#445566', 'color_bg' => '#FFFFFF', 'font' => 'Tajawal', 'default_locale' => 'ar',
            'english_enabled' => '1', 'ordering_enabled' => '1',
        ])->assertSessionHasNoErrors();

        $this->get($this->menuUrl('cafe-a'))->assertSee('Kahwa')->assertSee('#112233')->assertSee('Tajawal');
    }

    public function test_food_labels_are_saved_and_shown_on_the_menu(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $cat = $this->as($a, fn () => Category::query()->first());

        $this->actingAs($owner)->post($this->dashUrl('/menu/items'), [
            'category_id' => $cat->id, 'name' => 'Falafel', 'price' => 20, 'is_active' => '1', 'tags' => ['vegetarian', 'spicy'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['vegetarian', 'spicy'], $this->as($a, fn () => MenuItem::query()->first()->badges));
        $this->get($this->menuUrl('cafe-a'))->assertSee('🌱 نباتي')->assertSee('🌶 حار');

        $this->actingAs($owner)->post($this->dashUrl('/menu/items'), [
            'category_id' => $cat->id, 'name' => 'X', 'price' => 20, 'tags' => ['<script>'],
        ])->assertSessionHasErrors('tags.0');
    }
}
