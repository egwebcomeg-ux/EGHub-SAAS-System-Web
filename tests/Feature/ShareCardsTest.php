<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Support\ShareCards;
use App\Support\SiteModules;
use Illuminate\Support\Facades\File;
use Tests\TenantTestCase;

/** Each marketing page shares its own picture (public/og/{ar|en}/…png, drawn by egmenu:share-images). */
class ShareCardsTest extends TenantTestCase
{
    private function shareImage(string $html): string
    {
        $this->assertMatchesRegularExpression('~<meta property="og:image" content="([^"]+)">~', $html);
        preg_match('~<meta property="og:image" content="([^"]+)">~', $html, $match);

        return html_entity_decode($match[1]);
    }

    public function test_every_feature_page_shares_its_own_card_with_its_size_in_both_languages(): void
    {
        foreach (array_keys(SiteModules::all()) as $slug) {
            $arabic = $this->get($this->menuUrl("features/$slug?lang=ar"))->assertOk()
                ->assertSee('<meta property="og:image:width" content="1200">', false)
                ->assertSee('<meta property="og:image:height" content="630">', false)
                ->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
            $this->assertStringContainsString("/og/ar/features/$slug.png?v=", $this->shareImage($arabic->getContent()));

            $english = $this->get($this->menuUrl("features/$slug?lang=en"))->assertOk();
            $this->assertStringContainsString("/og/en/features/$slug.png?v=", $this->shareImage($english->getContent()));
        }

        $this->get($this->menuUrl('features/pager?lang=ar'))
            ->assertSee('<meta property="og:image:alt" content="النداء على الزبون — موبايل الزبون هو البيجر: بيرنّ لما الطلب يجهز">', false)
            ->assertSee('<meta property="og:title" content="النداء على الزبون · EgHub">', false);
    }

    public function test_other_marketing_pages_and_the_directory_share_their_own_card(): void
    {
        foreach (['' => 'home', 'pricing' => 'pricing', 'services' => 'services', 'features' => 'features', 'qr-code-generator' => 'qr-code-generator',
            'register' => 'register', 'blog' => 'blog', 'terms' => 'terms', 'privacy' => 'privacy', 'refund-policy' => 'refund-policy',
            'restaurants' => 'directory', 'restaurants/cairo' => 'directory/cairo', 'restaurants/cairo/coffee' => 'directory/cairo'] as $path => $card) {
            $page = $this->get($this->menuUrl($path))->assertOk();
            $this->assertStringContainsString("/og/ar/$card.png?v=", $this->shareImage($page->getContent()), $path);
        }
    }

    public function test_a_blog_post_shares_its_cover_or_else_the_blog_card(): void
    {
        $post = ['title' => 'أول مقال', 'excerpt' => 'ملخص', 'body' => 'فقرة', 'is_published' => true, 'published_at' => now()->subDay()];
        Post::query()->create($post + ['slug' => 'with-cover', 'cover' => 'platform/blog/cover.webp']);
        Post::query()->create($post + ['slug' => 'no-cover']);

        $withCover = $this->get($this->menuUrl('blog/with-cover'))->assertOk()->assertDontSee('og:image:width', false);
        $this->assertSame(url('/storage/platform/blog/cover.webp'), $this->shareImage($withCover->getContent()));

        $noCover = $this->get($this->menuUrl('blog/no-cover'))->assertOk()->assertSee('<meta property="og:image:width" content="1200">', false);
        $this->assertStringContainsString('/og/ar/blog.png?v=', $this->shareImage($noCover->getContent()));
    }

    public function test_a_card_that_was_never_drawn_falls_back_to_the_site_image(): void
    {
        $this->assertStringContainsString('/og-image.png?v=', ShareCards::url('features/not-a-module'));
        $this->assertStringContainsString('/og-image.png?v=', ShareCards::url(null));
        $this->assertStringContainsString('/og-image.png?v=', ShareCards::url('../../.env'));

        // the same page without its card file on disk
        $emptyPublic = storage_path('framework/testing/empty-public');
        File::ensureDirectoryExists($emptyPublic);
        $this->withoutVite();
        $this->app->usePublicPath($emptyPublic);
        try {
            $page = $this->get($this->menuUrl('features/pager'))->assertOk()->assertSee('<meta property="og:image:width" content="1200">', false);
            $this->assertStringEndsWith('/og-image.png', $this->shareImage($page->getContent()));
        } finally {
            File::deleteDirectory($emptyPublic);
        }
    }

    public function test_every_card_is_drawn_in_both_languages_at_the_share_size(): void
    {
        foreach (ShareCards::LOCALES as $locale) {
            app()->setLocale($locale);
            foreach (array_keys(ShareCards::all()) as $key) {
                $file = public_path(ShareCards::path($key, $locale));
                $this->assertFileExists($file, "run php artisan egmenu:share-images --only=$key");
                [$width, $height] = getimagesize($file);
                $this->assertSame([ShareCards::WIDTH, ShareCards::HEIGHT], [$width, $height], $key);
                $this->assertLessThan(300 * 1024, filesize($file), $key);
            }
        }
    }
}
