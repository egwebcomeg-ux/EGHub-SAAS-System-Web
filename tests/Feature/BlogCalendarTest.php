<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\Post;
use App\Support\PostBlocks;
use Database\Seeders\BlogSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** The blog's editorial calendar: scheduled articles stay hidden everywhere until their time, then appear by themselves. */
class BlogCalendarTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->travelTo(Carbon::parse('2026-10-25 12:00', 'Africa/Cairo'));
        $this->seed(BlogSeeder::class);
    }

    public function test_the_october_articles_are_live_with_their_blocks_in_the_blog_rss_and_sitemap(): void
    {
        // nine per page, newest first: the October articles sit among the starter ones on the first two pages
        $index = $this->get($this->menuUrl('blog'))->assertOk()->getContent().$this->get($this->menuUrl('blog?page=2'))->assertOk()->getContent();
        $rss = $this->get($this->menuUrl('rss.xml'))->assertOk()->getContent();
        $sitemap = $this->get($this->menuUrl('sitemap.xml'))->assertOk()->getContent();

        $blocks = [
            'takeaway-packaging-guide' => ['data-bpicker', 'data-bchecklist'],
            'packaging-cost-per-order' => ['data-bcalc="packaging"', 'data-bquiz'],
            'delivery-complaints-checklist' => ['data-bchecklist', 'data-bquiz'],
        ];
        foreach ($blocks as $slug => $markers) {
            $post = Post::query()->where('slug', $slug)->firstOrFail();
            $this->assertTrue($post->isLive());
            $this->assertStringContainsString(e($post->title), $index);
            $this->assertStringContainsString(route('blog.show', $slug), $rss);
            $this->assertStringContainsString(route('blog.show', $slug), $sitemap);
            $page = $this->get($this->menuUrl("blog/$slug"))->assertOk()->assertSee('<h2', false);
            foreach ($markers as $marker) {
                $page->assertSee($marker, false);
            }
            // the drawn cover is copied to the public disk and used as the article's share image
            Storage::disk('public')->assertExists($post->cover);
            $page->assertSee('property="og:image" content="'.url('/storage/'.$post->cover), false);
        }
    }

    public function test_every_calendar_article_carries_a_block_and_keeps_the_house_style(): void
    {
        $schedule = (new BlogSeeder)->schedule();
        $this->assertCount(15, $schedule);
        foreach ($schedule as $slug => $at) {
            $post = Post::query()->where('slug', $slug)->firstOrFail();
            $this->assertSame($at, $post->published_at->timezone('Africa/Cairo')->format('Y-m-d H:i'));
            $hour = (int) substr($at, 11, 2);
            $this->assertTrue($hour >= 19 && $hour <= 21, "$slug goes out in the evening");
            $text = $post->title.' '.$post->excerpt.' '.$post->body;
            foreach (['!', 'في عالم اليوم', 'لا تتردد', 'رحلة', 'تجربة فريدة', 'بكل سهولة', 'ببساطة', 'اكتشف', 'من خلال', 'بشكل', 'يتم ', 'الخاص بك', 'مكانك', 'منيوك', '✅'] as $phrase) {
                $this->assertStringNotContainsString($phrase, $text, "$slug: $phrase");
            }
            if ($post->is_published) {
                $segments = PostBlocks::parse($post->body);
                $this->assertNotEmpty(array_filter($segments, fn (array $segment) => $segment['type'] !== 'markdown'), "$slug has a block");
                foreach ($segments as $segment) {
                    $this->assertStringNotContainsString('[[', $segment['text'] ?? '', "$slug: a block line was left as text");
                }
                $this->assertGreaterThanOrEqual(3, $post->readingMinutes());
                $this->assertLessThanOrEqual(1, substr_count($post->body, 'EgHub'), "$slug mentions EgHub once at most");
            }
        }

        // 3–7 days between articles, never the same weekday twice in a row (the October backfill and the plan from
        // November on are two runs: the starter articles already fill the end of October)
        $times = array_map(fn (string $at) => Carbon::parse($at), array_values($schedule));
        for ($i = 1; $i < count($times); $i++) {
            if ($times[$i - 1]->month === 10 && $times[$i]->month === 11) {
                continue;
            }
            $gap = $times[$i - 1]->diffInDays($times[$i]);
            $this->assertTrue($gap >= 3 && $gap < 8, "gap before {$times[$i]}: $gap days");
            $this->assertNotSame($times[$i - 1]->dayOfWeek, $times[$i]->dayOfWeek);
        }
    }

    public function test_a_scheduled_article_stays_hidden_until_its_time_and_then_appears_by_itself(): void
    {
        $post = Post::query()->where('slug', 'winter-menu-hot-drinks')->firstOrFail();
        $this->assertTrue($post->is_published);
        $this->assertFalse($post->isLive());

        $this->get($this->menuUrl('blog'))->assertOk()->assertDontSee($post->title);
        $this->get($this->menuUrl('blog/winter-menu-hot-drinks'))->assertNotFound();
        $this->get($this->menuUrl('blog/delivery-complaints-checklist'))->assertOk()->assertDontSee($post->title);
        $this->assertStringNotContainsString('winter-menu-hot-drinks', $this->get($this->menuUrl('rss.xml'))->getContent());
        $this->assertStringNotContainsString('winter-menu-hot-drinks', $this->get($this->menuUrl('sitemap.xml'))->getContent());

        // one minute before: still hidden (the sitemap cached above is still served)
        $this->travelTo(Carbon::parse('2026-11-02 20:29', 'Africa/Cairo'));
        $this->get($this->menuUrl('blog/winter-menu-hot-drinks'))->assertNotFound();

        // its time comes: no cron, no cache flush
        $this->travelTo(Carbon::parse('2026-11-02 20:31', 'Africa/Cairo'));
        $this->get($this->menuUrl('blog'))->assertOk()->assertSee($post->title);
        $this->get($this->menuUrl('blog/winter-menu-hot-drinks'))->assertOk()->assertSee('data-bcalc="food-cost"', false)->assertSee('data-bchecklist', false);
        $this->assertStringContainsString(route('blog.show', 'winter-menu-hot-drinks'), $this->get($this->menuUrl('rss.xml'))->getContent());
        $this->assertStringContainsString(route('blog.show', 'winter-menu-hot-drinks'), $this->get($this->menuUrl('sitemap.xml'))->getContent());
        // the next one is still waiting
        $this->get($this->menuUrl('blog/custom-cake-pricing'))->assertNotFound();
    }

    public function test_the_december_articles_are_scheduled_and_each_appears_at_its_own_time(): void
    {
        $blocks = [
            'corporate-bulk-orders' => ['data-bcalc="food-cost"', 'data-bchecklist'],
            'choosing-ingredient-suppliers' => ['data-bchecklist', 'data-bquiz'],
            'new-waiter-first-week' => ['data-bchecklist', 'data-bquiz'],
            'year-end-review' => ['data-bchecklist', 'data-bquiz'],
            'weekly-sales-report-10-minutes' => ['data-bchecklist', 'data-bquiz'],
            'year-end-stock-count' => ['data-bcalc="stock-count"', 'data-bchecklist'],
        ];
        $schedule = (new BlogSeeder)->schedule();
        foreach ($blocks as $slug => $markers) {
            $post = Post::query()->where('slug', $slug)->firstOrFail();
            $this->assertTrue($post->is_published, "$slug is scheduled, not a draft");
            $this->assertStringNotContainsString('مسودة للمراجعة', $post->body);
            $this->assertNotSame('', $post->title_en);
            Storage::disk('public')->assertExists($post->cover);
            $at = Carbon::parse($schedule[$slug], 'Africa/Cairo');

            $this->travelTo($at->copy()->subMinute());
            $this->assertFalse($post->fresh()->isLive());
            $this->get($this->menuUrl("blog/$slug"))->assertNotFound();
            $this->get($this->menuUrl('blog'))->assertOk()->assertDontSee(e($post->title), false);

            $this->travelTo($at->copy()->addMinute());
            $page = $this->get($this->menuUrl("blog/$slug"))->assertOk()->assertSee('<h2', false);
            foreach ($markers as $marker) {
                $page->assertSee($marker, false);
            }
            $this->get($this->menuUrl('blog'))->assertOk()->assertSee(e($post->title), false);
            $this->assertStringContainsString(route('blog.show', $slug), $this->get($this->menuUrl('rss.xml'))->getContent());
        }
    }

    public function test_a_december_outline_left_as_a_draft_is_filled_in_once_and_an_edited_article_is_left_alone(): void
    {
        // production has the six as outlines (drafts), seeded before the articles were written
        Post::query()->where('slug', 'year-end-review')->update(['is_published' => false, 'body' => '> مسودة للمراجعة. الفكرة: ...

## الأرقام

- المبيعات بالشهر
', 'title_en' => '']);
        Post::query()->where('slug', 'year-end-stock-count')->update(['is_published' => false, 'body' => 'كتبه صاحب المحل بنفسه']);

        $this->seed(BlogSeeder::class);
        $this->seed(BlogSeeder::class);

        $review = Post::query()->where('slug', 'year-end-review')->firstOrFail();
        $this->assertTrue($review->is_published);
        $this->assertStringContainsString('[[checklist أرقام المراجعة]]', $review->body);
        $this->assertSame('2026-12-17 20:25', $review->published_at->timezone('Africa/Cairo')->format('Y-m-d H:i'));
        $this->assertNotSame('', $review->title_en);
        Storage::disk('public')->assertExists($review->cover);
        $this->assertSame(1, Post::query()->where('slug', 'year-end-review')->count());

        $edited = Post::query()->where('slug', 'year-end-stock-count')->firstOrFail();
        $this->assertFalse($edited->is_published);
        $this->assertSame('كتبه صاحب المحل بنفسه', $edited->body);
    }

    public function test_admin_shows_the_scheduled_time(): void
    {
        $admin = PlatformAdmin::query()->create(['name' => 'EgWeb', 'email' => 'calendar@egmenu.test', 'password' => bcrypt('Secret-pass-123')]);
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/blog'))->assertOk()
            ->assertSee(__('Scheduled'))->assertSee('2026/11/02 08:30 م');

        // hiding a scheduled article and publishing it again keeps its planned time
        $post = Post::query()->where('slug', 'year-end-review')->firstOrFail();
        $this->actingAs($admin, 'admin')->post($this->adminUrl("/blog/{$post->id}/publish"))->assertRedirect();
        $this->assertFalse($post->fresh()->is_published);
        $this->actingAs($admin, 'admin')->post($this->adminUrl("/blog/{$post->id}/publish"))->assertRedirect();
        $this->assertTrue($post->fresh()->is_published);
        $this->assertFalse($post->fresh()->isLive());
    }
}
