<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\TvScreen;
use Tests\TenantTestCase;

class TvScreensTest extends TenantTestCase
{
    public function test_screen_shows_the_menu_and_its_version_changes_with_prices(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $latte = $this->item($a, 'Latte', 60);
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl('/tv-screens'), [
            'name' => 'Counter', 'layout' => 'rotate', 'columns' => 2, 'rotate_seconds' => 10, 'theme' => 'dark', 'show_images' => 1,
        ])->assertSessionHasNoErrors();
        $screen = $this->as($a, fn () => TvScreen::query()->first());
        $this->actingAs($owner)->get($this->dashUrl('/tv-screens'))->assertOk()->assertSee($screen->token);

        $this->get($this->subUrl('cafe-a', "tv/{$screen->token}"))->assertOk()->assertSee('Latte')->assertSee('60');
        $before = $this->getJson($this->subUrl('cafe-a', "tv/{$screen->token}/version"))->assertOk()->json('v');

        $this->as($a, fn () => MenuItem::query()->whereKey($latte->id)->update(['price' => 70]));
        $after = $this->getJson($this->subUrl('cafe-a', "tv/{$screen->token}/version"))->json('v');
        $this->assertNotSame($before, $after);

        // a new link retires the old one
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$screen->id}/link"));
        $this->get($this->subUrl('cafe-a', "tv/{$screen->token}"))->assertNotFound();
    }

    public function test_tokens_and_categories_are_per_restaurant_and_pro_has_no_screens(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $b = $this->restaurant('cafe-b', 'business');
        $foreign = $this->item($b, 'Foreign', 10);
        $screenB = $this->as($b, fn () => TvScreen::query()->create(['name' => 'B', 'token' => random_token(10)]));

        // B's screen link under A's address does not open
        $this->get($this->subUrl('cafe-a', "tv/{$screenB->token}"))->assertNotFound();
        // another restaurant's category cannot be picked
        $this->actingAs($this->owner($a))->post($this->dashUrl('/tv-screens'), [
            'name' => 'X', 'layout' => 'all', 'columns' => 3, 'rotate_seconds' => 10, 'theme' => 'light', 'category_ids' => [$foreign->category_id],
        ])->assertSessionHasErrors('category_ids.0');

        $pro = $this->restaurant('cafe-pro', 'pro');
        $screenPro = $this->as($pro, fn () => TvScreen::query()->create(['name' => 'P', 'token' => random_token(10)]));
        $this->get($this->subUrl('cafe-pro', "tv/{$screenPro->token}"))->assertNotFound();
        $this->actingAs($this->owner($pro))->get($this->dashUrl('/tv-screens'))->assertForbidden();
    }
}
