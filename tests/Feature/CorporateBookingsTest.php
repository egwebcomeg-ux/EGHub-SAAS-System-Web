<?php

namespace Tests\Feature;

use App\Models\CorporateBooking;
use App\Models\CorporateContract;
use App\Models\CorporateVoucher;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Corporate\CorporateVouchers;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Company visits: the company asks from its private link (at least the notice ahead), a manager confirms, the budget
 * is split into the meals of the visit day (on the rep's mobile) and a voucher for the company, and charged to it.
 */
class CorporateBookingsTest extends TenantTestCase
{
    private const REP = '01012345678';

    private Tenant $tenant;

    private MenuItem $latte;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->restaurant('cafe-a', 'enterprise');
        $this->latte = $this->item($this->tenant, 'Latte', 60);
        $this->cashier = $this->staff($this->tenant, 'cashier', 'cashier@cafe-a.test');
        $this->manager = $this->staff($this->tenant, 'manager', 'manager@cafe-a.test');
    }

    /** A visits-only contract (no printed vouchers) with its private link. */
    private function contract(array $extra = []): CorporateContract
    {
        $this->actingAs($this->manager)->post($this->dashUrl('/corporate'), $extra + [
            'name' => 'Eva Pharma', 'voucher_value' => 1, 'quantity' => 0, 'expires_on' => now()->addMonths(3)->format('Y-m-d'),
            'mode' => 'balance', 'booking_notice_hours' => 24,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $contract = $this->as($this->tenant, fn () => CorporateContract::query()->latest('id')->firstOrFail());
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/{$contract->id}/portal"))->assertSessionHasNoErrors();

        return $this->fresh($contract);
    }

    private function fresh(CorporateContract $contract): CorporateContract
    {
        return $this->as($this->tenant, fn () => CorporateContract::query()->findOrFail($contract->id));
    }

    private function ask(CorporateContract $contract, array $data = [])
    {
        return $this->post($this->pathUrl('cafe-a', "company/{$contract->portal_token}/bookings"), $data + [
            'date' => local_at()->addDays(2)->format('Y-m-d'), 'time' => '13:00', 'party_size' => 4, 'budget' => 1000,
            'rep_name' => 'Mona', 'rep_phone' => self::REP,
        ]);
    }

    private function booking(): CorporateBooking
    {
        return $this->as($this->tenant, fn () => CorporateBooking::query()->latest('id')->firstOrFail());
    }

    /** @return array{0:?CorporateVoucher, 1:?CorporateVoucher} the meals voucher and the company's */
    private function vouchers(CorporateBooking $booking): array
    {
        $all = $this->as($this->tenant, fn () => CorporateVoucher::query()->where('corporate_booking_id', $booking->id)->with('customer')->get());

        return [$all->first(fn ($v) => $v->valid_from !== null), $all->first(fn ($v) => $v->valid_from === null)];
    }

    private function payAtPos(CorporateVoucher $voucher, ?string $phone = null)
    {
        return $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $this->latte->id, 'qty' => 2]],
            'pay' => 'corporate', 'vouchers' => [['code' => $voucher->code]], 'voucher_phone' => $phone,
        ]);
    }

    public function test_the_company_asks_from_its_link_at_least_the_notice_ahead_and_nothing_is_charged_yet(): void
    {
        $contract = $this->contract();
        $this->assertSame(0, $this->as($this->tenant, fn () => CorporateVoucher::query()->count()));

        $this->get($this->pathUrl('cafe-a', "company/{$contract->portal_token}"))->assertOk()->assertSee('Eva Pharma');
        $this->get($this->pathUrl('cafe-a', 'company/'.str_repeat('a', 24)))->assertNotFound();

        $this->ask($contract, ['date' => local_at()->addHours(3)->format('Y-m-d'), 'time' => local_at()->addHours(3)->format('H:i')])->assertSessionHasErrors('date');
        $this->assertSame(0, $this->as($this->tenant, fn () => CorporateBooking::query()->count()));

        $this->ask($contract)->assertRedirect()->assertSessionHasNoErrors();
        $booking = $this->booking();
        $this->assertSame(['requested', 'portal', 4, 1000.0], [$booking->status, $booking->source, $booking->party_size, $booking->budget]);
        $this->assertSame(0.0, $this->fresh($contract)->totalValue());

        // a new link: the old one stops working
        $old = $contract->portal_token;
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/{$contract->id}/portal"))->assertSessionHasNoErrors();
        $this->get($this->pathUrl('cafe-a', "company/$old"))->assertNotFound();
    }

    public function test_a_manager_confirms_only_what_the_company_paid_for_and_the_budget_is_split(): void
    {
        $contract = $this->contract();
        $this->ask($contract)->assertSessionHasNoErrors();
        $booking = $this->booking();

        $this->actingAs($this->cashier)->post($this->dashUrl("/corporate/bookings/{$booking->id}/approve"), ['meal_price' => 150])->assertForbidden();
        // prepaid with nothing paid: the visit waits for the payment
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/bookings/{$booking->id}/approve"), ['meal_price' => 150])->assertSessionHasErrors('booking');
        $this->assertSame('requested', $this->booking()->status);

        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/{$contract->id}/payments"), ['amount' => 1000, 'method' => 'transfer'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/bookings/{$booking->id}/approve"), ['meal_price' => 150])->assertSessionHasNoErrors();

        $booking = $this->booking();
        $this->assertSame(['confirmed', 600.0, 400.0], [$booking->status, $booking->meals_amount, $booking->voucher_amount]);
        [$meals, $rest] = $this->vouchers($booking);
        $visitDay = $booking->visit_at->format('Y-m-d');
        $this->assertSame([600.0, 'balance', $visitDay, $visitDay, self::REP], [$meals->value, $meals->mode, $meals->valid_from->format('Y-m-d'), $meals->expires_on->format('Y-m-d'), $meals->customer->phone]);
        $this->assertSame([400.0, null, $contract->expires_on->format('Y-m-d')], [$rest->value, $rest->customer_id, $rest->expires_on->format('Y-m-d')]);

        $contract = $this->fresh($contract);
        $this->assertSame([1000.0, 'paid'], [$contract->totalValue(), $contract->paid_status]);
        $ledger = $this->actingAs($this->manager)->get($this->dashUrl("/corporate/{$contract->id}/statement"))->assertOk()->viewData('ledger');
        $this->assertSame(['payment', 'visit'], $ledger->pluck('kind')->sort()->values()->all());
        $this->assertSame(0.0, $ledger->last()['balance']);

        // answered once only
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/bookings/{$booking->id}/approve"), ['meal_price' => 150])->assertSessionHasErrors('booking');
    }

    public function test_the_meals_work_on_the_visit_day_only_and_closing_moves_what_is_left_to_the_company(): void
    {
        $contract = $this->contract(['billing' => 'account']);
        $this->ask($contract)->assertSessionHasNoErrors();
        $booking = $this->booking();
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/bookings/{$booking->id}/approve"), ['meal_price' => 150])->assertSessionHasNoErrors();
        [$meals] = $this->vouchers($booking);

        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 100])->assertSessionHasNoErrors();
        $this->payAtPos($meals, self::REP)->assertStatus(422);

        $this->travelTo(local_at($booking->visit_at)->setTime(13, 30));
        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 100]);
        $this->payAtPos($meals, '01099999999')->assertStatus(422);
        $this->payAtPos($meals, self::REP)->assertOk();

        // cancelling after the meals were used is refused
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/bookings/{$booking->id}/cancel"), ['reason' => 'changed plans'])->assertSessionHasErrors('booking');

        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/bookings/{$booking->id}/close"))->assertSessionHasNoErrors();
        $booking = $this->booking();
        [$meals, $rest] = $this->vouchers($booking);
        $this->assertSame(['closed', 120.0, 880.0], [$booking->status, $booking->meals_amount, $booking->voucher_amount]);
        $this->assertSame(['used', 0.0], [$meals->status, $meals->balance]);
        $this->assertSame([880.0, 880.0], [$rest->value, $rest->balance]);
        // still charged the whole budget: the rest is the company's
        $this->assertSame(1000.0, $this->fresh($contract)->totalValue());
        // the meals voucher keeps what the visit ate, so the issued total is still the budget
        $this->assertSame([120.0, 1000.0], [$meals->value, round($meals->value + $rest->value, 2)]);
        // and is managed through its visit, not voided on its own
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/vouchers/{$meals->id}/void"))->assertSessionHasErrors('voucher');

        // the order is refunded after the visit was closed: the money goes onto the company's voucher, not the spent day
        $this->as($this->tenant, fn () => app(CorporateVouchers::class)->refundOrder(Order::query()->latest('id')->firstOrFail()));
        $booking = $this->booking();
        [$meals, $rest] = $this->vouchers($booking);
        $this->assertSame([0.0, 1000.0], [$booking->meals_amount, $booking->voucher_amount]);
        $this->assertSame(['used', 0.0, 0.0], [$meals->status, $meals->value, $meals->balance]);
        $this->assertSame([1000.0, 1000.0], [$rest->value, $rest->balance]);
    }

    public function test_a_visit_must_fall_in_the_contract_days_and_hours(): void
    {
        $visit = local_at()->addDays(2)->setTime(13, 0);
        $contract = $this->contract(['days' => [($visit->dayOfWeek + 1) % 7]]);
        $this->ask($contract)->assertSessionHasErrors('date');
        $this->assertSame(0, $this->as($this->tenant, fn () => CorporateBooking::query()->count()));
    }

    public function test_cancelling_an_unused_visit_voids_its_vouchers_and_takes_the_charge_back(): void
    {
        $contract = $this->contract(['billing' => 'account']);
        $this->ask($contract)->assertSessionHasNoErrors();
        $booking = $this->booking();
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/bookings/{$booking->id}/approve"), ['meal_price' => 150])->assertSessionHasNoErrors();
        $this->assertSame(1000.0, $this->fresh($contract)->totalValue());

        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/bookings/{$booking->id}/cancel"), ['reason' => 'rep is sick'])->assertSessionHasNoErrors();
        [$meals, $rest] = $this->vouchers($this->booking());
        $this->assertSame(['cancelled', 'void', 'void'], [$this->booking()->status, $meals->status, $rest->status]);
        $this->assertSame(0.0, $this->fresh($contract)->totalValue());
    }

    public function test_a_declined_visit_shows_its_reason_to_the_company_and_staff_may_enter_visits(): void
    {
        $contract = $this->contract();
        $this->ask($contract)->assertSessionHasNoErrors();
        $booking = $this->booking();
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/bookings/{$booking->id}/decline"), ['reason' => 'Fully booked that day'])->assertSessionHasNoErrors();
        $this->get($this->pathUrl('cafe-a', "company/{$contract->portal_token}"))->assertOk()->assertSee('Fully booked that day');

        // a visit asked by phone: staff enter it (no notice), a manager still confirms
        $this->actingAs($this->manager)->post($this->dashUrl('/corporate/bookings'), [
            'corporate_contract_id' => $contract->id, 'date' => local_at()->addHour()->format('Y-m-d'), 'time' => local_at()->addHour()->format('H:i'),
            'party_size' => 2, 'budget' => 300, 'rep_name' => 'Ali', 'rep_phone' => '01111111111',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['requested', 'staff'], [$this->booking()->status, $this->booking()->source]);
        $this->actingAs($this->manager)->get($this->dashUrl('/corporate/bookings'))->assertOk()->assertSee('Ali');
        $this->actingAs($this->cashier)->get($this->dashUrl('/corporate/bookings'))->assertForbidden();
    }

    public function test_the_nightly_run_closes_visits_of_past_days(): void
    {
        $contract = $this->contract(['billing' => 'account']);
        $this->ask($contract)->assertSessionHasNoErrors();
        $booking = $this->booking();
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/bookings/{$booking->id}/approve"), ['meal_price' => 250])->assertSessionHasNoErrors();

        $this->travelTo(local_at($booking->visit_at)->addDay()->setTime(4, 0));
        $this->artisan('egmenu:corporate-visits')->assertSuccessful();
        $booking = $this->booking();
        [, $rest] = $this->vouchers($booking);
        $this->assertSame(['closed', 0.0, 1000.0], [$booking->status, $booking->meals_amount, $booking->voucher_amount]);
        $this->assertSame(1000.0, $rest->balance);
    }
}
