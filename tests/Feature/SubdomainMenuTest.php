<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * eghub.app/{slug} is the canonical menu address; {slug}.egmenu.app is a short alias that serves the same pages
 * with links on its own host and a canonical tag pointing at the path form.
 */
class SubdomainMenuTest extends TenantTestCase
{
    private function table(string $slug): DiningTable
    {
        return $this->as(Tenant::query()->findOrFail($slug), fn () => DiningTable::query()->first());
    }

    private function links(string $html): string
    {
        return str_replace('\\/', '/', $html);
    }

    public function test_menu_is_served_on_the_main_domain_path_with_path_links(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a, 'Latte');
        $this->get($this->pathUrl('cafe-a', "t/{$this->table('cafe-a')->qr_token}"))->assertRedirect($this->pathUrl('cafe-a'));

        $html = $this->links($this->get($this->pathUrl('cafe-a'))->assertOk()->assertSee('Latte')
            ->assertSee('<link rel="canonical" href="http://egmenu.test/cafe-a">', false)->getContent());
        $this->assertStringContainsString('http://egmenu.test/cafe-a/order', $html);
        $this->assertStringNotContainsString('cafe-a.egmenu.test', $html);
        // landing links on the same page are not rewritten
        $this->assertStringNotContainsString('egmenu.test/cafe-a/cafe-a', $html);
    }

    public function test_menu_is_served_on_the_subdomain_with_subdomain_links_and_a_canonical_path(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a, 'Latte');
        $this->get($this->subUrl('cafe-a', "t/{$this->table('cafe-a')->qr_token}"))->assertRedirect($this->subUrl('cafe-a'));

        $html = $this->links($this->get($this->subUrl('cafe-a', '?lang=en'))->assertOk()->assertSee('Latte')
            ->assertSee('<link rel="canonical" href="http://egmenu.test/cafe-a?lang=en">', false)->getContent());
        $this->assertStringContainsString('http://cafe-a.egmenu.test/order', $html);
        $this->assertStringNotContainsString('egmenu.test/cafe-a/order', $html);
    }

    public function test_private_pages_are_noindex(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte');
        $this->get($this->pathUrl('cafe-a', "t/{$this->table('cafe-a')->qr_token}"));
        $url = $this->postJson($this->pathUrl('cafe-a', 'order'), ['client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $latte->id, 'qty' => 1]]])
            ->assertSuccessful()->json('url');

        $this->get($url)->assertOk()->assertSee('<meta name="robots" content="noindex">', false)->assertDontSee('rel="canonical"', false);
    }

    public function test_table_scan_and_order_from_the_path_land_in_the_right_restaurant(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $latte = $this->item($a, 'Latte');
        $table = $this->table('cafe-a');

        $this->get($this->pathUrl('cafe-a', "t/{$table->qr_token}"))->assertRedirect($this->pathUrl('cafe-a'));
        $this->assertSame($table->id, session('table.cafe-a')['id']);

        $res = $this->postJson($this->pathUrl('cafe-a', 'order'), ['client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $latte->id, 'qty' => 1]]])
            ->assertSuccessful();
        $this->assertStringStartsWith($this->pathUrl('cafe-a', 'o/'), $res->json('url'));
        $this->assertSame(1, $this->as($a, fn () => Order::query()->count()));
        $this->assertSame(0, $this->as($b, fn () => Order::query()->count()));
        $this->get($res->json('url'))->assertOk();
        // the tracking link of cafe-a's order means nothing under cafe-b, on either address
        $token = Str::afterLast($res->json('url'), '/');
        $this->get($this->pathUrl('cafe-b', "o/$token"))->assertNotFound();
        $this->get($this->subUrl('cafe-b', "o/$token"))->assertNotFound();
        $this->get($this->subUrl('cafe-a', "o/$token"))->assertOk();
    }

    public function test_table_scan_and_order_from_the_subdomain_land_in_the_right_restaurant(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $latte = $this->item($a, 'Latte');
        $table = $this->table('cafe-a');

        $this->get($this->subUrl('cafe-a', "t/{$table->qr_token}"))->assertRedirect($this->subUrl('cafe-a'));
        $res = $this->postJson($this->subUrl('cafe-a', 'order'), ['client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $latte->id, 'qty' => 1]]])
            ->assertSuccessful();
        $this->assertStringStartsWith($this->subUrl('cafe-a', 'o/'), $res->json('url'));
        $this->assertSame(1, $this->as($a, fn () => Order::query()->count()));
        $this->assertSame(0, $this->as($b, fn () => Order::query()->count()));
        $this->get($this->subUrl('cafe-b', 'o/'.Str::afterLast($res->json('url'), '/')))->assertNotFound();
    }

    public function test_language_cookie_is_per_host_and_remembered(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a);

        $res = $this->get($this->subUrl('cafe-a', '?lang=en'))->assertOk()->assertCookie('lang_cafe-a', 'en');
        $this->assertNull($res->getCookie('lang_cafe-a', false)->getDomain());
        $this->assertNull(config('session.domain'));
    }

    public function test_platform_hosts_are_not_restaurants(): void
    {
        $token = $this->table($this->restaurant('cafe-a')->id)->qr_token;

        $this->get("http://app.egmenu.test/t/$token")->assertNotFound();
        $this->get('http://admin.egmenu.test/card')->assertNotFound();
        $this->get('http://www.egmenu.test/board')->assertRedirect('http://egmenu.test/board');
        // other reserved names never become menus either
        $this->get('http://api.egmenu.test/')->assertNotFound();
        $this->get('http://dashboard.egmenu.test/')->assertNotFound();
        // the platform hosts keep their own pages
        $this->get($this->dashUrl('/login'))->assertOk();
        $this->get($this->menuUrl())->assertOk();
    }

    public function test_unknown_restaurant_is_404_on_both_addresses(): void
    {
        $this->restaurant('cafe-a');

        $this->get($this->subUrl('no-such-cafe'))->assertNotFound();
        $this->get($this->subUrl('no-such-cafe', 'card'))->assertNotFound();
        $this->get('http://x.cafe-a.egmenu.test/')->assertNotFound();
        $this->get($this->pathUrl('no-such-cafe'))->assertNotFound();
        $this->get($this->pathUrl('no-such-cafe', 'board'))->assertNotFound();
    }

    public function test_main_domain_pages_and_reserved_slugs_never_reach_a_menu(): void
    {
        $this->get($this->menuUrl('features'))->assertOk()->assertSee('<link rel="canonical" href="http://egmenu.test/features">', false);
        $this->get($this->menuUrl('pricing'))->assertOk();
        foreach (['restaurants', 'sitemap', 'directory', 'dashboard', 'about'] as $slug) {
            $this->assertContains($slug, Tenant::RESERVED);
            $this->assertNotNull(Tenant::slugError($slug));
        }
        $this->get($this->menuUrl('dashboard/card'))->assertNotFound();
    }

    public function test_another_restaurants_data_is_unreachable_on_either_address(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $this->item($a, 'Only-In-A');
        $itemOfB = $this->item($b, 'Only-In-B');
        $tokenOfB = $this->table('cafe-b')->qr_token;

        foreach ([$this->subUrl('cafe-a'), $this->pathUrl('cafe-a')] as $root) {
            $this->get($root)->assertOk()->assertSee('Only-In-A')->assertDontSee('Only-In-B');
            $this->get("$root/t/$tokenOfB")->assertNotFound();
            $this->get("$root/cafe-b")->assertNotFound();
        }

        // B's item cannot be ordered from A's table
        $this->get($this->pathUrl('cafe-a', "t/{$this->table('cafe-a')->qr_token}"));
        $this->postJson($this->pathUrl('cafe-a', 'order'), ['client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $itemOfB->id, 'qty' => 1]]])
            ->assertStatus(422);
        $this->assertSame(0, $this->as($b, fn () => Order::query()->count()));
    }

    public function test_generated_menu_links_use_the_path_and_the_dashboard_shows_both_addresses(): void
    {
        $a = $this->restaurant('cafe-a');
        $table = $this->table('cafe-a');

        $this->assertSame('http://egmenu.test/cafe-a', $a->url());
        $this->assertSame('http://egmenu.test/cafe-a/card', $a->url('card'));
        $this->assertSame('http://egmenu.test/cafe-a?branch=main', $a->url('?branch=main'));
        $this->assertSame('http://cafe-a.egmenu.test/', $a->subdomainUrl());
        $this->assertSame($this->pathUrl('cafe-a', "t/{$table->qr_token}"), $this->as($a, fn () => $table->menuUrl()));

        $this->actingAs($this->owner($a))->get($this->dashUrl())->assertOk()
            ->assertSee('http://egmenu.test/cafe-a')->assertSee('http://cafe-a.egmenu.test/')
            ->assertSee('الرابط الأساسي')->assertSee('رابط بديل قصير');
        $this->actingAs($this->owner($a))->get($this->dashUrl('/tables'))->assertOk()->assertSee('رابط بديل قصير');
        $this->get($this->menuUrl('register/check?slug=new-cafe'))->assertJsonPath('url', 'http://egmenu.test/new-cafe');
    }
}
