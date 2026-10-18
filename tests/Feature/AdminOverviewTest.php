<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PlatformAdmin;
use App\Models\ServiceRequest;
use Tests\TenantTestCase;

/** admin home "نظرة عامة": KPIs, the 30-day charts and the latest lists, from real rows only. */
class AdminOverviewTest extends TenantTestCase
{
    private function admin(string $role = 'owner'): PlatformAdmin
    {
        return PlatformAdmin::query()->create(['name' => 'Sara Admin', 'email' => "$role@egweb.test", 'password' => 'Secret-pass-123', 'role' => $role]);
    }

    public function test_empty_platform_shows_zeroes_and_empty_charts(): void
    {
        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('/'))->assertOk()
            ->assertSee(__('Welcome, :name', ['name' => 'Sara']))
            ->assertSee(__('No new restaurants in the last 30 days.'))->assertSee(__('No paid invoices in the last 30 days.'))
            ->assertSee('data-chart-empty', false)->assertDontSee('data-chart>', false)
            ->assertSee(__('No restaurants yet.'))->assertSee(__('No invoices here.'));
    }

    public function test_numbers_and_charts_come_from_the_database(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $this->restaurant('cafe-b', trial: true);
        Invoice::query()->create(['tenant_id' => $tenant->id, 'number' => 'EGM-0001', 'kind' => 'subscription', 'lines' => [], 'subtotal' => 399, 'total' => 399, 'status' => 'paid', 'paid_at' => now()]);
        Invoice::query()->create(['tenant_id' => $tenant->id, 'number' => 'EGM-0002', 'kind' => 'subscription', 'lines' => [], 'subtotal' => 250, 'total' => 250, 'status' => 'open']);
        ServiceRequest::query()->create(['service' => 'print', 'name' => 'Mona', 'restaurant' => 'Rosto', 'phone' => '01012345678', 'status' => 'new']);

        $page = $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('/'))->assertOk()
            ->assertSee('Restaurant cafe-a')->assertSee('EGM-0002')->assertSee(__('Open invoices: :count', ['count' => 1]))
            ->assertSee('data-chart>', false)->assertDontSee(__('No new restaurants in the last 30 days.'));
        $html = $page->getContent();
        $this->assertStringContainsString(money(250), $html);
        $this->assertStringContainsString(money(399).' '.__('EGP'), $html); // paid in the last 30 days
        // the sidebar counts what waits: one open invoice, one new service request
        $this->assertMatchesRegularExpression('/data-nav="admin.services".*?>1<\/span>/s', $html);
    }

    public function test_a_content_admin_sees_no_restaurant_or_money_numbers(): void
    {
        $this->restaurant('cafe-a');

        $this->actingAs($this->admin('content'), 'admin')->get($this->adminUrl('/'))->assertOk()
            ->assertDontSee('data-kpis="tenants"', false)->assertDontSee(__('MRR'))->assertDontSee('Restaurant cafe-a');
    }
}
