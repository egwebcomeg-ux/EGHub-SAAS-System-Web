<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\BranchItem;
use App\Models\Tenant;
use App\Models\TvGroup;
use App\Models\TvScreen;
use App\Models\TvSlide;
use App\Models\User;
use Tests\TenantTestCase;

/** EgHub screens: groups with one shared playlist, copying a playlist, split areas, and who may do it. */
class ScreenGroupsTest extends TenantTestCase
{
    private Tenant $cafe;

    private Branch $main;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cafe = $this->restaurant('cafe-a', 'business');
        [$this->main, $this->second] = $this->as($this->cafe, fn () => [
            Branch::query()->first(),
            Branch::query()->create(['name' => 'Maadi', 'slug' => 'maadi', 'is_active' => true]),
        ]);
    }

    /** @return array{0: TvScreen, 1: string} a paired screen and its device token */
    private function screen(string $name, ?Branch $branch = null): array
    {
        return $this->as($this->cafe, function () use ($name, $branch) {
            $screen = TvScreen::query()->create(['name' => $name, 'token' => random_token(10), 'branch_id' => $branch?->id]);

            return [$screen, $screen->issueDeviceToken()];
        });
    }

    private function playlist(string $token): array
    {
        return $this->getJson($this->menuUrl('screen/playlist'), ['X-Screen-Token' => $token])->assertOk()->json();
    }

    private function branchManager(Branch $branch): User
    {
        $user = $this->staff($this->cafe, 'manager', 'manager@cafe-a.test');
        $user->forceFill(['branch_id' => $branch->id])->save();

        return $user;
    }

    public function test_a_group_playlist_plays_on_every_screen_of_the_group_with_each_branch_price(): void
    {
        app(Billing::class)->setAddon($this->cafe, 'multibranch_pack', 1);
        $latte = $this->item($this->cafe, 'Latte', 60);
        $this->as($this->cafe, fn () => BranchItem::query()->create(['branch_id' => $this->second->id, 'menu_item_id' => $latte->id, 'price' => 70]));
        [$zamalek, $tokenA] = $this->screen('Zamalek', $this->main);
        [$maadi, $tokenB] = $this->screen('Maadi', $this->second);
        [$alone, $tokenC] = $this->screen('Alone', $this->main);
        $owner = $this->owner($this->cafe);

        $this->actingAs($owner)->post($this->dashUrl('/tv-groups'), ['name' => 'Cashiers'])->assertRedirect();
        $group = $this->as($this->cafe, fn () => TvGroup::query()->firstOrFail());
        $this->actingAs($owner)->post($this->dashUrl("/tv-groups/{$group->id}"), ['name' => 'Cashiers', 'screen_ids' => [$zamalek->id, $maadi->id]])
            ->assertSessionHasNoErrors();
        $before = $this->getJson($this->menuUrl('screen/version'), ['X-Screen-Token' => $tokenA])->json('v');
        $this->actingAs($owner)->post($this->dashUrl("/tv-groups/{$group->id}/slides"), ['type' => 'menu', 'duration' => 15, 'layout' => 'list'])
            ->assertSessionHasNoErrors();

        $this->assertNotSame($before, $this->getJson($this->menuUrl('screen/version'), ['X-Screen-Token' => $tokenA])->json('v'));
        $a = $this->playlist($tokenA)['slides'];
        $b = $this->playlist($tokenB)['slides'];
        $this->assertSame(['menu'], array_column($a, 'type'));
        $this->assertSame('60', $a[0]['categories'][0]['items'][0]['price']);
        $this->assertSame('70', $b[0]['categories'][0]['items'][0]['price']); // the same slide, the Maadi price
        // a screen outside the group still shows its whole menu (no slides of its own)
        $this->assertSame(0, $this->playlist($tokenC)['slides'][0]['id']);

        // a screen of the group may keep its own playlist
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$maadi->id}/slides"), ['type' => 'ready', 'duration' => 10])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$maadi->id}/settings"), ['name' => 'Maadi', 'orientation' => 'landscape', 'theme' => 'dark', 'tv_group_id' => $group->id, 'playlist' => 'own', 'branch_id' => $this->second->id, 'is_active' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame(['ready'], array_column($this->playlist($tokenB)['slides'], 'type'));
        $this->actingAs($owner)->get($this->dashUrl("/tv-screens/{$zamalek->id}"))->assertOk()->assertSee('data-follows-group="'.$group->id.'"', false);

        // deleting the group: its screens play their own playlists again
        $this->actingAs($owner)->post($this->dashUrl("/tv-groups/{$group->id}/delete"))->assertRedirect();
        $this->assertNull($this->as($this->cafe, fn () => $zamalek->fresh()->tv_group_id));
        $this->assertSame(0, $this->playlist($tokenA)['slides'][0]['id']);
    }

    public function test_a_playlist_is_copied_to_the_ticked_screens_and_replaces_theirs(): void
    {
        $this->item($this->cafe, 'Latte', 60);
        [$source] = $this->screen('Source', $this->main);
        [$target1, $token1] = $this->screen('One', $this->main);
        [$target2] = $this->screen('Two', $this->second);
        [$untouched] = $this->screen('Three', $this->second);
        $this->as($this->cafe, function () use ($source, $target1) {
            $source->update(['zone_layout' => 'side']);
            $source->slides()->create(['type' => 'menu', 'duration' => 20, 'zone' => 'main', 'sort_order' => 1]);
            $source->slides()->create(['type' => 'promo', 'duration' => 8, 'zone' => 'side', 'template' => 'headline', 'options' => ['title' => 'Open late'], 'sort_order' => 2]);
            $target1->slides()->create(['type' => 'ready', 'duration' => 10]);
        });

        $this->actingAs($this->owner($this->cafe))->post($this->dashUrl("/tv-screens/{$source->id}/copy"), ['screen_ids' => [$target1->id, $target2->id, $source->id]])
            ->assertSessionHasNoErrors()->assertSessionHas('ok');

        $this->as($this->cafe, function () use ($target1, $target2, $untouched, $source) {
            foreach ([$target1, $target2] as $t) {
                $this->assertSame(['menu', 'promo'], $t->slides()->pluck('type')->all());
                $this->assertSame('side', $t->fresh()->zone_layout);
            }
            $this->assertSame(0, $untouched->slides()->count());
            $this->assertSame(2, $source->slides()->count()); // the source is not copied onto itself
        });
        $slides = $this->playlist($token1)['slides'];
        $this->assertSame(['main', 'side'], array_column($slides, 'zone'));
        $this->assertSame('side', $this->playlist($token1)['screen']['zones']);

        $this->actingAs($this->owner($this->cafe))->post($this->dashUrl("/tv-screens/{$source->id}/copy"), ['screen_ids' => []])->assertSessionHasErrors('screen_ids');
    }

    public function test_slides_play_in_their_area_only_when_the_screen_is_split(): void
    {
        $this->item($this->cafe, 'Latte', 60);
        [$screen, $token] = $this->screen('Counter', $this->main);
        $owner = $this->owner($this->cafe);
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$screen->id}/slides"), ['type' => 'menu', 'duration' => 15, 'zone' => 'side'])->assertSessionHasNoErrors();

        $this->assertSame('full', $this->playlist($token)['screen']['zones']);
        $this->assertSame('main', $this->playlist($token)['slides'][0]['zone']); // one area: everything plays in it

        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$screen->id}/zones"), ['zone_layout' => 'halves'])->assertRedirect();
        $this->assertSame('halves', $this->playlist($token)['screen']['zones']);
        $this->assertSame('side', $this->playlist($token)['slides'][0]['zone']);
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$screen->id}/zones"), ['zone_layout' => 'mosaic'])->assertSessionHasErrors('zone_layout');
    }

    public function test_another_restaurants_groups_and_screens_cannot_be_touched(): void
    {
        $other = $this->restaurant('cafe-b', 'business');
        [$theirScreen, $theirGroup, $theirSlide] = $this->as($other, function () {
            $screen = TvScreen::query()->create(['name' => 'B', 'token' => random_token(10)]);
            $group = TvGroup::query()->create(['name' => 'B group']);
            $screen->update(['tv_group_id' => $group->id]);

            return [$screen, $group, $group->slides()->create(['type' => 'menu', 'duration' => 10])];
        });
        [$mine] = $this->screen('Mine', $this->main);
        $myGroup = $this->as($this->cafe, fn () => TvGroup::query()->create(['name' => 'A group']));
        $owner = $this->owner($this->cafe);

        $this->actingAs($owner)->get($this->dashUrl("/tv-groups/{$theirGroup->id}"))->assertNotFound();
        $this->actingAs($owner)->post($this->dashUrl("/tv-groups/{$theirGroup->id}/slides"), ['type' => 'menu', 'duration' => 10])->assertNotFound();
        $this->actingAs($owner)->post($this->dashUrl("/tv-groups/{$theirGroup->id}/delete"))->assertNotFound();
        $this->actingAs($owner)->post($this->dashUrl("/tv-groups/{$myGroup->id}/slides/{$theirSlide->id}/delete"))->assertNotFound();
        // their screen id is ignored when filling my group or copying my playlist
        $this->actingAs($owner)->post($this->dashUrl("/tv-groups/{$myGroup->id}"), ['name' => 'A group', 'screen_ids' => [$mine->id, $theirScreen->id]])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$mine->id}/copy"), ['screen_ids' => [$theirScreen->id]])->assertSessionHasErrors('screen_ids');
        // the settings form cannot put my screen in their group
        $this->actingAs($owner)->post($this->dashUrl("/tv-screens/{$mine->id}/settings"), ['name' => 'Mine', 'orientation' => 'landscape', 'theme' => 'dark', 'tv_group_id' => $theirGroup->id])
            ->assertSessionHasErrors('tv_group_id');

        $fresh = TvScreen::query()->withoutGlobalScopes()->find($theirScreen->id);
        $this->assertSame($theirGroup->id, $fresh->tv_group_id);
        $this->assertSame(0, TvSlide::query()->withoutGlobalScopes()->where('tv_screen_id', $theirScreen->id)->count());
        $this->assertTrue(TvSlide::query()->withoutGlobalScopes()->whereKey($theirSlide->id)->exists());
        $this->assertSame([$mine->id], $this->as($this->cafe, fn () => $myGroup->screens()->pluck('id')->all()));
    }

    public function test_staff_tied_to_a_branch_reach_only_that_branchs_screens_and_no_groups(): void
    {
        [$mine] = $this->screen('Counter screen', $this->main);
        [$theirs] = $this->screen('Door screen', $this->second);
        $group = $this->as($this->cafe, fn () => TvGroup::query()->create(['name' => 'All']));
        $manager = $this->branchManager($this->main);

        $this->actingAs($manager)->get($this->dashUrl("/tv-screens/{$mine->id}"))->assertOk();
        $this->actingAs($manager)->get($this->dashUrl("/tv-screens/{$theirs->id}"))->assertNotFound();
        $this->actingAs($manager)->post($this->dashUrl("/tv-screens/{$theirs->id}/slides"), ['type' => 'menu', 'duration' => 10])->assertNotFound();
        $this->actingAs($manager)->post($this->dashUrl('/tv-groups'), ['name' => 'Mine'])->assertForbidden();
        $this->actingAs($manager)->get($this->dashUrl("/tv-groups/{$group->id}"))->assertForbidden();
        $this->actingAs($manager)->post($this->dashUrl("/tv-screens/{$mine->id}/copy"), ['screen_ids' => [$theirs->id]])->assertSessionHasErrors('screen_ids');
        $this->actingAs($manager)->get($this->dashUrl('/devices?tab=tv'))->assertOk()->assertSee('Counter screen')->assertDontSee('Door screen');
        $this->assertSame(1, $this->as($this->cafe, fn () => TvGroup::query()->count()));
    }
}
