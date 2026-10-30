<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Post;
use App\Models\Subscription;
use App\Services\PageCache;
use App\Services\Settings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * The LiteSpeed page cache (App\Services\PageCache): which pages go out as public with which tags, ages and varies,
 * what never does, that a cached copy carries nothing of the visitor, and that every change purges exactly the
 * restaurant (or the pages) it touches, from a request, a queue job or a command.
 */
class PageCacheTest extends TenantTestCase
{
    private const MENU_VARY = 'cookie=lang_cafe-a,cookie=branch_cafe-a,cookie=card_cafe-a,cookie=tbl_cafe-a';

    protected function setUp(): void
    {
        parent::setUp();
        config(['page_cache.enabled' => true]);
        Http::fake();
        app(PageCache::class)->forget();
    }

    private function assertNotCached(TestResponse $response): TestResponse
    {
        return $response->assertHeader('X-LiteSpeed-Cache-Control', 'no-cache')->assertHeaderMissing('X-LiteSpeed-Tag');
    }

    /** @return list<array{host:string, tags:string}> purge calls sent to the hosts */
    private function purgeCalls(): array
    {
        return Http::recorded()->map(fn (array $pair) => [
            'host' => parse_url($pair[0]->url(), PHP_URL_HOST), 'tags' => $pair[0]->data()['tags'] ?? '',
        ])->values()->all();
    }

    public function test_a_guest_gets_the_marketing_page_as_public_without_any_session_or_token(): void
    {
        $response = $this->get($this->menuUrl('pricing'))->assertOk()
            ->assertHeader('X-LiteSpeed-Cache-Control', 'public,max-age=3600')
            ->assertHeader('X-LiteSpeed-Tag', 'eg_all, eg_platform')
            ->assertHeader('X-LiteSpeed-Vary', 'cookie=eghub_lang')
            ->assertHeaderMissing('X-LiteSpeed-Purge')
            ->assertCookieMissing(config('session.cookie'))
            ->assertCookieMissing('XSRF-TOKEN')
            ->assertSee('<meta name="csrf-token" content="">', false)
            // the page fetches a token before it posts
            ->assertSee("nativeFetch('/_csrf'", false);
        $this->assertNotSame('', session()->token());
        $this->assertStringNotContainsString(session()->token(), $response->getContent());

        $this->get($this->menuUrl(''))->assertOk()->assertHeader('X-LiteSpeed-Cache-Control', 'public,max-age=3600');
        $this->get($this->menuUrl('features'))->assertOk()->assertHeader('X-LiteSpeed-Cache-Control', 'public,max-age=3600');
    }

    public function test_with_the_cache_off_nothing_changes(): void
    {
        config(['page_cache.enabled' => false]);

        $this->get($this->menuUrl('pricing'))->assertOk()->assertHeaderMissing('X-LiteSpeed-Cache-Control')
            ->assertDontSee('<meta name="csrf-token" content="">', false)->assertDontSee("nativeFetch('/_csrf'", false);
    }

    public function test_personal_forms_signed_in_visitors_errors_posts_and_unknown_queries_are_never_cached(): void
    {
        $a = $this->restaurant('cafe-a');

        // the forms with their flashed answers and old input, and the sign-up
        $this->assertNotCached($this->get($this->menuUrl('register'))->assertOk())->assertDontSee('<meta name="csrf-token" content="">', false);
        $this->assertNotCached($this->get($this->menuUrl('services'))->assertOk());
        // a page reached with a flashed message
        $this->assertNotCached($this->withSession(['_flash.old' => ['ok'], 'ok' => 'x'])->get($this->menuUrl('features'))->assertOk());
        $this->flushSession();
        // error pages, posts and addresses with other query parameters (each would be its own copy)
        $this->assertNotCached($this->get($this->menuUrl('features/not-a-feature'))->assertNotFound());
        $this->assertNotCached($this->postJson($this->menuUrl('qr-code-generator/matrix'), ['text' => 'https://x.test']));
        $this->assertNotCached($this->get($this->menuUrl('pricing?utm_source=fb'))->assertOk());
        // someone signed in (their session would be stripped from a public copy), and the dashboard
        $this->assertNotCached($this->actingAs($this->owner($a))->get($this->menuUrl('pricing'))->assertOk());
        $this->assertNotCached($this->actingAs($this->owner($a))->get($this->dashUrl('/'))->assertOk());
    }

