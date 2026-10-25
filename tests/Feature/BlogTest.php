<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\Post;
use Database\Seeders\BlogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** The marketing blog: admin CRUD, public pages in both languages, RSS, sitemap, safe Markdown. */
class BlogTest extends TenantTestCase
{
    private function admin(): PlatformAdmin
    {
        return PlatformAdmin::query()->create(['name' => 'EgWeb', 'email' => 'blog@egmenu.test', 'password' => bcrypt('Secret-pass-123')]);
    }

    private function article(array $attributes = []): Post
    {
        return Post::query()->create($attributes + [
            'slug' => 'first-post', 'title' => 'أول مقال', 'title_en' => 'First post', 'excerpt' => 'ملخص', 'body' => "# عنوان\n\nفقرة **مهمة**.",
            'is_published' => true, 'published_at' => now()->subDay(),
        ]);
    }

    public function test_admin_crud_is_only_for_platform_admins(): void
    {
        $this->get($this->adminUrl('/blog'))->assertRedirect();
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/blog'))->assertOk()->assertSee('المدونة');
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/blog'), [
            'title' => 'مقال جديد', 'body' => "## مرحبا\n\nنص.", 'is_published' => 1,
        ])->assertRedirect();
        $post = Post::query()->firstOrFail();
        $this->assertSame('post-1', $post->slug);
        $this->assertTrue($post->isLive());
        $this->assertSame($admin->id, $post->author_id);

        // a Latin slug is kept; a duplicate gets a suffix
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/blog'), ['title' => 'تاني', 'slug' => 'My-Slug', 'body' => 'x']);
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/blog'), ['title' => 'تالت', 'slug' => 'my-slug', 'body' => 'y']);
        $this->assertSame(['my-slug', 'my-slug-2'], Post::query()->where('slug', 'like', 'my-slug%')->orderBy('id')->pluck('slug')->all());

        // hide, then delete
        $this->actingAs($admin, 'admin')->post($this->adminUrl("/blog/{$post->id}/publish"))->assertRedirect();
        $this->assertFalse($post->fresh()->is_published);
        $this->actingAs($admin, 'admin')->post($this->adminUrl("/blog/{$post->id}/delete"))->assertRedirect();
        $this->assertNull(Post::query()->find($post->id));
    }

    public function test_cover_upload_is_stored_under_the_platform_folder_as_webp(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/blog'), [
            'title' => 'بصورة', 'body' => 'نص', 'cover' => UploadedFile::fake()->image('cover.jpg', 1200, 700),
        ])->assertSessionHasNoErrors();
        $post = Post::query()->firstOrFail();
        $this->assertStringStartsWith('platform/blog/', $post->cover);
        $this->assertStringEndsWith('.webp', $post->cover);
        Storage::disk('public')->assertExists($post->cover);
    }

    public function test_public_pages_render_in_arabic_and_english_and_drafts_are_404(): void
    {
        $this->article();
        Post::query()->create(['slug' => 'draft', 'title' => 'مسودة', 'body' => 'x', 'is_published' => false]);
        Post::query()->create(['slug' => 'later', 'title' => 'لاحقاً', 'body' => 'x', 'is_published' => true, 'published_at' => now()->addDay()]);

        $this->get($this->menuUrl('blog'))->assertOk()->assertSee('أول مقال')->assertDontSee('مسودة')->assertDontSee('لاحقاً')->assertSee('data-posts', false);
        $this->get($this->menuUrl('blog/first-post'))->assertOk()->assertSee('<h1', false)->assertSee('فقرة <strong>مهمة</strong>', false)
            ->assertSee('"@type":"BlogPosting"', false)->assertSee('property="og:type" content="article"', false)
            ->assertSee('<link rel="canonical" href="'.route('blog.show', 'first-post').'">', false);
        $this->get($this->menuUrl('blog/first-post?lang=en'))->assertOk()->assertSee('lang="en"', false)->assertSee('First post');
        $this->get($this->menuUrl('blog/draft'))->assertNotFound();
        $this->get($this->menuUrl('blog/later'))->assertNotFound();
        $this->get($this->menuUrl('blog/nope'))->assertNotFound();
    }

    public function test_markdown_strips_raw_html_and_unsafe_links(): void
    {
        $this->article(['body' => "نص <script>alert(1)</script> <img src=x onerror=alert(1)>\n\n[رابط](javascript:alert(1))"]);
        $page = $this->get($this->menuUrl('blog/first-post'))->assertOk();
        $page->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('onerror', false)->assertDontSee('href="javascript:', false);
    }

    public function test_rss_feed_and_sitemap_list_published_posts(): void
    {
        $this->article();
        Post::query()->create(['slug' => 'draft', 'title' => 'مسودة', 'body' => 'x', 'is_published' => false]);

        $rss = $this->get($this->menuUrl('rss.xml'))->assertOk()->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');
        $xml = simplexml_load_string($rss->getContent());
        $this->assertNotFalse($xml);
        $this->assertCount(1, $xml->channel->item);
        $this->assertSame(route('blog.show', 'first-post'), (string) $xml->channel->item[0]->link);

        $sitemap = $this->get($this->menuUrl('sitemap.xml'))->assertOk()->getContent();
        $this->assertStringContainsString(route('blog.show', 'first-post'), $sitemap);
        $this->assertStringContainsString(route('blog'), $sitemap);
        $this->assertStringNotContainsString('blog/draft', $sitemap);

        // the landing layout advertises the feed and links the blog
        $this->get($this->menuUrl(''))->assertOk()->assertSee('type="application/rss+xml"', false)->assertSee(route('blog'), false);
    }

    public function test_the_starter_articles_seed_once_and_render(): void
    {
        Storage::fake('public');
        $this->travelTo(Carbon::parse('2026-10-25 12:00', 'Africa/Cairo'));
        $this->seed(BlogSeeder::class);
        $this->seed(BlogSeeder::class);
        // 13 starter articles + the calendar: 3 in October (live), 6 in November and 6 in December (scheduled)
        $this->assertSame(28, Post::query()->count());
        $this->assertSame(16, Post::query()->published()->count());
        $this->assertSame(12, Post::query()->where('is_published', true)->where('published_at', '>', now())->count());
        $this->assertSame(0, Post::query()->where('is_published', false)->count());
        foreach (Post::query()->published()->get() as $post) {
            $this->get($this->menuUrl('blog/'.$post->slug))->assertOk()->assertSee('<h2', false);
            $this->assertGreaterThanOrEqual(2, $post->readingMinutes());
            $this->assertStringNotContainsString('منيوك', $post->title.$post->excerpt.$post->body);
        }
        $this->assertSame('food-cost-menu-pricing', Post::query()->published()->value('slug'));
    }
}
