<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Times are UTC everywhere; the local_* helpers put Cairo's wall clock (with its daylight saving) at the edges. */
class LocalTimeTest extends TestCase
{
    public function test_the_app_runs_in_utc_and_the_restaurants_in_cairo(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
        $this->assertSame('Africa/Cairo', local_timezone());
    }

    public function test_business_days_start_at_cairo_midnight_on_both_sides_of_daylight_saving(): void
    {
        $utc = fn (Carbon $at) => [$at->getTimezone()->getName(), $at->format('Y-m-d H:i:s')];

        // summer time ends at midnight between Thursday 29 and Friday 30 October 2026: Thursday has 25 hours
        $this->assertSame(['UTC', '2026-10-28 21:00:00'], $utc(local_day_start('2026-10-29')));
        $this->assertSame(['UTC', '2026-10-29 21:59:59'], $utc(local_day_end('2026-10-29')));
        $this->assertSame(['UTC', '2026-10-29 22:00:00'], $utc(local_day_start('2026-10-30')));
        // it starts at midnight between Thursday 23 and Friday 24 April 2026: Friday has 23 hours
        $this->assertSame(['UTC', '2026-04-23 22:00:00'], $utc(local_day_start('2026-04-24')));
        $this->assertSame(['UTC', '2026-04-24 20:59:59'], $utc(local_day_end('2026-04-24')));
    }

    public function test_the_day_and_hour_of_a_moment_are_read_on_the_cairo_clock(): void
    {
        // 21:30 UTC is already the next day in Cairo, in summer and in winter
        $this->assertSame('2026-07-02', local_today(Carbon::parse('2026-07-01 21:30:00')));
        $this->assertSame('2026-12-02', local_today('2026-12-01 22:30:00'));
        // a moment's business day: the Cairo date it falls on
        $this->assertSame(['UTC', '2026-07-01 21:00:00'], [local_day_start('2026-07-01 21:30:00')->getTimezone()->getName(), local_day_start('2026-07-01 21:30:00')->format('Y-m-d H:i:s')]);

        // the repeated hour: 20:30 and 21:30 UTC are both 23:30 on the Cairo clock, the second time an hour later
        $this->assertSame(['23:30 +03:00', '23:30 +02:00'], [local_at('2026-10-29 20:30:00')->format('H:i P'), local_at('2026-10-29 21:30:00')->format('H:i P')]);

        $this->travelTo(Carbon::parse('2026-10-29 22:15:00'));
        $this->assertSame(['2026-10-30', '00:15'], [local_today(), local_at()->format('H:i')]);
        app()->setLocale('ar');
        $this->assertSame('30/10 12:15 ص', local_time(now(), 'd/m h:i A'));
    }

    public function test_typed_times_are_read_on_the_cairo_clock_and_stored_as_utc(): void
    {
        $this->assertSame(['UTC', '2026-07-01 18:30:00'], [local_input('2026-07-01 21:30')->getTimezone()->getName(), local_input('2026-07-01 21:30')->format('Y-m-d H:i:s')]);
        $this->assertSame('2026-12-01 19:30:00', local_input('2026-12-01T21:30')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-01 19:30:00', local_input('01/12/2026 21:30', 'd/m/Y H:i')->format('Y-m-d H:i:s'));
    }

    public function test_the_schedule_runs_at_restaurant_time(): void
    {
        $billing = collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains($e->command, 'egmenu:billing'));

        // 03:00 in Cairo: 00:00 UTC in summer, 01:00 UTC in winter
        $this->travelTo(Carbon::parse('2026-07-01 00:00:00'));
        $this->assertTrue($billing->isDue($this->app));
        $this->travelTo(Carbon::parse('2026-12-01 00:00:00'));
        $this->assertFalse($billing->isDue($this->app));
        $this->travelTo(Carbon::parse('2026-12-01 01:00:00'));
        $this->assertTrue($billing->isDue($this->app));
    }
}