    public function test_each_language_has_its_own_copy_and_the_choice_is_remembered_on_cached_copies(): void
    {
        // ?lang=en: an English copy that also sets the language cookie when the server serves it from the cache
        $en = $this->get($this->menuUrl('pricing?lang=en'))->assertOk()->assertSee('<html lang="en"', false)
            ->assertHeader('X-LiteSpeed-Cache-Control', 'public,max-age=3600')->assertHeader('X-LiteSpeed-Vary', 'cookie=eghub_lang')
            ->assertCookie(PageCache::LANDING_LANG_COOKIE, 'en', false);
        $this->assertStringContainsString('eghub_lang=en', (string) $en->headers->get('LSC-Cookie'));

        // the plain address: Arabic without the cookie, English with it (two copies, one per cookie value)
        $this->flushSession();
        $this->get($this->menuUrl('pricing'))->assertOk()->assertSee('<html lang="ar"', false)
            ->assertHeader('X-LiteSpeed-Cache-Control', 'public,max-age=3600');
        $this->withUnencryptedCookie(PageCache::LANDING_LANG_COOKIE, 'en')->get($this->menuUrl('pricing'))->assertOk()
            ->assertSee('<html lang="en"', false)->assertHeader('X-LiteSpeed-Cache-Control', 'public,max-age=3600');

        // a session that chose English before the cookie existed: English, not stored (the plain copy is Arabic), cookie set
        $this->flushHeaders();
        $this->defaultCookies = $this->unencryptedCookies = [];
        $this->assertNotCached($this->withSession(['locale' => 'en'])->get($this->menuUrl('pricing'))->assertOk()->assertSee('<html lang="en"', false))
            ->assertCookie(PageCache::LANDING_LANG_COOKIE, 'en', false);
    }

    public function test_the_token_endpoint_hands_out_the_session_token_and_is_never_cached(): void
    {
        $token = $this->getJson($this->menuUrl('_csrf'))->assertOk()
            ->assertHeader('X-LiteSpeed-Cache-Control', 'no-cache')->json('token');
        $this->assertSame(session()->token(), $token);
        $this->assertSame(40, strlen($token));

        // on a restaurant's own host as well (its pages post to themselves)
        $this->restaurant('cafe-a');
        $this->getJson($this->subUrl('cafe-a', '_csrf'))->assertOk()->assertJsonStructure(['token']);
    }

    public function test_the_blog_copy_never_outlives_the_next_scheduled_article_and_saving_one_purges_the_marketing_pages(): void
    {
        $this->freezeSecond();
        Post::query()->create(['slug' => 'later', 'title' => 'لاحقاً', 'body' => 'x', 'is_published' => true, 'published_at' => now()->addMinutes(10)]);
        $this->assertSame(['eg_platform'], app(PageCache::class)->pendingTags());
        app(PageCache::class)->forget();

        $this->get($this->menuUrl('blog'))->assertOk()->assertHeader('X-LiteSpeed-Cache-Control', 'public,max-age=601');
    }

    public function test_a_guest_gets_the_menu_pages_as_public_tagged_with_their_restaurant(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a);
        $this->travelTo(now()->setTime(9, 0));
        app(PageCache::class)->forget();

