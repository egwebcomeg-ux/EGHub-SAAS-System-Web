<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** Menu banners: managed from the offers page, shown as a strip under the menu header, at most 5 live. */
class BannersTest extends TenantTestCase
{
    private function banner(Tenant $tenant, array $attrs = []): Banner
    {
        return $this->as($tenant, fn () => Banner::query()->create($attrs + ['image' => 'tenants/'.$tenant->id.'/banners/x.webp']));
    }

    public function test_owner_adds_a_banner_linked_to_an_item_and_guests_see_the_strip(): void
    {
        Storage::fake('public');
        $a = $this->restaurant('cafe-a', 'pro');
        $owner = $this->owner($a);
        $item = $this->item($a, 'Latte', 60);

        $this->actingAs($owner)->get($this->dashUrl('/marketing'))->assertOk()->assertSee(__('Banners & announcements'))->assertSee('id="announcements"', false);
        $this->actingAs($owner)->post($this->dashUrl('/marketing/banners'), [
            'image' => UploadedFile::fake()->image('b.jpg', 1600, 700), 'title' => 'لاتيه بنص السعر', 'link_type' => 'item', 'menu_item_id' => $item->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $banner = $this->as($a, fn () => Banner::query()->firstOrFail());
        $this->assertSame($item->id, $banner->menu_item_id);
        $this->assertStringStartsWith('tenants/cafe-a/banners/', $banner->image);
        $this->assertStringEndsWith('.webp', $banner->image);
        Storage::disk('public')->assertExists($banner->image);

        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-banners', false)
            ->assertSee('data-item="'.$item->id.'" data-banner="'.$banner->id.'"', false)->assertSee('لاتيه بنص السعر')->assertSee('loading="eager"', false);

        // paused: gone from the menu
        $this->actingAs($owner)->post($this->dashUrl("/marketing/banners/{$banner->id}/toggle"))->assertRedirect();
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-banners', false);

        $this->actingAs($owner)->post($this->dashUrl("/marketing/banners/{$banner->id}/delete"))->assertRedirect();
        $this->assertSame(0, $this->as($a, fn () => Banner::query()->count()));
        Storage::disk('public')->assertMissing($banner->image);
    }

    public function test_links_schedule_and_the_limit_of_five(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $owner = $this->owner($a);
        $this->item($a, 'Latte', 60);
        $category = $this->as($a, fn () => Category::query()->where('name', 'Coffee')->firstOrFail()); // the one with the Latte
        $hidden = $this->as($a, fn () => Category::query()->create(['name' => 'Hidden', 'is_active' => false]));

        $this->banner($a, ['title' => 'Category link', 'category_id' => $category->id]);
        $this->banner($a, ['title' => 'Hidden category', 'category_id' => $hidden->id]);
        $this->banner($a, ['title' => 'Web link', 'link_url' => 'https://example.com/promo']);
        $this->banner($a, ['title' => 'Future', 'starts_on' => now()->addDays(3)->toDateString()]);
        $this->banner($a, ['title' => 'Expired', 'ends_on' => now()->subDay()->toDateString()]);

        $res = $this->get($this->subUrl('cafe-a'))->assertOk();
        $res->assertSee('href="#cat-'.$category->id.'"', false)->assertSee('Category link');
        $res->assertSee('Hidden category')->assertDontSee('href="#cat-'.$hidden->id.'"', false); // no link to a category not on the menu
        $res->assertSee('href="https://example.com/promo"', false);
        $res->assertDontSee('Future')->assertDontSee('Expired');

        // at most five live: 4 live now (incl. the future one) + 1 more is fine, the sixth is refused, so is resuming a paused one
        $this->banner($a, ['title' => 'Fifth']);
        $paused = $this->banner($a, ['title' => 'Paused', 'is_active' => false]);
        $this->actingAs($owner)->post($this->dashUrl('/marketing/banners'), [
            'image' => UploadedFile::fake()->image('b.jpg', 1600, 700), 'link_type' => 'none',
        ])->assertSessionHasErrors('banners');
        $this->actingAs($owner)->post($this->dashUrl("/marketing/banners/{$paused->id}/toggle"))->assertSessionHasErrors('banners');
        $this->assertFalse($this->as($a, fn () => Banner::query()->findOrFail($paused->id)->is_active));

        // a link needs its target
        $this->actingAs($owner)->post($this->dashUrl('/marketing/banners'), [
            'image' => UploadedFile::fake()->image('b.jpg', 1600, 700), 'link_type' => 'url',
        ])->assertSessionHasErrors('link_url');
    }

    public function test_its_dates_are_the_restaurants_days(): void
    {
        $banner = new Banner(['is_active' => true, 'ends_on' => '2026-10-16']);
        $this->travelTo(Carbon::parse('2026-10-16 23:30', 'Africa/Cairo'));
        $this->assertTrue($banner->isLive());
        $this->travelTo(Carbon::parse('2026-10-17 00:30', 'Africa/Cairo')); // still the 16th in UTC
        $this->assertFalse($banner->isLive());
    }

    public function test_isolation_and_feature_gate(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $b = $this->restaurant('cafe-b', 'pro');
        $this->item($a);
        $this->item($b);
        $banner = $this->banner($a, ['title' => 'Only in A']);

        $this->get($this->subUrl('cafe-b'))->assertOk()->assertDontSee('Only in A');
        $this->actingAs($this->owner($b))->post($this->dashUrl("/marketing/banners/{$banner->id}/delete"))->assertNotFound();
        $this->assertSame(1, $this->as($a, fn () => Banner::query()->count()));

        // Basic has no offers feature: the strip is not shown and the page is locked
        $basic = $this->restaurant('cafe-basic', 'basic');
        $this->item($basic);
        $this->banner($basic, ['title' => 'Basic banner']);
        $this->get($this->subUrl('cafe-basic'))->assertOk()->assertDontSee('Basic banner');
        $this->actingAs($this->owner($basic))->post($this->dashUrl('/marketing/banners'), ['link_type' => 'none'])->assertStatus(403);
    }
}
