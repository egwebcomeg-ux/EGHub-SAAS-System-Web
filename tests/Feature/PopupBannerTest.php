<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Tenant;
use App\Services\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/** The menu's popup is a banner marked "show as soon as the menu opens": one at a time, after the first-open sheet. */
class PopupBannerTest extends TenantTestCase
{
    /** @return array<string, mixed> */
    private function menuData(TestResponse $response): array
    {
        preg_match('#<script type="application/json" id="menu-data">(.*?)</script>#s', $response->getContent(), $m);

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function banner(Tenant $tenant, array $attrs = []): Banner
    {
        return $this->as($tenant, fn () => Banner::query()->create($attrs + ['image' => 'tenants/'.$tenant->id.'/banners/x.webp']));
    }

    public function test_a_banner_marked_as_popup_opens_over_the_menu_and_only_one_is_on(): void
    {
        Storage::fake('public');
        $a = $this->restaurant('cafe-a', 'pro');
        $owner = $this->owner($a);
        $cake = $this->item($a, 'Cake', 90);

        $this->actingAs($owner)->post($this->dashUrl('/marketing/banners'), [
            'image' => UploadedFile::fake()->image('p.jpg', 1600, 700), 'title' => 'First popup', 'link_type' => 'none', 'is_popup' => '1',
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($this->dashUrl('/marketing/banners'), [
            'image' => UploadedFile::fake()->image('p.jpg', 1600, 700), 'title' => 'Try our cake', 'link_type' => 'item',
            'menu_item_id' => $cake->id, 'is_popup' => '1', 'popup_frequency' => 'day',
        ])->assertSessionHasNoErrors();

        [$first, $second] = $this->as($a, fn () => Banner::query()->orderBy('id')->get()->all());
        $this->assertTrue($first->is_popup && $second->is_popup);
        $this->assertFalse($first->is_active);   // the new popup replaced it
        $this->assertTrue($second->is_active);
        $this->assertSame('day', $second->popup_frequency);
        $this->assertSame('session', $first->popup_frequency); // once per visit unless set otherwise

        // on the menu: the popup dialog, not a slide of the strip
        $menu = $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee('data-popup="'.$second->id.'"', false)->assertSee('Try our cake')
            ->assertDontSee('data-banners', false)->assertDontSee('First popup');
        $popup = $this->menuData($menu)['popup'];
        $this->assertSame(['id' => $second->id, 'frequency' => 'day', 'item' => $cake->id], array_intersect_key($popup, array_flip(['id', 'frequency', 'item'])));
        $this->assertStringStartsWith('/storage/tenants/cafe-a/banners/', $popup['image']);

        // switching the first one back on pauses the second
        $this->actingAs($owner)->post($this->dashUrl("/marketing/banners/{$first->id}/toggle"))->assertSessionHasNoErrors();
        $this->assertSame([true, false], $this->as($a, fn () => [$first->fresh()->is_active, $second->fresh()->is_active]));

        // the owner sees it with the banners, marked, with its counters
        $this->actingAs($owner)->get($this->dashUrl('/marketing'))->assertOk()
            ->assertSee('data-banner-popup', false)->assertSee(__('Shows as soon as the menu opens'))->assertSee('name="is_popup"', false);
    }

    public function test_the_popup_does_not_count_towards_the_strip_limit_and_is_counted(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $owner = $this->owner($a);
        $this->item($a);
        foreach (range(1, Banner::MAX_ACTIVE) as $n) {
            $this->banner($a, ['title' => "Slide $n"]);
        }
        $this->actingAs($owner)->post($this->dashUrl('/marketing/banners'), [
            'image' => UploadedFile::fake()->image('p.jpg', 1600, 700), 'link_type' => 'none', 'is_popup' => '1',
        ])->assertSessionHasNoErrors();
        $popup = $this->as($a, fn () => Banner::query()->where('is_popup', true)->firstOrFail());

        $this->postJson($this->subUrl('cafe-a', "popups/{$popup->id}/view"))->assertOk();
        $this->postJson($this->subUrl('cafe-a', "popups/{$popup->id}/click"))->assertOk();
        $this->assertSame([1, 1], [$popup->fresh()->views, $popup->fresh()->clicks]);
        // a slide of the strip is not a popup: nothing is counted
        $slide = $this->as($a, fn () => Banner::query()->where('is_popup', false)->firstOrFail());
        $this->postJson($this->subUrl('cafe-a', "popups/{$slide->id}/view"))->assertOk();
        $this->assertSame(0, $slide->fresh()->views);
    }

    public function test_the_popup_waits_for_the_first_open_sheet_and_never_stacks_on_it(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');   // hall + takeaway: the first-open sheet is there
        $this->item($a);
        $this->banner($a, ['title' => 'Hello', 'is_popup' => true]);

        $general = $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee('data-first-sheet', false)->assertSee('data-popup-after="first-sheet"', false);
        $this->assertTrue($this->menuData($general)['popup']['afterFirstSheet']);

        // a scanned table has no first-open sheet: the popup does not wait for one
        $table = $this->as($a, fn () => DiningTable::query()->firstOrFail());
        $this->get($this->subUrl('cafe-a', "t/{$table->qr_token}"))->assertRedirect();
        $atTable = $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-popup=', false)
            ->assertDontSee('data-first-sheet', false)->assertDontSee('data-popup-after', false);
        $this->assertFalse($this->menuData($atTable)['popup']['afterFirstSheet']);
    }

    public function test_old_popups_become_popup_banners_once_and_the_old_editor_points_to_the_banners(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $cake = $this->item($a, 'Cake', 90);
        $branch = $this->as($a, fn () => Branch::query()->firstOrFail());
        $row = fn (array $attrs) => $attrs + ['tenant_id' => 'cafe-a', 'type' => 'image', 'title' => '', 'title_en' => '', 'button_text' => '',
            'frequency' => 'session', 'delay_seconds' => 2, 'views' => 0, 'clicks' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
        DB::table('popups')->insert($row(['type' => 'item', 'menu_item_id' => $cake->id, 'title' => 'Try our cake', 'frequency' => 'always', 'views' => 7, 'clicks' => 2]));
        DB::table('popups')->insert($row(['image' => 'tenants/cafe-a/covers/old.webp', 'body' => 'Free dessert with every order this week', 'link_url' => 'https://example.com/x', 'branch_id' => $branch->id]));
        DB::table('popups')->insert($row(['title' => 'Paused one', 'image' => 'tenants/cafe-a/covers/p.webp', 'is_active' => false]));

        $migration = require database_path('migrations/2026_10_22_000150_popups_become_banners.php');
        $migration->up();
        $migration->up(); // running it again copies nothing twice

        $banners = $this->as($a, fn () => Banner::query()->with('item')->where('is_popup', true)->orderBy('popup_id')->get());
        $this->assertCount(3, $banners);
        [$item, $picture, $paused] = $banners->all();
        $this->assertSame([$cake->id, 'always', 7, 2, ''], [$item->menu_item_id, $item->popup_frequency, $item->views, $item->clicks, $item->image]);
        $this->assertSame('Free dessert with every order this week', $picture->title);   // the text, when there was no title
        $this->assertSame('https://example.com/x', $picture->link_url);
        $this->assertSame('tenants/cafe-a/covers/old.webp', $picture->image);
        // one popup at a time: only the newest switched-on one stays on
        $this->assertSame([false, true, false], [$item->is_active, $picture->is_active, $paused->is_active]);
        // the old data is still there
        $this->assertSame(3, DB::table('popups')->where('tenant_id', 'cafe-a')->count());

        // the old editor's addresses lead to the announcements on the offers page
        $owner = $this->owner($a);
        $this->actingAs($owner)->get($this->dashUrl('/marketing/popups'))->assertRedirect($this->dashUrl('/marketing').'#announcements');
        $this->actingAs($owner)->post($this->dashUrl('/marketing/popups'), ['type' => 'image'])->assertRedirect($this->dashUrl('/marketing').'#announcements');
        $this->actingAs($owner)->get($this->dashUrl('/marketing'))->assertOk()->assertDontSee('dash.popups.store')->assertDontSee(__('New popup'));
    }

    public function test_an_item_popup_without_its_own_picture_shows_the_items_photo(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $cake = $this->item($a, 'Cake', 90);
        $this->as($a, fn () => $cake->update(['image' => 'tenants/cafe-a/items/cake.webp']));
        $this->banner($a, ['image' => '', 'title' => 'Try our cake', 'menu_item_id' => $cake->id, 'is_popup' => true]);
        $this->as($a, fn () => Settings::put(['order_type_prompt' => '0']));

        $popup = $this->menuData($this->get($this->subUrl('cafe-a'))->assertOk())['popup'];
        $this->assertSame('/storage/tenants/cafe-a/items/cake.webp', $popup['image']);
        $this->assertSame($cake->id, $popup['item']);
    }
}
