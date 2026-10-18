<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Services\ItemVideo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** A short video per item: an MP4 / WebM upload up to 15 MB or a YouTube / Instagram / TikTok link (feature full_branding). */
class ItemVideoTest extends TenantTestCase
{
    private function mp4(int $kb = 200): UploadedFile
    {
        $bytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", $kb * 1024);
        $path = tempnam(sys_get_temp_dir(), 'vid');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'clip.mp4', 'video/mp4', null, true);
    }

    /** @return array<string, mixed> */
    private function form(MenuItem $item, array $extra = []): array
    {
        return $extra + ['category_id' => $item->category_id, 'name' => $item->name, 'price' => $item->price, 'is_active' => 1];
    }

    public function test_an_uploaded_video_is_stored_and_shown_in_the_item_sheet(): void
    {
        Storage::fake('public');
        $a = $this->restaurant('cafe-a', 'basic');
        $item = $this->item($a, 'Latte', 60);
        $this->actingAs($this->owner($a))->post($this->dashUrl("/menu/items/{$item->id}"), $this->form($item, ['video' => $this->mp4()]))
            ->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertMatchesRegularExpression('#^tenants/cafe-a/videos/[0-9a-f-]{36}\.mp4$#', $item->video);
        Storage::disk('public')->assertExists($item->video);

        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-sheet-video-play', false)
            ->assertSee('"video":{"type":"file","src":"\/storage\/'.str_replace('/', '\/', $item->video).'"}', false);

        // removing it deletes the file
        $old = $item->video;
        $this->actingAs($this->owner($a))->post($this->dashUrl("/menu/items/{$item->id}"), $this->form($item, ['remove_video' => 1]));
        $this->assertNull($item->fresh()->video);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_wrong_files_and_sizes_are_refused(): void
    {
        Storage::fake('public');
        $a = $this->restaurant('cafe-a', 'basic');
        $item = $this->item($a);
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl("/menu/items/{$item->id}"), $this->form($item, ['video' => UploadedFile::fake()->create('clip.mp4', 100, 'video/mp4')]))
            ->assertSessionHasErrors('video'); // no MP4 header inside
        $this->actingAs($owner)->post($this->dashUrl("/menu/items/{$item->id}"), $this->form($item, ['video' => $this->mp4(16 * 1024)]))
            ->assertSessionHasErrors('video'); // over 15 MB
        $this->actingAs($owner)->post($this->dashUrl("/menu/items/{$item->id}"), $this->form($item, ['video_url' => 'https://vimeo.com/123']))
            ->assertSessionHasErrors('video_url');
        $this->assertNull($item->fresh()->video);
    }

    public function test_links_become_lazy_embeds_and_the_feature_gates_videos(): void
    {
        $a = $this->restaurant('cafe-a', 'basic');
        $item = $this->item($a);
        $this->actingAs($this->owner($a))->post($this->dashUrl("/menu/items/{$item->id}"), $this->form($item, ['video_url' => 'https://youtu.be/dQw4w9WgXcQ']))
            ->assertSessionHasNoErrors();
        $this->assertSame('https://youtu.be/dQw4w9WgXcQ', $item->fresh()->video_url);
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('youtube-nocookie.com\/embed\/dQw4w9WgXcQ?autoplay=1&mute=1', false);

        $this->assertSame('https://www.instagram.com/reel/C1abcDEF/embed', ItemVideo::embed('https://www.instagram.com/reel/C1abcDEF/?igsh=x')['embed']);
        $this->assertSame('https://www.tiktok.com/embed/v2/7212345678901234567', ItemVideo::embed('https://www.tiktok.com/@cafe/video/7212345678901234567')['embed']);
        $this->assertNull(ItemVideo::embed('https://example.com/watch?v=dQw4w9WgXcQ'));

        // starter has no full_branding: nothing saved, nothing shown
        $s = $this->restaurant('cafe-s', 'starter');
        $tea = $this->item($s, 'Tea', 20);
        $this->as($s, fn () => MenuItem::query()->whereKey($tea->id)->update(['video_url' => 'https://youtu.be/dQw4w9WgXcQ']));
        $this->get($this->subUrl('cafe-s'))->assertOk()->assertDontSee('youtube-nocookie', false);
        $this->actingAs($this->owner($s))->post($this->dashUrl("/menu/items/{$tea->id}"), ['category_id' => $this->as($s, fn () => Category::query()->value('id')), 'name' => 'Tea', 'price' => 20, 'video_url' => 'https://youtu.be/aaaaaaaaaaa']);
        $this->assertSame('https://youtu.be/dQw4w9WgXcQ', $this->as($s, fn () => MenuItem::query()->find($tea->id)->video_url));
    }
}
