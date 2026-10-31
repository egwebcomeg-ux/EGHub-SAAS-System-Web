<?php

namespace Tests\Feature;

use App\Models\Season;
use App\Services\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** Seasonal looks of the menu: running by the restaurant's dates, one at a time, greeting, banner, logo and accent. */
class SeasonsTest extends TenantTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_owner_adds_a_ramadan_season_with_preset_defaults(): void
    {
        Storage::fake('public');
        $a = $this->restaurant('cafe-a');
        $this->item($a);
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl('/marketing/seasons'), [
            'name' => 'Ramadan 2026', 'preset' => 'ramadan', 'starts_on' => local_at()->subDay()->toDateString(), 'ends_on' => local_at()->addDays(20)->toDateString(),
            'decoration' => '1', 'banner' => UploadedFile::fake()->image('banner.jpg', 1600, 700), 'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
        ])->assertSessionHasNoErrors();

        $season = $this->as($a, fn () => Season::query()->first());
        $this->assertSame('رمضان كريم', $season->greeting);
        $this->assertSame('#0F6B4F', $season->accent);
        Storage::disk('public')->assertExists($season->banner);
        $this->assertStringStartsWith('tenants/cafe-a/', $season->logo);

        $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee('data-ticker-line="season"', false)->assertSee('رمضان كريم')
            ->assertSee('data-season-deco="crescent"', false)->assertSee('data-season-banner', false)
            ->assertSee('/storage/'.$season->logo, false)
            ->assertSee('--menu-accent: #0F6B4F;', false)->assertSee('--menu-accent-text: #FFFFFF;', false);
        // every guest page follows the season accent
        $this->get($this->subUrl('cafe-a', 'links'))->assertOk()->assertSee('--menu-accent: #0F6B4F;', false);
        $this->actingAs($owner)->get($this->dashUrl('/marketing'))->assertOk()->assertSee('Ramadan 2026');
    }

    public function test_only_the_latest_started_season_runs_by_the_restaurant_date(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a);
        $this->as($a, function () {
            Season::query()->create(['name' => 'Long', 'preset' => 'summer', 'starts_on' => '2026-06-01', 'ends_on' => '2026-09-30', 'greeting' => 'Summer!', 'accent' => '#0284C7']);
            Season::query()->create(['name' => 'Eid', 'preset' => 'eid', 'starts_on' => '2026-06-15', 'ends_on' => '2026-06-18', 'greeting' => 'Eid!', 'accent' => '#B8860B']);
        });

        // 23:30 UTC on 14 June is already 15 June in Cairo: the Eid season (started last) wins
        Carbon::setTestNow(Carbon::parse('2026-06-14 23:30:00', 'UTC'));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('Eid!')->assertDontSee('Summer!')->assertSee('--menu-accent: #B8860B;', false);

        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00', 'Africa/Cairo'));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('Summer!')->assertDontSee('Eid!');

        // paused or ended: nothing, the theme accent is back
        $this->as($a, fn () => Season::query()->update(['is_active' => false]));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-ticker-line="season"', false)->assertSee('--menu-accent: #F26522;', false);
    }

    public function test_dark_themes_keep_their_surfaces_and_the_feature_gates_seasons(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $this->item($a);
        $this->as($a, function () {
            Settings::put(['menu_theme' => 'bistro']);
            Season::query()->create(['name' => 'R', 'preset' => 'ramadan', 'starts_on' => local_today(), 'ends_on' => local_today(), 'greeting' => 'رمضان كريم', 'accent' => '#0F6B4F']);
        });
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('--menu-surface: #1E1E1E;', false)->assertSee('--menu-accent: #0F6B4F;', false);

        $b = $this->restaurant('cafe-b', 'basic');
        $this->item($b);
        $this->as($b, fn () => Season::query()->create(['name' => 'R', 'preset' => 'ramadan', 'starts_on' => local_today(), 'ends_on' => local_today(), 'greeting' => 'رمضان كريم', 'accent' => '#0F6B4F']));
        $this->get($this->subUrl('cafe-b'))->assertOk()->assertDontSee('data-ticker-line="season"', false)->assertDontSee('#0F6B4F', false);
        $this->actingAs($this->owner($b))->post($this->dashUrl('/marketing/seasons'), ['name' => 'X', 'preset' => 'eid', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-02'])->assertForbidden();
    }
}
