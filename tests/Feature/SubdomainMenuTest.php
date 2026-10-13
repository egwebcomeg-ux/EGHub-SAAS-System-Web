<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** {slug}.egmenu.app is the canonical menu address; egmenu.app/{slug} only redirects there. */
class SubdomainMenuTest extends TenantTestCase
{
    private function table(string $slug): DiningTable
    {
        return $this->as(Tenant::query()->findOrFail($slug), fn () => DiningTable::query()->first());
    }

    public function test_menu_is_served_on_the_subdomain_with_subdomain_links(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a, 'Latte');
        $this->get($this->subUrl('cafe-a', "t/{$this->table('cafe-a')->qr_token}"))->assertRedirect($this->subUrl('cafe-a'));

        $html = $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('Latte')->getContent();
        $this->assertStringContainsString('cafe-a.egmenu.test', str_replace('\\/', '/', $html));
        $this->assertStringNotContainsString('egmenu.test/cafe-a', str_replace('\\/', '/', $html));
    }

    public function test_table_scan_and_order_from_the_subdomain_land_in_the_right_restaurant(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $latte = $this->item($a, 'Latte');
        $table = $this->table('cafe-a');

        $this->get($this->subUrl('cafe-a', "t/{$table->qr_token}"))->assertRedirect($this->subUrl('cafe-a'));
        $this->assertSame($table->id, session('table.cafe-a')['id']);

        $res = $this->postJson($this->subUrl('cafe-a', 'order'), ['client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $latte->id, 'qty' => 1]]])
            ->assertSuccessful();
        $this->assertStringStartsWith($this->subUrl('cafe-a', 'o/'), $res->json('url'));
        $this->assertSame(1, $this->as($a, fn () => Order::query()->count()));
        $this->assertSame(0, $this->as($b, fn () => Order::query()->count()));
        $this->get($res->json('url'))->assertOk();
        // the tracking link of cafe-a's order means nothing on cafe-b's subdomain
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

    public function test_unknown_subdomain_is_404(): void
    {
        $this->restaurant('cafe-a');

        $this->get($this->subUrl('no-such-cafe'))->assertNotFound();
        $this->get($this->subUrl('no-such-cafe', 'card'))->assertNotFound();
        $this->get('http://x.cafe-a.egmenu.test/')->assertNotFound();
    }

    public function test_old_path_address_redirects_permanently_to_the_subdomain(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte');
        $token = $this->table('cafe-a')->qr_token;

        $this->get($this->menuUrl('cafe-a'))->assertStatus(301)->assertRedirect($this->subUrl('cafe-a', '/'));
        $this->get($this->menuUrl("cafe-a/t/$token?lang=en"))->assertStatus(301)
            ->assertRedirect($this->subUrl('cafe-a', "t/$token?lang=en"));
        $this->get($this->menuUrl('cafe-a/card'))->assertStatus(301)->assertRedirect($this->subUrl('cafe-a', 'card'));
        $this->get($this->menuUrl('no-such-cafe/board'))->assertNotFound();

        // a page opened on the old address before the move can still post its order
        $this->get($this->subUrl('cafe-a', "t/$token"));
        $res = $this->postJson($this->menuUrl('cafe-a/order'), ['client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $latte->id, 'qty' => 1]]])
            ->assertSuccessful();
        $this->assertStringStartsWith($this->subUrl('cafe-a', 'o/'), $res->json('url'));
        $this->assertSame(1, $this->as($a, fn () => Order::query()->count()));
    }

    public function test_another_restaurants_data_is_unreachable_from_a_subdomain(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $this->item($a, 'Only-In-A');
        $itemOfB = $this->item($b, 'Only-In-B');
        $tokenOfB = $this->table('cafe-b')->qr_token;

        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('Only-In-A')->assertDontSee('Only-In-B');
        $this->get($this->subUrl('cafe-a', "t/$tokenOfB"))->assertNotFound();
        $this->get($this->subUrl('cafe-a', 'cafe-b'))->assertNotFound();

        // B's item cannot be ordered from A's table
        $this->get($this->subUrl('cafe-a', "t/{$this->table('cafe-a')->qr_token}"));
        $this->postJson($this->subUrl('cafe-a', 'order'), ['client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $itemOfB->id, 'qty' => 1]]])
            ->assertStatus(422);
        $this->assertSame(0, $this->as($b, fn () => Order::query()->count()));
    }

    public function test_every_generated_menu_link_uses_the_subdomain(): void
    {
        $a = $this->restaurant('cafe-a');
        $table = $this->table('cafe-a');

        $this->assertSame('http://cafe-a.egmenu.test/', $a->url());
        $this->assertSame('http://cafe-a.egmenu.test/card', $a->url('card'));
        $this->assertSame($this->subUrl('cafe-a', "t/{$table->qr_token}"), $this->as($a, fn () => $table->menuUrl()));

        $this->actingAs($this->owner($a))->get($this->dashUrl())->assertOk()
            ->assertSee('http://cafe-a.egmenu.test/')->assertDontSee('egmenu.test/cafe-a');
        $this->get($this->menuUrl('register/check?slug=new-cafe'))->assertJsonPath('url', 'http://new-cafe.egmenu.test/');
    }
}
