<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Tenant;
use App\Services\Settings;
use Tests\TenantTestCase;

/**
 * One first-open sheet after the intro: how the guest orders on top and, with 2+ branches and no table QR, the branch
 * list in the same sheet, one "Let's order". A table QR skips it; one branch never shows the branch part.
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

    public function test_order_type_and_branch_share_one_sheet_with_one_confirm(): void
    {
        $this->twoBranches();
        $html = $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee(['هتطلب إزاي؟', 'من أنهي فرع؟', 'يلا نطلب', 'أقرب فرع ليا'])
            ->assertSee('data-way="dine"', false)->assertSee('data-branch-pick="zamalek"', false)
            ->assertSee('data-first-confirm', false)
            // the header chips reopen the same sheet
            ->assertSee('data-way-chip', false)->assertSee('data-branch-open', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'data-order-choice'));
        $this->assertSame(1, substr_count($html, 'data-branch-sheet'));
        $this->assertStringNotContainsString('<dialog class="m-0 mt-auto w-full max-w-none bg-transparent p-0 backdrop:bg-black/50 sm:m-auto sm:max-w-lg" data-branch-sheet', $html);
        // the branch part sits inside the sheet, after the order type and before the confirm
        $sheet = substr($html, strpos($html, 'data-order-choice'));
        $this->assertTrue(strpos($sheet, 'data-way="dine"') < strpos($sheet, 'data-branch-sheet'));
        $this->assertTrue(strpos($sheet, 'data-branch-sheet') < strpos($sheet, 'data-first-confirm'));
        // a first visit with no branch chosen opens it before the first paint (once: the browser remembers the branch)
        $this->assertStringContainsString('egmenu.branch.cafe-a', $html);
        $this->assertSame(1, substr_count($html, "root.classList.add('choice-open')"));
        // reduced motion: the sheet does not slide in
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce) { .m-choice-card { animation: none; } }', $html);
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
