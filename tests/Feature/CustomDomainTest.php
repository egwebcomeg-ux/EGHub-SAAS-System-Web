<?php

namespace Tests\Feature;

use App\Http\Controllers\Dashboard\DomainController;
use App\Models\DiningTable;
use App\Models\Domain;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Notifications\DomainRequested;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class CustomDomainTest extends TenantTestCase
{
    private function activeDomain(Tenant $tenant, string $host): Domain
    {
        return Domain::query()->create(['domain' => $host, 'tenant_id' => $tenant->id, 'source' => 'own', 'status' => 'active', 'verified_at' => now()]);
    }

    public function test_active_domain_serves_the_menu_with_its_own_links(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $this->item($a, 'Latte');
        $this->activeDomain($a, 'menu.cafe-a.com');

        $this->get('http://menu.cafe-a.com/')->assertOk()->assertSee('Latte')->assertDontSee('egmenu.test', false);
        // ordering from the domain lands in the right restaurant
        $latte = $this->as($a, fn () => MenuItem::query()->first());
        $table = $this->as($a, fn () => DiningTable::query()->first());
        $this->get("http://menu.cafe-a.com/t/{$table->qr_token}")->assertRedirect('http://menu.cafe-a.com');
        $this->postJson('http://menu.cafe-a.com/order', ['client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $latte->id, 'qty' => 1]]])
            ->assertSuccessful();
        $this->assertSame(1, $this->as($a, fn () => Order::query()->count()));
        $this->get('http://www.menu.cafe-a.com/')->assertOk();
        // QR codes and shared links now use the domain
        $this->assertSame('http://menu.cafe-a.com/', $a->fresh()->url());
        // the slug address keeps working
        $this->get($this->menuUrl('cafe-a'))->assertOk()->assertSee('Latte');
    }

    public function test_unknown_pending_or_unpaid_domains(): void
    {
        $a = $this->restaurant('cafe-a', 'starter');
        $this->get('http://nobody.com/')->assertNotFound();

        Domain::query()->create(['domain' => 'pending.com', 'tenant_id' => $a->id, 'status' => 'pending']);
        $this->get('http://pending.com/')->assertRedirect($this->menuUrl('cafe-a/'));

        // active but the plan has no custom domains: printed QR codes still reach the menu
        $this->activeDomain($a, 'starter-cafe.com');
        $this->get('http://starter-cafe.com/t/0123456789abcdef0123')->assertRedirect($this->menuUrl('cafe-a/t/0123456789abcdef0123'));
        $this->assertStringContainsString('/cafe-a', $a->fresh()->url());
    }

    public function test_another_restaurants_data_is_not_reachable_through_a_domain(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $b = $this->restaurant('cafe-b', 'business');
        $this->item($b, 'Secret B item');
        $this->activeDomain($a, 'menu.cafe-a.com');

        $this->get('http://menu.cafe-a.com/')->assertOk()->assertDontSee('Secret B item');
        $this->get('http://menu.cafe-a.com/cafe-b')->assertNotFound();
    }

    public function test_owner_requests_a_domain_and_admin_activates_it(): void
    {
        Notification::fake();
        $a = $this->restaurant('cafe-a', 'pro');
        $owner = $this->owner($a);
        $admin = PlatformAdmin::query()->create(['name' => 'A', 'email' => 'a@egmenu.test', 'password' => 'Secret-pass-123']);

        // booking through EgWeb opens the egweb.com.eg cart with the name filled in
        $this->actingAs($owner)->post($this->dashUrl('/domain/book'), ['domain' => 'https://www.MyCafe.com/'])
            ->assertRedirect(DomainController::DEFAULT_CART_URL.'mycafe.com');
        $domain = Domain::query()->where('domain', 'mycafe.com')->firstOrFail();
        $this->assertSame(['egweb', 'requested'], [$domain->source, $domain->status]);
        Notification::assertSentTo($admin, DomainRequested::class);

        // not served before EgWeb activates it
        $this->assertStringContainsString('/cafe-a', $a->fresh()->url());
        $this->actingAs($admin, 'admin')->post($this->adminUrl("/domains/{$domain->id}"), ['status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('http://mycafe.com/', $a->fresh()->url());

        // the egmenu domain itself, bad names and another restaurant's domain are refused
        $this->actingAs($owner)->post($this->dashUrl('/domain/connect'), ['domain' => 'x.egmenu.test'])->assertSessionHasErrors('domain');
        $this->actingAs($owner)->post($this->dashUrl('/domain/connect'), ['domain' => 'not a domain'])->assertSessionHasErrors('domain');
        $b = $this->restaurant('cafe-b', 'pro');
        $this->actingAs($this->owner($b))->post($this->dashUrl('/domain/connect'), ['domain' => 'mycafe.com'])->assertSessionHasErrors('domain');
    }

    public function test_plans_without_custom_domains_cannot_request_one(): void
    {
        $a = $this->restaurant('cafe-a', 'basic');
        $this->actingAs($this->owner($a))->get($this->dashUrl('/domain'))->assertOk()->assertSee(__('See plans'));
        $this->actingAs($this->owner($a))->post($this->dashUrl('/domain/connect'), ['domain' => 'cafe-a.com'])->assertForbidden();
        $this->assertSame(0, Domain::query()->count());
    }
}
