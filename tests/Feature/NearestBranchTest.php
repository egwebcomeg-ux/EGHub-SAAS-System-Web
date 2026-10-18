<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchItem;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Tenant;
use App\Support\MapsLink;
use Tests\TenantTestCase;

/** The branch switcher of the menu: nearest branch data, remembered choice, fixed by a table QR, never for one branch. */
class NearestBranchTest extends TenantTestCase
{
    /** @return array<string, mixed> */
    private function menuData(string $html): array
    {
        preg_match('#<script type="application/json" id="menu-data">(.*?)</script>#s', $html, $m);

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function twoBranches(): Tenant
    {
        $a = $this->restaurant('cafe-a', 'business');
        $this->item($a, 'Latte', 60);
        $this->as($a, function () {
            Branch::query()->first()->update(['name' => 'Maadi', 'lat' => 29.9602, 'lng' => 31.2569, 'working_hours' => '24 hours', 'address' => 'Road 9']);
            Branch::query()->create(['name' => 'Zamalek', 'slug' => 'zamalek', 'lat' => 30.0609, 'lng' => 31.2197, 'maps_url' => 'https://maps.google.com/?q=30.0609,31.2197']);
        });

        return $a;
    }

    public function test_two_branches_give_the_switcher_with_coordinates_and_open_state(): void
    {
        $this->twoBranches();
        $res = $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee('data-branch-open', false)->assertSee('data-branch-sheet', false)->assertSee('data-branch-near', false)
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');

        $data = $this->menuData($res->getContent());
        $this->assertCount(2, $data['branches']);
        $this->assertSame(['Maadi', 'Zamalek'], array_column($data['branches'], 'name'));
        $this->assertSame(29.9602, $data['branches'][0]['lat']);
        $this->assertTrue($data['branches'][0]['open']);
        $this->assertSame('https://maps.google.com/?q=30.0609,31.2197', $data['branches'][1]['map']);
        $this->assertFalse($data['branchChosen']);
    }

    public function test_choosing_a_branch_switches_prices_and_is_remembered(): void
    {
        $a = $this->twoBranches();
        $this->as($a, function () {
            $zamalek = Branch::query()->where('slug', 'zamalek')->first();
            BranchItem::query()->create(['branch_id' => $zamalek->id, 'menu_item_id' => MenuItem::query()->first()->id, 'price' => 75]);
        });

        $res = $this->get($this->subUrl('cafe-a').'?branch=zamalek')->assertOk()->assertCookie('branch_cafe-a', 'zamalek');
        $data = $this->menuData($res->getContent());
        $this->assertTrue($data['branchChosen']);
        $this->assertSame(75.0, (float) array_values($data['items'])[0]['price']);

        // the next visit without ?branch= opens the remembered branch
        $again = $this->withCookie('branch_cafe-a', 'zamalek')->get($this->subUrl('cafe-a'))->assertOk();
        $this->assertSame(75.0, (float) array_values($this->menuData($again->getContent())['items'])[0]['price']);
    }

    public function test_a_table_qr_fixes_the_branch_and_one_branch_never_shows_the_switcher(): void
    {
        $a = $this->twoBranches();
        $table = $this->as($a, fn () => DiningTable::query()->first());
        $this->get($this->subUrl('cafe-a', "t/{$table->qr_token}"))->assertRedirect();
        $res = $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-branch-sheet', false)->assertSee('data-table-chip', false);
        $this->assertSame([], $this->menuData($res->getContent())['branches']);

        $this->restaurant('solo', 'business');
        $solo = $this->get($this->subUrl('solo'))->assertOk()->assertDontSee('data-branch-open', false)
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $this->assertSame([], $this->menuData($solo->getContent())['branches']);
    }

    public function test_the_branch_form_takes_the_coordinates_from_a_maps_link(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $branch = $this->as($a, fn () => Branch::query()->first());
        $this->actingAs($this->owner($a))->post($this->dashUrl("/branches/{$branch->id}"), [
            'name' => 'Main', 'maps_url' => 'https://www.google.com/maps/place/Cafe/@30.0444196,31.2357116,17z/data=!3m1', 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $branch->refresh();
        $this->assertSame(30.0444196, $branch->lat);
        $this->assertSame(31.2357116, $branch->lng);

        // typed coordinates win; a wrong one is refused
        $this->actingAs($this->owner($a))->post($this->dashUrl("/branches/{$branch->id}"), ['name' => 'Main', 'lat' => '29.5', 'lng' => '31.1', 'is_active' => 1]);
        $this->assertSame(29.5, $branch->fresh()->lat);
        $this->actingAs($this->owner($a))->post($this->dashUrl("/branches/{$branch->id}"), ['name' => 'Main', 'lat' => '120', 'lng' => '31.1', 'is_active' => 1])
            ->assertSessionHasErrors('lat');

        $this->assertSame(['lat' => 30.1, 'lng' => 31.2], MapsLink::coordinates('https://www.google.com/maps/place/x/data=!4m5!3d30.1!4d31.2'));
        $this->assertNull(MapsLink::coordinates('https://maps.app.goo.gl/AbCdEf'));
        $this->assertEqualsWithDelta(11.4, MapsLink::distanceKm(29.9602, 31.2569, 30.0609, 31.2197), 0.5);
    }
}
