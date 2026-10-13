<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Models\Tenant;
use Tests\TenantTestCase;

class DeliveryZonesTest extends TenantTestCase
{
    private function withCallCenter(string $slug): Tenant
    {
        $tenant = $this->restaurant($slug, 'basic');
        app(Billing::class)->setAddon($tenant, 'callcenter_pack', 1);
        return $tenant;
    }

    public function test_zones_and_sub_areas_inherit_empty_values(): void
    {
        $a = $this->withCallCenter('cafe-a');
        $branch = $this->as($a, fn () => Branch::query()->first());
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl('/delivery-zones'), [
            'branch_id' => $branch->id, 'name' => 'Nasr City', 'fee' => 30, 'min_order' => 150, 'estimated_minutes' => 40,
        ])->assertSessionHasNoErrors();
        $main = $this->as($a, fn () => DeliveryZone::query()->first());
        $this->actingAs($owner)->post($this->dashUrl('/delivery-zones'), [
            'branch_id' => $branch->id, 'parent_id' => $main->id, 'name' => 'District 7', 'fee' => '',
        ])->assertSessionHasNoErrors();

        $sub = $this->as($a, fn () => DeliveryZone::query()->where('name', 'District 7')->with('parent')->first());
        $this->assertSame(30.0, $sub->effective('fee'));
        $this->assertSame(40, (int) $sub->effective('estimated_minutes'));
        $this->actingAs($owner)->get($this->dashUrl('/delivery-zones'))->assertOk()->assertSee('District 7');
    }

    public function test_foreign_branch_or_zone_rejected_and_no_access_without_pack(): void
    {
        $a = $this->withCallCenter('cafe-a');
        $b = $this->withCallCenter('cafe-b');
        $foreignBranch = $this->as($b, fn () => Branch::query()->first());
        $foreignZone = $this->as($b, fn () => DeliveryZone::query()->create(['branch_id' => $foreignBranch->id, 'name' => 'B zone']));

        $this->actingAs($this->owner($a))->post($this->dashUrl('/delivery-zones'), ['branch_id' => $foreignBranch->id, 'name' => 'X'])
            ->assertSessionHasErrors('branch_id');
        $ownBranch = $this->as($a, fn () => Branch::query()->first());
        $this->actingAs($this->owner($a))->post($this->dashUrl('/delivery-zones'), ['branch_id' => $ownBranch->id, 'parent_id' => $foreignZone->id, 'name' => 'X'])
            ->assertSessionHasErrors('parent_id');
        $this->actingAs($this->owner($a))->post($this->dashUrl("/delivery-zones/{$foreignZone->id}/delete"))->assertNotFound();
        $this->assertSame(1, DeliveryZone::query()->withoutGlobalScopes()->count());

        $plain = $this->restaurant('cafe-plain', 'pro');
        $this->actingAs($this->owner($plain))->get($this->dashUrl('/delivery-zones'))->assertForbidden();
    }
}
