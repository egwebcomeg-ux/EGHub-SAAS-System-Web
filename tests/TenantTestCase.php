<?php

namespace Tests;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Settings;
use App\Services\TenantProvisioner;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

/** Base for multi-tenant feature tests: plans/roles seeded, helpers to create restaurants and menu rows. */
abstract class TenantTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        Entitlements::flush();
        Settings::flush();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        Entitlements::flush();
        Settings::flush();
        parent::tearDown();
    }

    /**
     * Every HTTP request runs in a fresh PHP process in production; in tests the app is shared, so end the tenant
     * context a request opened. Otherwise later assertions would silently run scoped to that restaurant.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
            Entitlements::flush();
            Settings::flush();
            // a custom-domain request rewrites generated links; production gets a fresh process per request
            URL::formatHostUsing(fn ($root) => $root);
            URL::formatPathUsing(fn ($path) => $path);
        }
    }

    protected function restaurant(string $slug, string $plan = 'pro', bool $trial = false): Tenant
    {
        return app(TenantProvisioner::class)->create([
            'slug' => $slug, 'name' => "Restaurant $slug", 'owner_name' => 'Owner', 'email' => "owner@$slug.test",
            'password' => 'Secret-pass-123', 'plan' => $plan, 'trial' => $trial,
        ]);
    }

    /** Runs $fn inside $tenant's context. */
    protected function as(Tenant $tenant, \Closure $fn): mixed
    {
        tenancy()->initialize($tenant);
        try {
            return $fn();
        } finally {
            tenancy()->end();
            Settings::flush();
        }
    }

    protected function item(Tenant $tenant, string $name = 'Latte', float $price = 65): MenuItem
    {
        return $this->as($tenant, function () use ($name, $price) {
            $cat = Category::query()->firstOrCreate(['name' => 'Coffee']);

            return MenuItem::query()->create(['category_id' => $cat->id, 'name' => $name, 'price' => $price]);
        });
    }

    protected function owner(Tenant $tenant): User
    {
        return User::query()->where('tenant_id', $tenant->id)->firstOrFail();
    }

    /** A staff member of $tenant with $role. */
    protected function staff(Tenant $tenant, string $role, string $email): User
    {
        return $this->as($tenant, function () use ($role, $email) {
            $u = User::query()->create(['name' => ucfirst($role), 'email' => $email, 'password' => 'Secret-pass-123']);
            $u->assignRole($role);

            return $u;
        });
    }

    /** The main domain: landing pages, and the old /{slug}/... menu address (redirects to the subdomain). */
    protected function menuUrl(string $path = ''): string
    {
        return 'http://egmenu.test/'.ltrim($path, '/');
    }

    /** A restaurant's menu on its subdomain: subUrl('cafe-a', 't/abc') → http://cafe-a.egmenu.test/t/abc. */
    protected function subUrl(string $slug, string $path = ''): string
    {
        $root = "http://$slug.egmenu.test";

        return $path === '' ? $root : $root.'/'.ltrim($path, '/');
    }

    protected function dashUrl(string $path = '/'): string
    {
        return 'http://app.egmenu.test'.$path;
    }

    protected function adminUrl(string $path = '/'): string
    {
        return 'http://admin.egmenu.test'.$path;
    }
}
