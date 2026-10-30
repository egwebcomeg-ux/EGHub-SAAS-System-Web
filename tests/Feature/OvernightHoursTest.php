<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Reservation;
use App\Services\Settings;
use App\Support\OpeningHours;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Tests\TenantTestCase;

/** Working hours that end after midnight (20:00–03:00) count as open after midnight everywhere hours are read. */
class OvernightHoursTest extends TenantTestCase
{
    public function test_open_now_follows_an_overnight_window(): void
    {
        $tz = local_timezone();
        $this->assertSame(['20:00', '03:00'], OpeningHours::window('8 PM – 3 AM'));
        $this->assertSame(['20:00', '03:00'], OpeningHours::window('٨ م - ٣ ص'));
        $this->assertNull(OpeningHours::window('whenever we feel like it'));

        $this->assertTrue(OpeningHours::isOpenAt('8 PM – 3 AM', Carbon::parse('2026-10-20 23:00', $tz)));
        $this->assertTrue(OpeningHours::isOpenAt('8 PM – 3 AM', Carbon::parse('2026-10-21 01:30', $tz)));  // after midnight
        $this->assertFalse(OpeningHours::isOpenAt('8 PM – 3 AM', Carbon::parse('2026-10-21 03:00', $tz)));
        $this->assertFalse(OpeningHours::isOpenAt('8 PM – 3 AM', Carbon::parse('2026-10-21 12:00', $tz)));
        $this->assertTrue(OpeningHours::isOpenAt('09:00 - 17:00', Carbon::parse('2026-10-21 12:00', $tz)));
        $this->assertFalse(OpeningHours::isOpenAt('09:00 - 17:00', Carbon::parse('2026-10-21 17:00', $tz)));
        $this->assertTrue(OpeningHours::isOpenAt('24 hours', Carbon::parse('2026-10-21 04:00', $tz)));
        $this->assertNull(OpeningHours::isOpenAt('', now()));
    }

    public function test_menu_and_profile_show_open_after_midnight(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a);
        $this->as($a, fn () => Branch::query()->firstOrFail()->update(['working_hours' => '8 PM – 3 AM']));

        $this->travelTo(Carbon::parse('2026-10-21 01:30', local_timezone()));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-open-now', false)->assertSee(__('Open now'))->assertDontSee(__('Closed now'));
        $this->get($this->subUrl('cafe-a', 'about'))->assertOk()->assertSee(__('Open now'));

        $this->travelTo(Carbon::parse('2026-10-21 12:00', local_timezone()));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee(__('Closed now'));

        // no readable hours: no badge at all
        $this->as($a, fn () => Branch::query()->firstOrFail()->update(['working_hours' => 'كل يوم تقريباً']));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-open-now', false);
    }

    public function test_reservations_accept_and_book_an_overnight_window(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $a = $this->restaurant('cafe-a', 'pro');
        $owner = $this->owner($a);
        $this->travelTo(Carbon::parse('2026-10-20 10:00', local_timezone())); // Tuesday

        // custom reservation hours 20:00–03:00 every day are accepted by the settings form
        $hours = array_fill(0, 7, ['open' => '20:00', 'close' => '03:00']);
        $this->actingAs($owner)->post($this->dashUrl('/reservations/settings'), [
            'reservations_enabled' => 1, 'slot_minutes' => 30, 'duration_minutes' => 90, 'max_party' => 12, 'lead_hours' => 1, 'horizon_days' => 30,
            'hours_mode' => 'custom', 'hours' => $hours,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('03:00', $this->as($a, fn () => json_decode(Settings::get('reservation_hours'), true)[2]['close']));

        // the slots of Tuesday run past midnight; 01:00 (Wednesday) is one of them and can be booked
        $branch = $this->as($a, fn () => Branch::query()->firstOrFail());
        $this->get($this->pathUrl('cafe-a', 'reserve?date=2026-10-20&party=2'))->assertOk()->assertSee('value="01:00"', false)->assertSee('value="22:30"', false)->assertDontSee('value="12:00"', false);
        $this->post($this->pathUrl('cafe-a', 'reserve'), [
            'branch_id' => $branch->id, 'date' => '2026-10-21', 'time' => '01:00', 'party_size' => 2, 'name' => 'Mona', 'phone' => '01001234567',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $r = $this->as($a, fn () => Reservation::query()->firstOrFail());
        $this->assertSame('2026-10-21 01:00', $r->starts_at->timezone(local_timezone())->format('Y-m-d H:i'));

        // noon is outside the window
        $this->post($this->pathUrl('cafe-a', 'reserve'), [
            'branch_id' => $branch->id, 'date' => '2026-10-21', 'time' => '12:00', 'party_size' => 2, 'name' => 'Mona', 'phone' => '01001234567',
        ])->assertSessionHasErrors();

        // the branch's own overnight working hours work the same way when no custom hours are set
        $this->as($a, function () {
            Settings::put(['reservation_hours' => '']);
            Branch::query()->firstOrFail()->update(['working_hours' => '8 PM – 3 AM']);
        });
        $this->get($this->pathUrl('cafe-a', 'reserve?date=2026-10-20&party=2'))->assertOk()->assertSee('value="01:00"', false)->assertDontSee('value="12:00"', false);
    }
}
