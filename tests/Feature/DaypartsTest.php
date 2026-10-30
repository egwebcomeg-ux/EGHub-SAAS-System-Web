<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Tenant;
use App\Services\Menu\MenuCatalog;
use Illuminate\Support\Carbon;
use Tests\TenantTestCase;

/** Dayparts: categories with a time window (may cross midnight) and weekdays show only inside them. */
class DaypartsTest extends TenantTestCase
{
    private function category(Tenant $tenant, string $name, array $attrs): Category
    {
        return $this->as($tenant, function () use ($name, $attrs) {
            $c = Category::query()->create(['name' => $name] + $attrs);
            MenuItem::query()->create(['category_id' => $c->id, 'name' => "$name item", 'price' => 50]);

            return $c;
        });
    }

    private function names(Tenant $tenant): array
    {
        return $this->as($tenant, fn () => MenuCatalog::forBranch(null)->categories()->pluck('name')->all());
    }

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse($time, local_timezone()));
    }

    public function test_windows_weekdays_and_overnight(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->category($a, 'All day', []);
        $this->category($a, 'Breakfast', ['visible_from' => '07:00', 'visible_until' => '12:00']);
        $this->category($a, 'Late night', ['visible_from' => '22:00', 'visible_until' => '03:00']);
        $this->category($a, 'Friday brunch', ['visible_days' => [5]]);

        $this->at('2026-10-20 09:00'); // Tuesday
        $this->assertSame(['All day', 'Breakfast'], $this->names($a));
        $this->at('2026-10-20 12:00');
        $this->assertSame(['All day'], $this->names($a));
        $this->at('2026-10-20 23:30');
        $this->assertSame(['All day', 'Late night'], $this->names($a));
        $this->at('2026-10-21 01:30'); // after midnight: still Tuesday's late night
        $this->assertSame(['All day', 'Late night'], $this->names($a));
        $this->at('2026-10-21 03:00');
        $this->assertSame(['All day'], $this->names($a));
        $this->at('2026-10-23 10:00'); // Friday
        $this->assertSame(['All day', 'Breakfast', 'Friday brunch'], $this->names($a));

        // the public menu, kiosk and TV hide the category; the POS and the printed menu keep it
        $this->at('2026-10-20 15:00');
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('All day')->assertDontSee('Breakfast');
        $this->assertContains('Breakfast', $this->as($a, fn () => MenuCatalog::forBranch(null)->allDay()->categories()->pluck('name')->all()));
        $this->actingAs($this->owner($a))->get($this->dashUrl('/menu/print'))->assertOk()->assertSee('Breakfast');
    }

    public function test_the_dashboard_saves_the_window_and_shows_a_clock_badge(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $c = $this->category($a, 'Breakfast', []);
        $post = fn (array $extra) => $this->actingAs($owner)->post($this->dashUrl("/menu/categories/{$c->id}"), $extra + ['name' => 'Breakfast', 'is_active' => 1]);

        $post(['visible_from' => '07:00', 'visible_until' => '12:00', 'visible_days' => [0, 1, 2, 3, 4]])->assertRedirect()->assertSessionHasNoErrors();
        $saved = $this->as($a, fn () => Category::query()->findOrFail($c->id));
        $this->assertSame('07:00', substr($saved->visible_from, 0, 5));
        $this->assertSame('12:00', substr($saved->visible_until, 0, 5));
        $this->assertSame([0, 1, 2, 3, 4], $saved->visible_days);
        $this->assertTrue($saved->hasSchedule());
        $this->actingAs($owner)->get($this->dashUrl('/menu'))->assertOk()->assertSee('🕒 07:00–12:00');

        // one time without the other is refused; all seven days means "every day" (null)
        $post(['visible_from' => '07:00'])->assertSessionHasErrors('visible_until');
        $post(['visible_days' => [0, 1, 2, 3, 4, 5, 6]])->assertRedirect()->assertSessionHasNoErrors();
        $saved = $this->as($a, fn () => Category::query()->findOrFail($c->id));
        $this->assertNull($saved->visible_from);
        $this->assertNull($saved->visible_days);
        $this->assertFalse($saved->hasSchedule());
    }
}
