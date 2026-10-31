<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\DomainDns;
use Tests\TenantTestCase;

/**
 * «طلبات الدومين» (tenants.manage): the restaurants that entered their own domain, whether the plan includes it, the
 * status and the last DNS check; approve connects it, reject sends the owner a reason. Domains already connected
 * keep serving the menu.
 */
class AdminDomainRequestsTest extends TenantTestCase
{
    private function admin(string $role, string $name = 'Sara Support'): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name))."-$role@egweb.test", 'password' => 'Secret-pass-123', 'role' => $role,
        ]);
    }

    private function domain(Tenant $tenant, string $host, string $status = 'pending'): Domain
    {
        return Domain::query()->create([
            'domain' => $host, 'tenant_id' => $tenant->id, 'source' => 'own', 'status' => $status,
            'verified_at' => $status === 'active' ? now() : null,
        ]);
    }

    /** The DNS answers $ip for every host, without a network call. */
    private function fakeDns(?string $ip): void
    {
        $this->app->instance(DomainDns::class, new class($ip) extends DomainDns
        {
            public function __construct(private ?string $ip) {}

            public function resolve(string $host): ?string
            {
                return $this->ip;
            }
        });
    }

    public function test_the_list_shows_each_domain_with_its_plan_status_and_waiting_ones_first(): void
    {
        $this->domain($this->restaurant('cafe-live', 'business'), 'menu.cafe-live.com', 'active');
        $this->domain($this->restaurant('cafe-new', 'business'), 'cafe-new.com');
        $this->domain($this->restaurant('cafe-small', 'starter'), 'cafe-small.com');

        $page = $this->actingAs($this->admin('support'), 'admin')->get($this->adminUrl('/domains'))->assertOk()
            ->assertSee('data-nav="admin.domains"', false)
            ->assertSeeInOrder(['cafe-new.com', 'menu.cafe-live.com'])
            ->assertSee('Restaurant cafe-small');
        $small = Domain::query()->where('domain', 'cafe-small.com')->first();
        $live = Domain::query()->where('domain', 'menu.cafe-live.com')->first();
        $this->assertMatchesRegularExpression('/data-domain-row="'.$small->id.'".*?data-domain-allowed="no"/s', $page->getContent());
        $this->assertMatchesRegularExpression('/data-domain-row="'.$live->id.'".*?data-domain-allowed="yes"/s', $page->getContent());

        $this->get($this->adminUrl('/domains?status=waiting'))->assertOk()
            ->assertSee('cafe-new.com')->assertDontSee('menu.cafe-live.com');
    }

    public function test_approving_connects_the_domain_and_is_in_the_activity_log(): void
    {
        $tenant = $this->restaurant('cafe-a', 'business');
        $domain = $this->domain($tenant, 'menu.cafe-a.com');
        $this->get('http://menu.cafe-a.com/')->assertRedirect($this->pathUrl('cafe-a'));

        $admin = $this->admin('support');
        $this->actingAs($admin, 'admin')->post($this->adminUrl("/domains/{$domain->id}/approve"))->assertRedirect();

        $domain->refresh();
        $this->assertTrue($domain->isActive());
        $this->get('http://menu.cafe-a.com/')->assertOk();
        $this->assertSame('http://menu.cafe-a.com/', $tenant->fresh()->url());
        $log = PlatformAuditLog::query()->where('action', 'domain.approved')->sole();
        $this->assertSame([$admin->id, 'menu.cafe-a.com', 'cafe-a'], [$log->admin_id, $log->subject, $log->meta['tenant']]);
        $this->actingAs($this->admin('owner', 'Omar Owner'), 'admin')->get($this->adminUrl('/activity'))->assertOk()->assertSee(__('Custom domain approved'));
    }

    public function test_rejecting_needs_a_reason_the_owner_reads_and_stops_the_domain(): void
    {
        $tenant = $this->restaurant('cafe-a', 'business');
        $domain = $this->domain($tenant, 'menu.cafe-a.com', 'active');
        $this->actingAs($this->admin('support'), 'admin');

        $this->post($this->adminUrl("/domains/{$domain->id}/reject"), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertTrue($domain->fresh()->isActive());

        $this->post($this->adminUrl("/domains/{$domain->id}/reject"), ['reason' => 'The domain is registered to another company.'])->assertRedirect();
        $domain->refresh();
        $this->assertSame(['rejected', 'The domain is registered to another company.', null], [$domain->status, $domain->note, $domain->verified_at]);
        $this->get('http://menu.cafe-a.com/')->assertRedirect($this->pathUrl('cafe-a'));
        $this->assertSame(1, PlatformAuditLog::query()->where('action', 'domain.rejected')->count());

        $this->actingAs($this->owner($tenant), 'web')->get($this->dashUrl('/domain'))->assertOk()
            ->assertSee('The domain is registered to another company.');
    }

    public function test_the_dns_check_is_remembered_on_the_list(): void
    {
        PlatformSetting::put('server_ip', '203.0.113.10');
        $domain = $this->domain($this->restaurant('cafe-a', 'business'), 'menu.cafe-a.com');
        $this->actingAs($this->admin('support'), 'admin');
        $this->get($this->adminUrl('/domains'))->assertSee(__('Not checked'));

        $this->fakeDns('198.51.100.7');
        $this->post($this->adminUrl("/domains/{$domain->id}/check"))->assertRedirect()
            ->assertSessionHas('ok', __(':domain does not point to our server yet (it points to :ip).', ['domain' => 'menu.cafe-a.com', 'ip' => '198.51.100.7']));
        $this->get($this->adminUrl('/domains'))->assertSee('data-domain-dns="no"', false)->assertSee('198.51.100.7');

        $this->fakeDns('203.0.113.10');
        $this->post($this->adminUrl("/domains/{$domain->id}/check"));
        $this->get($this->adminUrl('/domains'))->assertSee('data-domain-dns="yes"', false);
    }

    public function test_only_admins_who_manage_restaurants_open_the_requests(): void
    {
        $domain = $this->domain($this->restaurant('cafe-a', 'business'), 'menu.cafe-a.com');

        $this->actingAs($this->admin('finance', 'Fady Finance'), 'admin');
        $this->get($this->adminUrl('/domains'))->assertForbidden();
        $this->post($this->adminUrl("/domains/{$domain->id}/approve"))->assertForbidden();
        $this->post($this->adminUrl("/domains/{$domain->id}/reject"), ['reason' => 'No'])->assertForbidden();
        $this->get($this->adminUrl('/'))->assertDontSee('data-nav="admin.domains"', false);
        $this->assertSame('pending', $domain->fresh()->status);
    }

    public function test_domains_already_connected_keep_working(): void
    {
        $tenant = $this->restaurant('cafe-a', 'business');
        $this->item($tenant, 'Latte');
        $this->domain($tenant, 'menu.cafe-a.com', 'active');

        $this->get('http://menu.cafe-a.com/')->assertOk()->assertSee('Latte');
        $this->actingAs($this->admin('support'), 'admin')->get($this->adminUrl('/domains?status=active'))->assertOk()
            ->assertSee('menu.cafe-a.com')->assertSee(Domain::STATUSES['active'][0]);
    }
}