        foreach ([$this->pathUrl('cafe-a'), $this->subUrl('cafe-a'), $this->pathUrl('cafe-a', 'about'), $this->pathUrl('cafe-a', 'links'), $this->pathUrl('cafe-a', "item/{$latte->id}")] as $url) {
            $response = $this->get($url)->assertOk()
                ->assertHeader('X-LiteSpeed-Cache-Control', 'public,max-age=300')
                ->assertHeader('X-LiteSpeed-Tag', 'eg_all, eg_t_cafe-a')
                ->assertHeader('X-LiteSpeed-Vary', self::MENU_VARY)
                ->assertHeaderMissing('X-LiteSpeed-Purge')
                ->assertCookieMissing(config('session.cookie'))
                ->assertSee('<meta name="csrf-token" content="">', false);
            $this->assertStringNotContainsString(session()->token(), $response->getContent(), $url);
        }
        // the menu counts the visits the cache served
        $this->get($this->pathUrl('cafe-a'))->assertSee('http:\/\/egmenu.test\/cafe-a\/visit', false);
        $this->get($this->pathUrl('cafe-a', 'visit'))->assertNoContent();
    }

    public function test_a_menu_with_anything_of_the_guest_on_it_is_never_cached(): void
    {
        $a = $this->restaurant('cafe-a');
        $table = $this->as($a, fn () => DiningTable::query()->first());

        // a scanned table: the redirect sets the marker cookie, the menu of that session is the guest's own
        $this->get($this->pathUrl('cafe-a', "t/{$table->qr_token}"))->assertRedirect()->assertCookie('tbl_cafe-a');
        $this->assertNotCached($this->withSession(['table.cafe-a' => ['id' => $table->id, 'at' => now()->timestamp]])->get($this->pathUrl('cafe-a'))->assertOk());
        $this->flushSession();
        // a remembered guest, branch or language, any query (?lang, ?branch, ?reorder, tracking), a crawler
        foreach (['card_cafe-a', 'branch_cafe-a', 'lang_cafe-a', 'tbl_cafe-a'] as $cookie) {
            $this->assertNotCached($this->withCookie($cookie, 'x')->get($this->pathUrl('cafe-a'))->assertOk());
            $this->defaultCookies = [];
        }
        $this->assertNotCached($this->get($this->pathUrl('cafe-a', '?lang=en'))->assertOk());
        $this->assertNotCached($this->get($this->pathUrl('cafe-a', '?fbclid=abc'))->assertOk());
        $this->assertNotCached($this->withHeader('User-Agent', 'Googlebot/2.1')->get($this->pathUrl('cafe-a'))->assertOk());
        $this->flushHeaders();
        // the other guest pages: booking, account, loyalty card, board, posts
        $this->assertNotCached($this->get($this->pathUrl('cafe-a', 'me'))->assertOk());
        $this->assertNotCached($this->get($this->pathUrl('cafe-a', 'card')));
        $this->assertNotCached($this->get($this->pathUrl('cafe-a', 'reserve')));
        $this->assertNotCached($this->postJson($this->pathUrl('cafe-a', 'call'), []));
        // the screen player and the dashboard
        $this->assertNotCached($this->get($this->menuUrl('screen'))->assertOk());
    }

    public function test_a_change_in_one_restaurant_purges_that_restaurant_only(): void
    {
        Storage::fake('public');
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $latte = $this->item($a);
        $this->item($b, 'Tea');
        app(PageCache::class)->forget();

        // each restaurant's pages carry its own tag only (and each host is its own copy on the server)
        $this->get($this->pathUrl('cafe-b'))->assertHeader('X-LiteSpeed-Tag', 'eg_all, eg_t_cafe-b');

        // a price change from the dashboard: the response purges cafe-a, and the hosts that hold cafe-a get the call
        $this->actingAs($this->owner($a))->postJson($this->dashUrl("/menu/items/{$latte->id}/price"), ['price' => 70])->assertOk()
            ->assertHeader('X-LiteSpeed-Purge', 'tag=eg_t_cafe-a')
            ->assertHeader('X-LiteSpeed-Cache-Control', 'no-cache');
        $calls = $this->purgeCalls();
        $this->assertEqualsCanonicalizing([
            ['host' => 'egmenu.test', 'tags' => 'eg_t_cafe-a'], ['host' => 'cafe-a.egmenu.test', 'tags' => 'eg_t_cafe-a'],
        ], $calls);
        foreach ($calls as $call) {
            $this->assertStringNotContainsString('cafe-b', $call['host'].$call['tags']);
        }

        // a photo (new item with an image), an availability toggle, the settings (branding) of cafe-b: cafe-b only
        $owner = $this->owner($b);
        $category = $this->as($b, fn () => Category::query()->value('id'));
        $this->actingAs($owner)->post($this->dashUrl('/menu/items'), [
            'category_id' => $category, 'name' => 'كيك', 'price' => 50, 'is_active' => '1', 'image' => UploadedFile::fake()->image('c.jpg', 800, 600),
        ])->assertSessionHasNoErrors()->assertHeader('X-LiteSpeed-Purge', 'tag=eg_t_cafe-b');
        $tea = $this->as($b, fn () => MenuItem::query()->where('name', 'Tea')->first());
        $this->actingAs($owner)->postJson($this->dashUrl("/menu/items/{$tea->id}/toggle"), ['field' => 'sold_out'])->assertOk()
            ->assertHeader('X-LiteSpeed-Purge', 'tag=eg_t_cafe-b');
        $this->actingAs($owner)->post($this->dashUrl('/settings'), ['brand_name' => 'B', 'currency' => 'ج.م', 'currency_en' => 'EGP',
            'color_primary' => '#F26522', 'color_secondary' => '#0A2540', 'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar'])
            ->assertSessionHasNoErrors()->assertHeader('X-LiteSpeed-Purge', 'tag=eg_t_cafe-b, tag=eg_directory');

        // a page view never purges anything, nor do the guests' counters (popup views and taps)
        $this->get($this->pathUrl('cafe-a'))->assertHeaderMissing('X-LiteSpeed-Purge');
        $popup = $this->as($a, fn () => Banner::query()->create(['image' => 'p.jpg', 'is_popup' => true]));
        app(PageCache::class)->forget();
        $this->postJson($this->pathUrl('cafe-a', "popups/{$popup->id}/view"))->assertOk()->assertHeaderMissing('X-LiteSpeed-Purge');
        $this->assertSame(1, $popup->fresh()->views);
    }

    public function test_suspension_and_plan_changes_from_the_admin_purge_the_restaurant(): void
    {
        $a = $this->restaurant('cafe-a');
        app(PageCache::class)->forget();

        $a->update(['status' => 'suspended']);
        $this->assertEqualsCanonicalizing(['eg_t_cafe-a', 'eg_directory', 'eg_platform'], app(PageCache::class)->pendingTags());
        $this->assertNotCached($this->get($this->pathUrl('cafe-a'))->assertStatus(503));

        app(PageCache::class)->forget();
        Subscription::query()->where('tenant_id', 'cafe-a')->first()->touch();
        $this->assertSame(['eg_t_cafe-a'], app(PageCache::class)->pendingTags());
    }

    public function test_the_menu_copy_never_outlives_what_the_clock_changes(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a);

        // the greeting changes at 17:00 restaurant time
        $this->travelTo(local_at()->setTime(16, 58, 0));
        $this->get($this->pathUrl('cafe-a'))->assertHeader('X-LiteSpeed-Cache-Control', 'public,max-age=121');

        // busy mode ends in 90 seconds
        $this->travelTo(now()->setTime(10, 0, 0));
        $this->as($a, fn () => Settings::put(['busy_until' => now()->addSeconds(90)->toIso8601String()]));
        $this->get($this->pathUrl('cafe-a'))->assertHeader('X-LiteSpeed-Cache-Control', 'public,max-age=91');

        // an item back from a timed sold-out in 200 seconds
        $this->as($a, function () use ($latte) {
            Settings::put(['busy_until' => '']);
            $latte->update(['is_sold_out' => true, 'available_from' => now()->addSeconds(200)]);
        });
        $this->get($this->pathUrl('cafe-a'))->assertHeader('X-LiteSpeed-Cache-Control', 'public,max-age=201');
    }

    public function test_purges_outside_a_request_reach_the_hosts_through_the_signed_endpoint(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a);
        app(PageCache::class)->forget();
        Http::fake();

        // a queue job (the AI menu import, a scheduled change) runs in the restaurant's context and sends when it ends
        tenancy()->initialize($a);
        dispatch(fn () => MenuItem::query()->whereKey($latte->id)->update(['price' => 99]));
        tenancy()->end();
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'http://egmenu.test/_lscache/purge' && $r['tags'] === 'eg_t_cafe-a');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'http://cafe-a.egmenu.test/_lscache/purge' && $r['tags'] === 'eg_t_cafe-a');

        // the deploy command: everything, on every host
        Http::fake();
        $this->artisan('egmenu:page-cache-purge')->assertSuccessful();
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'http://egmenu.test/_lscache/purge' && $r['tags'] === '*');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'http://purge.egmenu.test/_lscache/purge' && $r['tags'] === '*');

        // the endpoint answers a signed call with the purge header, and refuses anything else
        $time = time();
        $signed = ['tags' => 'eg_t_cafe-a', 'time' => $time, 'signature' => PageCache::signature('egmenu.test', 'eg_t_cafe-a', $time)];
        $this->post($this->menuUrl('_lscache/purge'), $signed)->assertNoContent()->assertHeader('X-LiteSpeed-Purge', 'tag=eg_t_cafe-a');
        $this->post($this->menuUrl('_lscache/purge'), ['tags' => 'eg_t_cafe-b'] + $signed)->assertForbidden()->assertHeaderMissing('X-LiteSpeed-Purge');
        $this->post($this->subUrl('cafe-a', '_lscache/purge'), $signed)->assertForbidden();
        $old = $time - 600;
        $this->post($this->menuUrl('_lscache/purge'), ['time' => $old, 'signature' => PageCache::signature('egmenu.test', 'eg_t_cafe-a', $old)] + $signed)->assertForbidden();
    }
}
