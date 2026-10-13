<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchItem;
use Tests\TenantTestCase;

class BranchMenuTest extends TenantTestCase
{
    public function test_branch_prices_availability_and_cleanup(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $tea = $this->item($a, 'Tea', 20);
        $cake = $this->item($a, 'Cake', 80);
        $branch = $this->as($a, fn () => Branch::query()->first());
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl("/branches/{$branch->id}/menu"), ['items' => [
            $tea->id => ['price' => 25, 'available' => 1],
            $cake->id => ['price' => '', 'available' => 1, 'sold_out' => 1],
        ]])->assertSessionHasNoErrors();
        $this->get($this->menuUrl('cafe-a'))->assertSee('25')->assertSee('Cake');
        $this->assertSame(2, $this->as($a, fn () => BranchItem::query()->count()));

        // back to "same as the main menu": the override rows are removed
        $this->actingAs($owner)->post($this->dashUrl("/branches/{$branch->id}/menu"), ['items' => [
            $tea->id => ['price' => '', 'available' => 1], $cake->id => ['available' => 1],
        ]]);
        $this->assertSame(0, $this->as($a, fn () => BranchItem::query()->count()));
    }

    public function test_items_of_another_restaurant_are_ignored_and_pro_has_no_access(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $b = $this->restaurant('cafe-b', 'business');
        $foreign = $this->item($b, 'Foreign', 10);
        $branch = $this->as($a, fn () => Branch::query()->first());
        $this->actingAs($this->owner($a))->post($this->dashUrl("/branches/{$branch->id}/menu"), ['items' => [$foreign->id => ['price' => 1]]]);
        $this->assertSame(0, BranchItem::query()->withoutGlobalScopes()->count());

        $pro = $this->restaurant('cafe-pro', 'pro');
        $proBranch = $this->as($pro, fn () => Branch::query()->first());
        $this->actingAs($this->owner($pro))->get($this->dashUrl("/branches/{$proBranch->id}/menu"))->assertForbidden();
    }
}
