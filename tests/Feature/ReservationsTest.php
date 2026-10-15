<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Services\Entitlements;
use App\Services\Notify\ReservationNotifier;
use App\Services\Settings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Tests\TenantTestCase;

/** Table reservations: guest booking on the menu host, capacity / lead / horizon rules, staff moves, isolation. */
class ReservationsTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Tuesday 20 Oct 2026, 10:00 in Cairo; with no readable working hours the branch takes bookings 12:00–23:00
        $this->travelTo(Carbon::parse('2026-10-20 10:00', config('app.timezone')));
        $this->withoutMiddleware(ThrottleRequests::class); // these tests post more bookings a minute than a guest may
    }

    /** A restaurant with reservations on (Pro has the feature) and its booking rules. */
    private function bookable(string $slug, array $settings = [], string $plan = 'pro'): Tenant
    {
        $tenant = $this->restaurant($slug, $plan);
        $this->as($tenant, fn () => Settings::put($settings + ['reservations_enabled' => '1']));

        return $tenant;
    }

    private function branchId(Tenant $tenant): int
    {
        return $this->as($tenant, fn () => Branch::query()->value('id'));
    }

    private function book(string $slug, Tenant $tenant, array $extra = [])
    {
        return $this->post($this->pathUrl($slug, 'reserve'), $extra + [
            'branch_id' => $this->branchId($tenant), 'date' => '2026-10-20', 'time' => '19:00', 'party_size' => 2,
            'name' => 'Mona', 'phone' => '0100 123 4567',
        ]);
    }

    public function test_guest_books_follows_and_cancels_with_the_token(): void
    {
        $a = $this->bookable('cafe-a');
        $this->get($this->pathUrl('cafe-a'))->assertOk()->assertSee($this->pathUrl('cafe-a', 'reserve'));
        $this->get($this->pathUrl('cafe-a', 'reserve?date=2026-10-20&party=2'))->assertOk()->assertSee('value="19:00"', false);

        $this->book('cafe-a', $a, ['notes' => 'Window please'])->assertRedirect();
        $r = $this->as($a, fn () => Reservation::query()->firstOrFail());
        $this->assertSame('pending', $r->status);
        $this->assertSame('guest', $r->source);
        $this->assertSame('01001234567', $r->phone);
        $this->assertSame('2026-10-20 20:30:00', $r->ends_at->format('Y-m-d H:i:s'));
        // the guest becomes a customer (Pro has the customer database)
        $this->assertSame($r->customer_id, $this->as($a, fn () => Customer::query()->where('phone', '01001234567')->value('id')));

        $this->get($this->pathUrl('cafe-a', "reserve/{$r->token}"))->assertOk()->assertSee('Mona')->assertSee('Window please');

        // it waits on the order board strip until staff answer it
        $owner = $this->owner($a);
        $this->actingAs($owner)->getJson($this->dashUrl('/orders/feed'))->assertOk()->assertJsonPath('reservations.0.id', $r->id);

        $this->post($this->pathUrl('cafe-a', "reserve/{$r->token}/cancel"))->assertRedirect();
        $this->assertSame('cancelled', $this->as($a, fn () => $r->fresh()->status));
        $this->actingAs($owner)->getJson($this->dashUrl('/orders/feed'))->assertJsonCount(0, 'reservations');
        // a cancelled booking cannot be cancelled again
        $this->post($this->pathUrl('cafe-a', "reserve/{$r->token}/cancel"))->assertSessionHasErrors('cancel');
    }

    public function test_auto_confirm_and_notifier_hooks(): void
    {
        $notifier = new class implements ReservationNotifier
        {
            public array $events = [];

            public function created(Reservation $reservation): void
            {
                $this->events[] = 'created:'.$reservation->status;
            }

            public function statusChanged(Reservation $reservation, string $from): void
            {
                $this->events[] = "$from>{$reservation->status}";
            }
        };
        $this->app->instance(ReservationNotifier::class, $notifier);

        $a = $this->bookable('cafe-a', ['reservation_auto_confirm' => '1']);
        $this->book('cafe-a', $a)->assertRedirect();
        $r = $this->as($a, fn () => Reservation::query()->firstOrFail());
        $this->assertSame('confirmed', $r->status);
        $this->post($this->pathUrl('cafe-a', "reserve/{$r->token}/cancel"));
        $this->assertSame(['created:confirmed', 'confirmed>cancelled'], $notifier->events);
    }

    public function test_slot_capacity_is_enforced_over_the_whole_stay(): void
    {
        $a = $this->bookable('cafe-a', ['reservation_seats' => '', 'reservation_duration_minutes' => '90']);
        $branch = $this->branchId($a);
        $this->as($a, fn () => Settings::put(['reservation_seats' => json_encode([$branch => 4])]));

        $this->book('cafe-a', $a, ['time' => '19:00', 'party_size' => 3])->assertSessionHasNoErrors();
        // 19:30 overlaps the 19:00 table (until 20:30): only one seat left
        $this->book('cafe-a', $a, ['time' => '19:30', 'party_size' => 2, 'phone' => '01111111111'])->assertSessionHasErrors('time');
        $this->book('cafe-a', $a, ['time' => '18:00', 'party_size' => 2, 'phone' => '01111111111'])->assertSessionHasErrors('time');
        $this->book('cafe-a', $a, ['time' => '19:30', 'party_size' => 1, 'phone' => '01222222222'])->assertSessionHasNoErrors();
        // once the 19:00 table is free again the seats come back
        $this->book('cafe-a', $a, ['time' => '20:30', 'party_size' => 3, 'phone' => '01555555555'])->assertSessionHasNoErrors();
        $this->assertSame(3, $this->as($a, fn () => Reservation::query()->count()));

        // the guest page shows the full slot as unavailable for that party size
        $page = $this->get($this->pathUrl('cafe-a', 'reserve?date=2026-10-20&party=2'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/value="19:30"[^>]*disabled/', $page);

        // a cancelled booking gives its seats back; staff are held to the seats too
        $this->as($a, fn () => Reservation::query()->where('party_size', 3)->whereTime('starts_at', '19:00:00')->update(['status' => 'cancelled']));
        $this->actingAs($this->owner($a))->post($this->dashUrl('/reservations'), [
            'branch_id' => $branch, 'date' => '2026-10-20', 'time' => '19:00', 'party_size' => 5, 'name' => 'Big', 'phone' => '01066666666',
        ])->assertSessionHasErrors('time');
        $this->actingAs($this->owner($a))->post($this->dashUrl('/reservations'), [
            'branch_id' => $branch, 'date' => '2026-10-20', 'time' => '19:00', 'party_size' => 3, 'name' => 'Walk-in', 'phone' => '01066666666',
        ])->assertSessionHasNoErrors();
    }

    public function test_lead_time_horizon_hours_and_party_size_are_enforced(): void
    {
        $a = $this->bookable('cafe-a', ['reservation_lead_hours' => '3', 'reservation_horizon_days' => '10', 'reservation_max_party' => '6']);

        $this->book('cafe-a', $a, ['time' => '12:30'])->assertSessionHasErrors('time');   // 2.5 h ahead
        $this->book('cafe-a', $a, ['date' => '2026-10-31'])->assertSessionHasErrors('time'); // 11 days ahead
        $this->book('cafe-a', $a, ['time' => '23:30'])->assertSessionHasErrors('time');   // after closing
        $this->book('cafe-a', $a, ['time' => '19:10'])->assertSessionHasErrors('time');   // not a slot
        $this->book('cafe-a', $a, ['party_size' => 7])->assertSessionHasErrors('time');
        $this->book('cafe-a', $a, ['phone' => '12345'])->assertSessionHasErrors('time');
        $this->assertSame(0, $this->as($a, fn () => Reservation::query()->count()));

        $this->book('cafe-a', $a, ['time' => '13:00'])->assertSessionHasNoErrors();
        $this->book('cafe-a', $a, ['date' => '2026-10-30', 'phone' => '01111111111'])->assertSessionHasNoErrors();

        // custom hours: closed on Tuesdays
        $hours = array_fill(0, 7, ['open' => '18:00', 'close' => '23:00']);
        $hours[2] = ['open' => '', 'close' => ''];
        $this->as($a, fn () => Settings::put(['reservation_hours' => json_encode($hours)]));
        $this->book('cafe-a', $a, ['time' => '20:00', 'phone' => '01222222222'])->assertSessionHasErrors('time');
        $this->book('cafe-a', $a, ['date' => '2026-10-21', 'time' => '20:00', 'phone' => '01222222222'])->assertSessionHasNoErrors();
        $this->book('cafe-a', $a, ['date' => '2026-10-21', 'time' => '13:00', 'phone' => '01222222222'])->assertSessionHasErrors('time');
    }

    public function test_staff_book_by_hand_and_move_bookings(): void
    {
        $a = $this->bookable('cafe-a', ['reservation_lead_hours' => '5']);
        $owner = $this->owner($a);
        $branch = $this->branchId($a);

        // by hand: confirmed at once, lead time doesn't apply
        $this->actingAs($owner)->post($this->dashUrl('/reservations'), [
            'branch_id' => $branch, 'date' => '2026-10-20', 'time' => '12:00', 'party_size' => 4, 'name' => 'Phone guest', 'phone' => '01012345678',
        ])->assertSessionHasNoErrors();
        $r = $this->as($a, fn () => Reservation::query()->firstOrFail());
        $this->assertSame(['confirmed', 'staff'], [$r->status, $r->source]);
        $this->actingAs($owner)->get($this->dashUrl('/reservations?date=2026-10-20'))->assertOk()->assertSee('Phone guest');

        $this->actingAs($owner)->postJson($this->dashUrl("/reservations/{$r->id}/status"), ['status' => 'seated'])->assertOk();
        $this->assertNotNull($this->as($a, fn () => $r->fresh()->seated_at));
        $this->actingAs($owner)->postJson($this->dashUrl("/reservations/{$r->id}/status"), ['status' => 'cancelled'])->assertStatus(422);

        $this->book('cafe-a', $a, ['time' => '20:00', 'phone' => '01111111111']);
        $guest = $this->as($a, fn () => Reservation::query()->where('source', 'guest')->firstOrFail());
        $this->actingAs($owner)->post($this->dashUrl("/reservations/{$guest->id}/status"), ['status' => 'no_show'])->assertRedirect();
        $this->assertSame('no_show', $this->as($a, fn () => $guest->fresh()->status));

        // the rules form
        $this->actingAs($owner)->post($this->dashUrl('/reservations/settings'), [
            'reservations_enabled' => 1, 'slot_minutes' => 15, 'duration_minutes' => 120, 'max_party' => 8, 'lead_hours' => 2,
            'horizon_days' => 14, 'hours_mode' => 'custom', 'hours' => [5 => ['open' => '13:00', 'close' => '01:00']], 'seats' => [$branch => 30],
        ])->assertSessionHasNoErrors();
        $this->as($a, function () use ($branch) {
            $this->assertSame('15', Settings::get('reservation_slot_minutes'));
            $this->assertSame([$branch => 30], json_decode(Settings::get('reservation_seats'), true));
            $this->assertSame(['open' => '13:00', 'close' => '01:00'], json_decode(Settings::get('reservation_hours'), true)[5]);
        });

        // cashiers can't manage bookings, managers can
        $this->actingAs($this->staff($a, 'cashier', 'cashier@a.test'))->get($this->dashUrl('/reservations'))->assertForbidden();
        $this->actingAs($this->staff($a, 'manager', 'manager@a.test'))->get($this->dashUrl('/reservations'))->assertOk();
    }

    public function test_reservations_are_isolated_per_restaurant(): void
    {
        $a = $this->bookable('cafe-a');
        $b = $this->bookable('cafe-b');
        $this->book('cafe-b', $b)->assertRedirect();
        $rb = $this->as($b, fn () => Reservation::query()->firstOrFail());

        $this->get($this->pathUrl('cafe-a', "reserve/{$rb->token}"))->assertNotFound();
        $this->get($this->subUrl('cafe-a', "reserve/{$rb->token}"))->assertNotFound();
        $this->post($this->pathUrl('cafe-a', "reserve/{$rb->token}/cancel"))->assertNotFound();
        // A can't book into B's branch nor move B's booking
        $this->book('cafe-a', $a, ['branch_id' => $this->branchId($b)])->assertNotFound();
        $this->actingAs($this->owner($a))->postJson($this->dashUrl("/reservations/{$rb->id}/status"), ['status' => 'confirmed'])->assertNotFound();
        $this->actingAs($this->owner($a))->getJson($this->dashUrl('/orders/feed'))->assertJsonCount(0, 'reservations');
        $this->assertSame('pending', $this->as($b, fn () => $rb->fresh()->status));
        $this->assertSame(0, $this->as($a, fn () => Reservation::query()->count()));
    }

    public function test_feature_gate(): void
    {
        // Basic has no reservations until the add-on is bought
        $basic = $this->bookable('cafe-basic', [], 'basic');
        $this->get($this->pathUrl('cafe-basic', 'reserve'))->assertNotFound();
        $this->get($this->pathUrl('cafe-basic'))->assertOk()->assertDontSee($this->pathUrl('cafe-basic', 'reserve'));
        $this->book('cafe-basic', $basic)->assertNotFound();
        $this->actingAs($this->owner($basic))->get($this->dashUrl('/reservations'))->assertForbidden();

        app(Billing::class)->setAddon($basic, 'reservations_pack', 1);
        Entitlements::flush();
        $this->get($this->pathUrl('cafe-basic', 'reserve'))->assertOk();
        $this->actingAs($this->owner($basic))->get($this->dashUrl('/reservations'))->assertOk();

        // switched off by the restaurant: no guest booking, staff still book by hand
        $this->as($basic, fn () => Settings::put(['reservations_enabled' => '0']));
        $this->get($this->pathUrl('cafe-basic', 'reserve'))->assertNotFound();
        $this->get($this->pathUrl('cafe-basic', 'about'))->assertOk()->assertDontSee($this->pathUrl('cafe-basic', 'reserve'));

        // Business has it as a plan feature
        $this->assertTrue(Entitlements::for($this->restaurant('cafe-biz', 'business'))->allows('reservations'));
        $this->assertFalse(Entitlements::for($this->restaurant('cafe-starter', 'starter'))->allows('reservations'));
    }
}
