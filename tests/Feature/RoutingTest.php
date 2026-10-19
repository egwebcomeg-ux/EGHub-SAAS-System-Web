<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Tenant;
use Tests\TenantTestCase;

/** egmenu.app/{slug} menus, reserved paths, suspension, table QR and language. */
class RoutingTest extends TenantTestCase
{
    public function test_landing_lists_plans_from_the_database(): void
    {
        $this->get($this->menuUrl())->assertOk()->assertSee(['1,250', '12,000'])->assertSee('أسئلة شائعة');
        $this->get($this->menuUrl('terms'))->assertOk()->assertSee('EgWeb');
        $this->get($this->menuUrl('privacy?lang=en'))->assertOk()->assertSee('Personal Data Protection Law');
    }

    public function test_unknown_and_reserved_slugs_are_404(): void
    {
        $this->get($this->menuUrl('no-such-cafe'))->assertNotFound();
        $this->get($this->menuUrl('dashboard'))->assertNotFound();
    }

    public function test_slug_rules(): void
    {
        $this->restaurant('taken-cafe');
        $this->assertNotNull(Tenant::slugError('ab'));
        $this->assertNotNull(Tenant::slugError('Bad_Slug'));
        $this->assertNotNull(Tenant::slugError('login'));
        $this->assertNotNull(Tenant::slugError('taken-cafe'));
        $this->assertNull(Tenant::slugError('new-cafe'));
    }

    public function test_suspended_restaurant_menu_is_offline_but_dashboard_works(): void
    {
        $a = $this->restaurant('cafe-a');
        $a->update(['status' => 'suspended']);

        $this->get($this->subUrl('cafe-a'))->assertStatus(503);
        $this->actingAs($this->owner($a))->get($this->dashUrl())->assertOk();
    }

    public function test_table_qr_remembers_the_table(): void
    {
        $a = $this->restaurant('cafe-a');
        $table = $this->as($a, fn () => DiningTable::query()->where('name', '3')->first());

        $this->get($this->subUrl('cafe-a', "t/{$table->qr_token}"))->assertRedirect($this->subUrl('cafe-a'));
        $this->get($this->subUrl('cafe-a'))->assertSee('3');
        $this->assertSame($table->id, session('table.cafe-a')['id']);
    }

    public function test_menu_language_switch(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a);
        $this->get($this->subUrl('cafe-a', '?lang=en'))->assertOk()->assertSee('dir="ltr"', false);
        $this->get($this->subUrl('cafe-a', '?lang=ar'))->assertOk()->assertSee('dir="rtl"', false);
    }
}
