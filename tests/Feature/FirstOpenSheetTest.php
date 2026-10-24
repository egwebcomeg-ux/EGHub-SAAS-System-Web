<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Tenant;
use App\Services\Settings;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * One first-open sheet after the intro: how the guest orders on top and, with 2+ branches and no table QR, the branch
 * list in the same sheet, one step (a tap on the order type continues). A table QR skips it; one branch never shows
 * the branch part.
 */
class FirstOpenSheetTest extends TenantTestCase
{
    private function twoBranches(): Tenant
    {
        $a = $this->restaurant('cafe-a', 'business');
        $this->item($a, 'Latte', 60);
        $this->as($a, function () {
            Branch::query()->first()->update(['name' => 'Maadi', 'lat' => 29.9602, 'lng' => 31.2569]);
            Branch::query()->create(['name' => 'Zamalek', 'slug' => 'zamalek', 'lat' => 30.0609, 'lng' => 31.2197]);
        });

        return $a;
    }

    public function test_branch_and_order_type_share_one_sheet_and_a_tap_on_the_type_continues(): void
    {
        $this->twoBranches();
        $html = $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee(['هتطلب إزاي؟', 'من أنهي فرع؟', 'الأقرب ليا', 'اختار وورّي الويتر'])
            ->assertSee('data-way="dine"', false)->assertSee('data-branch-pick="zamalek"', false)
            // one step: no separate "Let's order" button
            ->assertDontSee('data-first-confirm', false)->assertDontSee('يلا نطلب')
            // the header chips reopen the same sheet
            ->assertSee('data-way-chip', false)->assertSee('data-branch-open', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'data-order-choice'));
        $this->assertSame(1, substr_count($html, 'data-branch-sheet'));
        $this->assertStringNotContainsString('<dialog class="m-0 mt-auto w-full max-w-none bg-transparent p-0 backdrop:bg-black/50 sm:m-auto sm:max-w-lg" data-branch-sheet', $html);
        // the branch is chosen first, then the order type cards
        $sheet = substr($html, strpos($html, 'data-order-choice'));
        $this->assertTrue(strpos($sheet, 'data-branch-sheet') < strpos($sheet, 'data-way="dine"'));
        // two branches: no search box (it comes from 5)
        $this->assertStringNotContainsString('data-branch-search', $sheet);
        // a first visit with no branch chosen opens it before the first paint (once: the browser remembers the branch)
        $this->assertStringContainsString('egmenu.branch.cafe-a', $html);
        $this->assertSame(1, substr_count($html, "root.classList.add('choice-open')"));
        // reduced motion: the sheet does not slide in
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce) { .m-choice-card { animation: none; } }', $html);
    }

    public function test_many_branches_get_a_search_and_the_nearest_button(): void
    {
        $a = $this->restaurant('chain', 'full');
        $this->as($a, function () {
            Branch::query()->first()->update(['name' => 'Maadi', 'lat' => 29.9602, 'lng' => 31.2569]);
            foreach (['Zamalek', 'Heliopolis', 'Dokki', 'Nasr City'] as $name) {
                Branch::query()->create(['name' => $name, 'slug' => Str::slug($name)]);
            }
        });

        $this->get($this->subUrl('chain'))->assertOk()
            ->assertSee('data-branch-search', false)->assertSee('دوّر على الفرع')
            ->assertSee('data-branch-near', false)->assertSee('data-branch-pick="nasr-city"', false);
    }

    public function test_a_bare_logo_on_a_light_page_gets_the_light_intro(): void
    {
        $a = $this->restaurant('dukes');
        $this->as($a, fn () => Settings::put(['logo' => 'tenants/dukes/brand/logo.webp', 'logo_background' => 'none',
            'color_primary' => '#9F2241', 'color_secondary' => '#72253D', 'color_bg' => '#FDFBE6']));
        $this->get($this->subUrl('dukes'))->assertOk()->assertSee('data-intro-background="light"', false)->assertSee('m-intro--light', false);

        $this->as($a, fn () => Settings::put(['intro_background' => 'brand']));
        $this->get($this->subUrl('dukes'))->assertOk()->assertSee('data-intro-background="brand"', false);

        // the owner picks it in the settings (menu tab)
        $this->actingAs($this->owner($a))->get($this->dashUrl('/settings?tab=menu'))->assertOk()->assertSee('name="intro_background"', false);
    }

    public function test_a_table_qr_skips_the_sheet(): void
    {
        $a = $this->twoBranches();
        $table = $this->as($a, fn () => DiningTable::query()->firstOrFail());
        $this->get($this->subUrl('cafe-a', "t/{$table->qr_token}"))->assertRedirect();

        $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertDontSee('data-order-choice', false)->assertDontSee('data-branch-sheet', false)
            ->assertDontSee('data-first-confirm', false)->assertSee('data-table-chip', false);
    }

    public function test_one_branch_never_shows_the_branch_part(): void
    {
        $this->restaurant('solo', 'business');
        $this->get($this->subUrl('solo'))->assertOk()
            ->assertSee('data-first-sheet', false)->assertSee('data-way="takeaway"', false)
            ->assertDontSee('data-branch-sheet', false)->assertDontSee('من أنهي فرع؟')->assertDontSee('data-branch-open', false)
            ->assertDontSee('egmenu.branch.solo', false);
    }

    public function test_a_single_branch_never_opens_the_sheet_by_itself_and_nothing_is_picked_for_the_guest(): void
    {
        $a = $this->restaurant('solo', 'business');
        $this->item($a, 'Kofta', 150);
        $this->as($a, fn () => Settings::put(['order_type_prompt' => '1', 'online_delivery' => '1']));

        // the hall, takeaway and the setting on: the guest still lands on the menu
        $this->get($this->subUrl('solo'))->assertOk()
            ->assertSee('data-first-sheet', false)->assertDontSee("root.classList.add('choice-open')", false)
            // the header chip asks instead of claiming a way the guest never chose; the cart asks above its switch
            ->assertSee('<span data-way-chip-label>هتطلب إزاي؟</span>', false)->assertSee('data-way-ask', false);
    }

    public function test_many_branches_keep_the_way_tiles_on_screen_and_show_the_name_only_without_a_logo(): void
    {
        $a = $this->restaurant('chain', 'full');
        $this->as($a, function () {
            foreach (['Zamalek', 'Heliopolis', 'Dokki', 'Nasr City', 'Maadi 2', 'Shoubra'] as $name) {
                Branch::query()->create(['name' => $name, 'slug' => Str::slug($name)]);
            }
        });

        $sheet = $this->get($this->subUrl('chain'))->assertOk()->getContent();
        $sheet = substr($sheet, strpos($sheet, 'data-order-choice'));
        // the ways sit after the branch list as a row of tiles (the list scrolls on its own, CSS in intro-head)
        $this->assertStringContainsString('class="m-way-grid" style="--ways: 2"', $sheet);
        $this->assertStringContainsString('class="m-choice-brand truncate"', $sheet);

        $this->as($a, fn () => Settings::put(['logo' => 'tenants/chain/brand/logo.webp']));
        $sheet = $this->get($this->subUrl('chain'))->assertOk()->getContent();
        $this->assertStringContainsString('class="m-choice-brand truncate sr-only"', $sheet);
    }

    public function test_with_one_way_of_ordering_the_sheet_is_only_the_branch_list(): void
    {
        $a = $this->twoBranches();
        $this->as($a, fn () => Settings::put(['online_takeaway' => '0', 'online_delivery' => '0']));

        $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee('data-first-sheet', false)->assertSee('data-branch-sheet', false)->assertSee('m-choice-card--closable', false)
            ->assertDontSee('data-way-pick', false)->assertSee('اختار الفرع');

        // a chosen branch (remembered) does not open it by itself again
        $this->withCookie('branch_cafe-a', 'zamalek')->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee('data-branch-sheet', false)->assertDontSee("root.classList.add('choice-open')", false);
    }
}
