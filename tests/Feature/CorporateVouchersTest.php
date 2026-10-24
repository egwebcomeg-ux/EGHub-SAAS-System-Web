<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CorporateContract;
use App\Models\CorporateVoucher;
use App\Models\CorporateVoucherRedemption;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Entitlements;
use App\Services\Orders\OrderService;
use App\Services\Orders\ShiftService;
use App\Services\Reports\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * Corporate prepaid vouchers: contracts and their codes, who may do what, redemption at the POS (single / balance,
 * several vouchers, refusals, exceptions with a manager PIN, no double spend), refunds, linking to a guest's mobile,
 * printing, shifts / reports and tenant isolation.
 */
class CorporateVouchersTest extends TenantTestCase
{
    private const PHONE = '01012345678';

    private Tenant $tenant;

    private MenuItem $latte;

    private User $cashier;

    private User $manager;

    /** An Enterprise restaurant (POS + corporate vouchers), a 60 EGP latte, a cashier with an open shift and a manager. */
    private function shop(string $slug = 'cafe-a'): void
    {
        $this->tenant = $this->restaurant($slug, 'enterprise');
        $this->latte = $this->item($this->tenant, 'Latte', 60);
        $this->cashier = $this->staff($this->tenant, 'cashier', "cashier@$slug.test");
        $this->manager = $this->staff($this->tenant, 'manager', "manager@$slug.test");
        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 100])->assertSessionHasNoErrors();
    }

    private function contract(array $overrides = [], ?Tenant $tenant = null): CorporateContract
    {
        $tenant ??= $this->tenant;

        return $this->as($tenant, fn () => app(CorporateVouchers::class)->createContract($overrides + [
            'name' => 'Eva Pharma', 'voucher_value' => 200, 'quantity' => 3, 'expires_on' => now()->addMonth()->format('Y-m-d'), 'mode' => 'single',
        ], $this->owner($tenant)));
    }

    /** @return list<CorporateVoucher> */
    private function vouchers(CorporateContract $contract): array
    {
        return $this->as($this->tenant, fn () => CorporateVoucher::query()->where('corporate_contract_id', $contract->id)->orderBy('id')->get()->all());
    }

    private function fresh(CorporateVoucher $voucher): CorporateVoucher
    {
        return $this->as($this->tenant, fn () => CorporateVoucher::query()->findOrFail($voucher->id));
    }

    /** A takeaway POS order of $qty lattes paid with $vouchers, the rest by $pay. */
    private function sell(int $qty, array $vouchers, string $pay = 'cash', ?string $phone = null, ?User $by = null): TestResponse
    {
        return $this->actingAs($by ?? $this->cashier)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $this->latte->id, 'qty' => $qty]],
            'pay' => $pay, 'vouchers' => $vouchers, 'voucher_phone' => $phone,
        ]);
    }

    private function check(string $query, float $total = 120, ?User $by = null): TestResponse
    {
        return $this->actingAs($by ?? $this->cashier)->postJson($this->dashUrl('/pos/vouchers/check'), ['query' => $query, 'total' => $total]);
    }

    public function test_a_contract_makes_unique_codes_with_a_check_character_and_running_serials(): void
    {
        $this->shop();
        $this->actingAs($this->manager)->post($this->dashUrl('/corporate'), [
            'name' => 'Eva Pharma', 'voucher_value' => 200, 'quantity' => 40, 'expires_on' => now()->addMonths(3)->format('Y-m-d'), 'mode' => 'single',
            'paid_amount' => 3000,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $contract = $this->as($this->tenant, fn () => CorporateContract::query()->firstOrFail());
        $this->assertSame('EVA', $contract->prefix);
        $this->assertSame('partial', $contract->paid_status);
        $vouchers = $this->vouchers($contract);
        $this->assertCount(40, $vouchers);
        $codes = array_map(fn ($v) => $v->code, $vouchers);
        $this->assertCount(40, array_unique($codes));
        foreach ($vouchers as $v) {
            $this->assertTrue(CorporateVoucher::isValidCode($v->code), $v->code);
            $this->assertSame(200.0, $v->balance);
        }
        $this->assertSame(['EVA-0001', 'EVA-0040'], [$vouchers[0]->serial, $vouchers[39]->serial]);

        // a typo (one character changed) or two neighbours swapped is caught before any lookup
        $code = $vouchers[0]->code;
        $other = $code[3] === 'A' ? 'B' : 'A';
        $this->assertFalse(CorporateVoucher::isValidCode(substr($code, 0, 3).$other.substr($code, 4)));
        if ($code[1] !== $code[2]) {
            $this->assertFalse(CorporateVoucher::isValidCode($code[0].$code[2].$code[1].substr($code, 3)));
        }
        $this->check(substr($code, 0, 3).$other.substr($code, 4))->assertStatus(422)->assertJsonFragment(['message' => 'الكود ده مش صحيح: راجعه واكتبه تاني.']);
        // typed with dashes and lower case, or scanned as the QR link: the same voucher
        $this->check(strtolower(implode('-', str_split($code, 5))))->assertOk()->assertJsonPath('vouchers.0.serial', 'EVA-0001');
        $this->check($this->as($this->tenant, fn () => $vouchers[0]->publicUrl()))->assertOk()->assertJsonPath('vouchers.0.serial', 'EVA-0001');

        // a second contract with the same prefix continues the serials; more vouchers later, too
        $second = $this->contract(['prefix' => 'eva', 'quantity' => 2]);
        $this->assertSame(['EVA-0041', 'EVA-0042'], array_map(fn ($v) => $v->serial, $this->vouchers($second)));
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/{$second->id}"), ['name' => 'Eva Pharma 2', 'quantity' => 1])->assertSessionHasErrors('quantity');
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/{$second->id}"), ['name' => 'Eva Pharma 2', 'quantity' => 4, 'paid_amount' => 800])->assertSessionHasNoErrors();
        $this->assertCount(4, $this->vouchers($second));
        $this->assertSame('paid', $this->as($this->tenant, fn () => CorporateContract::query()->find($second->id)->paid_status));

        $this->actingAs($this->manager)->get($this->dashUrl('/corporate'))->assertOk()->assertSee('Eva Pharma')->assertSee('تعاقدات الشركات');
        $this->actingAs($this->manager)->get($this->dashUrl('/corporate/create'))->assertOk()->assertSee('data-contract-form', false);
    }

    public function test_who_may_manage_redeem_and_approve(): void
    {
        $this->shop();
        $contract = $this->contract();
        $code = $this->vouchers($contract)[0]->code;

        // contracts: owner and manager, not the cashier
        $this->actingAs($this->owner($this->tenant))->get($this->dashUrl('/corporate'))->assertOk();
        $this->actingAs($this->manager)->get($this->dashUrl("/corporate/{$contract->id}"))->assertOk()->assertSee('EVA-0001');
        $this->actingAs($this->cashier)->get($this->dashUrl('/corporate'))->assertForbidden();
        $this->actingAs($this->cashier)->post($this->dashUrl('/corporate'), ['name' => 'X', 'voucher_value' => 50, 'quantity' => 1, 'expires_on' => now()->addDay()->format('Y-m-d'), 'mode' => 'single'])->assertForbidden();
        $this->assertSame(1, $this->as($this->tenant, fn () => CorporateContract::query()->count()));

        // redeeming: any cashier; a waiter can't
        $this->check($code)->assertOk()->assertJsonPath('vouchers.0.ok', true);
        $waiter = $this->staff($this->tenant, 'waiter', 'w@cafe-a.test');
        $this->check($code, 120, $waiter)->assertForbidden();
        $this->actingAs($this->cashier)->get($this->dashUrl('/pos'))->assertOk()->assertSee('data-corp-box', false)->assertDontSee('data-exc-manager', false);
        $this->actingAs($waiter)->get($this->dashUrl('/pos'))->assertOk()->assertDontSee('data-corp-box', false);

        // the plan: Complete and Enterprise have it, Pro does not
        $this->assertTrue(Entitlements::for($this->restaurant('cafe-full', 'full'))->allows('corporate_vouchers'));
        $pro = $this->restaurant('cafe-pro', 'pro');
        $this->assertFalse(Entitlements::for($pro)->allows('corporate_vouchers'));
        $this->actingAs($this->owner($pro))->get($this->dashUrl('/corporate'))->assertForbidden();
    }

    public function test_single_mode_loses_the_rest_and_balance_mode_keeps_it_and_the_shift_and_reports_see_their_own_method(): void
    {
        $this->shop();
        [$single] = $this->vouchers($this->contract());
        [$balance] = $this->vouchers($this->contract(['name' => 'Nile Bank', 'mode' => 'balance']));

        // 2 lattes = 120 on a single-use 200 voucher: all covered, 80 lost
        $this->sell(2, [['code' => $single->code]], 'corporate')->assertOk()->assertJsonPath('paid', true);
        $order = $this->as($this->tenant, fn () => Order::query()->latest('id')->first());
        $this->assertSame(['corporate', 120.0], [$order->payment_method, $order->corporate_amount]);
        $this->assertSame(['used', 0.0], [$this->fresh($single)->status, $this->fresh($single)->balance]);
        $row = $this->as($this->tenant, fn () => CorporateVoucherRedemption::query()->where('corporate_voucher_id', $single->id)->first());
        $this->assertSame([120.0, 80.0, $this->cashier->id], [$row->amount, $row->forfeited, $row->user_id]);

        // balance mode: 120 now, 80 stays; next time 3 lattes = 180 → 80 by voucher, 100 in cash
        $this->sell(2, [['code' => $balance->code]], 'corporate')->assertOk();
        $this->assertSame(['active', 80.0], [$this->fresh($balance)->status, $this->fresh($balance)->balance]);
        $this->sell(3, [['code' => $balance->code]], 'corporate')->assertStatus(422)
            ->assertJsonFragment(['message' => 'الفاوتشرات غطت 80 من 180: اختار هيدفع الباقي (100) إزاي.']);
        $this->sell(3, [['code' => $balance->code]], 'cash')->assertOk();
        $last = $this->as($this->tenant, fn () => Order::query()->latest('id')->first());
        $this->assertSame(['cash', 80.0], [$last->payment_method, $last->corporate_amount]);
        $this->assertSame(['used', 0.0], [$this->fresh($balance)->status, $this->fresh($balance)->balance]);

        // the shift: vouchers are their own method, never cash in the drawer
        $shift = $this->as($this->tenant, fn () => Shift::query()->first());
        $summary = app(ShiftService::class)->summary($shift);
        $this->assertSame(320.0, $summary['by_method']['corporate']);
        $this->assertSame(100.0, $summary['by_method']['cash']);
        $this->assertSame(200.0, $summary['expected_cash']); // 100 opening + 100 cash
        $this->assertSame(420.0, $summary['total']);
        $this->actingAs($this->cashier)->get($this->dashUrl('/shifts'))->assertOk()->assertSee('data-shift-corporate', false)->assertSee('مش كاش في الدرج');

        // the reports: the same split
        $report = $this->as($this->tenant, fn () => (new SalesReport(CarbonImmutable::now(), CarbonImmutable::now()))->build());
        $byMethod = collect($report['by_method'])->keyBy('key');
        $this->assertSame(320.0, $byMethod['corporate']['total']);
        $this->assertSame(100.0, $byMethod['cash']['total']);
        $this->actingAs($this->owner($this->tenant))->get($this->dashUrl('/reports'))->assertOk()->assertSee('فاوتشر شركة');
        $this->actingAs($this->cashier)->get($this->dashUrl("/orders/{$last->id}/receipt"))->assertOk()->assertSee('data-receipt-corporate', false);
    }

    public function test_several_vouchers_on_one_order_go_earliest_expiry_first(): void
    {
        $this->shop();
        [$late] = $this->vouchers($this->contract(['name' => 'Late Co', 'voucher_value' => 100, 'mode' => 'balance', 'expires_on' => now()->addMonths(2)->format('Y-m-d')]));
        [$early] = $this->vouchers($this->contract(['name' => 'Early Co', 'voucher_value' => 100, 'mode' => 'balance', 'expires_on' => now()->addDays(5)->format('Y-m-d')]));
        [$unneeded] = $this->vouchers($this->contract(['name' => 'Spare Co', 'voucher_value' => 100, 'mode' => 'single', 'expires_on' => now()->addMonths(3)->format('Y-m-d')]));

        // 3 lattes = 180: the early one first (100), then 80 of the late one; the third isn't needed and is untouched
        $this->sell(3, [['code' => $late->code], ['code' => $unneeded->code], ['code' => $early->code]], 'cash')->assertOk();
        $this->assertSame(0.0, $this->fresh($early)->balance);
        $this->assertSame(20.0, $this->fresh($late)->balance);
        $this->assertSame(['active', 100.0], [$this->fresh($unneeded)->status, $this->fresh($unneeded)->balance]);
        $order = $this->as($this->tenant, fn () => Order::query()->latest('id')->first());
        $this->assertSame(['corporate', 180.0], [$order->payment_method, $order->corporate_amount]);
        $this->assertSame([$early->id, $late->id], $this->as($this->tenant, fn () => CorporateVoucherRedemption::query()->orderBy('id')->pluck('corporate_voucher_id')->all()));

        // the same voucher twice on one order counts once
        $this->sell(1, [['code' => $late->code], ['code' => $late->code]], 'cash')->assertOk();
        $this->assertSame(0.0, $this->fresh($late)->balance);
        $this->assertSame(40.0, $this->as($this->tenant, fn () => Order::query()->latest('id')->first()->total - Order::query()->latest('id')->first()->corporate_amount));
    }

    public function test_an_expired_voucher_is_refused_until_a_manager_approves_an_exception(): void
    {
        $this->shop();
        [$voucher] = $this->vouchers($this->contract());
        $this->as($this->tenant, fn () => CorporateVoucher::query()->whereKey($voucher->id)->update(['expires_on' => now()->subDays(3)->format('Y-m-d')]));

        $this->check($voucher->code)->assertOk()->assertJsonPath('vouchers.0.ok', false)->assertJsonPath('vouchers.0.expired', true)
            ->assertJsonPath('vouchers.0.reason', 'خلص يوم '.now()->subDays(3)->format('Y/m/d').'.');
        $this->sell(2, [['code' => $voucher->code]], 'corporate')->assertStatus(422);
        $this->assertSame(0, $this->as($this->tenant, fn () => Order::query()->count())); // a refused voucher leaves no order behind

        // the cashier alone can't; a manager's PIN on the cashier's device can
        $url = $this->dashUrl("/pos/vouchers/{$voucher->id}/exception");
        $this->actingAs($this->cashier)->postJson($url, ['reason' => 'Staff came on the last day'])->assertStatus(422)
            ->assertJsonFragment(['message' => 'لازم مدير يوافق: اختار المدير وخليه يكتب الـ PIN بتاعه.']);
        $this->as($this->tenant, fn () => app(CorporateVouchers::class)->setPin(User::query()->find($this->manager->id), '4321'));
        $this->actingAs($this->cashier)->postJson($url, ['reason' => 'Staff came on the last day', 'approver_id' => $this->manager->id, 'pin' => '1111'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'الـ PIN غلط. فاضل 4 محاولات.']);
        $this->actingAs($this->cashier)->postJson($url, ['reason' => 'ok', 'approver_id' => $this->manager->id, 'pin' => '4321'])->assertStatus(422); // reason too short
        $token = $this->actingAs($this->cashier)->postJson($url, ['reason' => 'Staff came on the last day', 'approver_id' => $this->manager->id, 'pin' => '4321'])
            ->assertOk()->assertJsonPath('approver', 'Manager')->json('token');

        // the approval works for this voucher and this cashier only
        $other = $this->staff($this->tenant, 'cashier', 'other@cafe-a.test');
        $this->actingAs($other)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 0]);
        $this->sell(2, [['code' => $voucher->code, 'approval' => $token]], 'corporate', null, $other)->assertStatus(422);
        $this->sell(2, [['code' => $voucher->code, 'approval' => $token]], 'corporate')->assertOk();

        $row = $this->as($this->tenant, fn () => CorporateVoucherRedemption::query()->firstOrFail());
        $this->assertSame([$this->manager->id, 'Staff came on the last day'], [$row->approved_by, $row->exception_reason]);
        $log = $this->as($this->tenant, fn () => AuditLog::query()->where('action', 'corporate.exception')->firstOrFail());
        $this->assertSame([$this->manager->id, 'Staff came on the last day', 'Cashier'], [$log->user_id, $log->meta['reason'], $log->meta['cashier']]);
        $this->actingAs($this->owner($this->tenant))->get($this->dashUrl("/corporate/{$voucher->corporate_contract_id}/statement"))->assertOk()->assertSee('Staff came on the last day');

        // a manager signed in on the POS approves without a PIN
        [, $second] = $this->vouchers($this->as($this->tenant, fn () => CorporateContract::query()->firstOrFail()));
        $this->as($this->tenant, fn () => CorporateVoucher::query()->whereKey($second->id)->update(['expires_on' => now()->subDay()->format('Y-m-d')]));
        $this->actingAs($this->manager)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 0]);
        $this->actingAs($this->manager)->postJson($this->dashUrl("/pos/vouchers/{$second->id}/exception"), ['reason' => 'Company asked to extend'])->assertOk();
        // a voucher that has not expired needs no exception
        [, , $third] = $this->vouchers($this->as($this->tenant, fn () => CorporateContract::query()->firstOrFail()));
        $this->actingAs($this->manager)->postJson($this->dashUrl("/pos/vouchers/{$third->id}/exception"), ['reason' => 'Just because'])->assertStatus(422);
    }

    public function test_wrong_manager_pins_are_rate_limited_and_the_pin_is_set_from_the_security_page(): void
    {
        $this->shop();
        // only someone who may approve sets a PIN, with their password; it is stored hashed
        $this->actingAs($this->cashier)->post($this->dashUrl('/security/pin'), ['pin' => '2468', 'pin_confirmation' => '2468', 'current_password' => 'Secret-pass-123'])->assertForbidden();
        $this->actingAs($this->manager)->post($this->dashUrl('/security/pin'), ['pin' => '2468', 'pin_confirmation' => '2468', 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->actingAs($this->manager)->post($this->dashUrl('/security/pin'), ['pin' => '12', 'pin_confirmation' => '12', 'current_password' => 'Secret-pass-123'])->assertSessionHasErrors('pin');
        $this->actingAs($this->manager)->post($this->dashUrl('/security/pin'), ['pin' => '2468', 'pin_confirmation' => '2468', 'current_password' => 'Secret-pass-123'])->assertSessionHasNoErrors();
        $stored = $this->as($this->tenant, fn () => User::query()->find($this->manager->id)->manager_pin);
        $this->assertNotSame('2468', $stored);
        $this->assertTrue(Hash::check('2468', $stored));
        $this->actingAs($this->manager)->get($this->dashUrl('/security'))->assertOk()->assertSee('data-pin-state="on"', false);
        $this->actingAs($this->cashier)->get($this->dashUrl('/pos'))->assertOk()->assertSee('data-exc-manager', false);

        [$voucher] = $this->vouchers($this->contract());
        $this->as($this->tenant, fn () => CorporateVoucher::query()->whereKey($voucher->id)->update(['expires_on' => now()->subDay()->format('Y-m-d')]));
        $url = $this->dashUrl("/pos/vouchers/{$voucher->id}/exception");
        for ($i = 0; $i < CorporateVouchers::PIN_ATTEMPTS; $i++) {
            $this->actingAs($this->cashier)->postJson($url, ['reason' => 'Late staff', 'approver_id' => $this->manager->id, 'pin' => '0000'])->assertStatus(422);
        }
        // even the right PIN waits now
        $this->actingAs($this->cashier)->postJson($url, ['reason' => 'Late staff', 'approver_id' => $this->manager->id, 'pin' => '2468'])->assertStatus(422)
            ->assertJsonFragment(['message' => 'محاولات PIN غلط كتير. جرّب تاني بعد 15 دقيقة.']);
        $this->assertSame(CorporateVouchers::PIN_ATTEMPTS, $this->as($this->tenant, fn () => AuditLog::query()->where('action', 'corporate.pin_failed')->count()));
    }

    public function test_refusals_name_their_reason(): void
    {
        $this->shop();
        $second = $this->as($this->tenant, fn () => Branch::query()->create(['name' => 'Second', 'slug' => 'second', 'is_active' => true]));
        $reason = fn (string $code, float $total = 120) => $this->check($code, $total)->assertOk()->json('vouchers.0.reason');

        [$branchOnly] = $this->vouchers($this->contract(['branch_ids' => [$second->id]]));
        $this->assertSame('الفاوتشر ده مش صالح في الفرع ده.', $reason($branchOnly->code));

        $this->travelTo(now()->setTime(10, 0)); // a Monday-to-Sunday window of 12:00–16:00, now 10:00
        [$lunch] = $this->vouchers($this->contract(['hours_from' => '12:00', 'hours_to' => '16:00']));
        $this->assertStringStartsWith('الفاوتشر ده بيشتغل بس', $reason($lunch->code));
        $this->travelTo(now()->setTime(13, 0));
        $this->assertNull($reason($lunch->code));

        [$minimum] = $this->vouchers($this->contract(['min_order' => 300]));
        $this->assertSame('الطلب لازم يكون 300 على الأقل عشان الفاوتشر ده.', $reason($minimum->code));
        $this->sell(2, [['code' => $minimum->code]], 'cash')->assertStatus(422);
        $this->assertNull($reason($minimum->code, 300));

        $paused = $this->contract(['name' => 'Paused Co']);
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/{$paused->id}/status"), ['status' => 'paused'])->assertSessionHasNoErrors();
        $this->assertSame('تعاقد الشركة واقف مؤقتاً.', $reason($this->vouchers($paused)[0]->code));

        $contract = $this->contract(['name' => 'Void Co']);
        [$void, $used] = $this->vouchers($contract);
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/vouchers/{$void->id}/void"))->assertSessionHasNoErrors();
        $this->assertSame('الفاوتشر ده اتلغى.', $reason($void->code));
        $this->sell(2, [['code' => $used->code]], 'corporate')->assertOk();
        $this->assertSame('الفاوتشر ده اتصرف قبل كده.', $reason($used->code));

        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/{$contract->id}/status"), ['status' => 'void'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/{$contract->id}/status"), ['status' => 'active'])->assertSessionHasErrors('status');
        $this->assertSame('الفاوتشر ده اتلغى.', $reason($this->vouchers($contract)[2]->code));
    }

    public function test_a_voucher_is_never_spent_twice(): void
    {
        $this->shop();
        [$voucher, $single] = $this->vouchers($this->contract(['mode' => 'balance']));

        $this->sell(4, [['code' => $voucher->code]], 'cash')->assertOk(); // 240: 200 by voucher, 40 cash
        $this->sell(1, [['code' => $voucher->code]], 'cash')->assertStatus(422)->assertJsonFragment(['message' => 'EVA-0001: الفاوتشر ده اتصرف قبل كده.']);
        $this->assertSame(1, $this->as($this->tenant, fn () => CorporateVoucherRedemption::query()->where('corporate_voucher_id', $voucher->id)->count()));

        // two open orders (two tills) settled with the same paper: the first takes it, the second is refused untouched
        $open = fn () => $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $this->latte->id, 'qty' => 4]],
        ])->assertOk()->json('id');
        [$first, $second] = [$open(), $open()];
        $pay = fn (int $id) => $this->actingAs($this->cashier)->postJson($this->dashUrl("/pos/orders/$id/pay"), ['method' => 'cash', 'vouchers' => [['code' => $single->code]]]);
        $pay($first)->assertOk();
        $pay($second)->assertStatus(422);
        $pay($first)->assertStatus(422); // and an order is never paid twice
        $this->assertSame(['paid', 'unpaid'], $this->as($this->tenant, fn () => [Order::query()->find($first)->payment_status, Order::query()->find($second)->payment_status]));
        $this->assertSame(1, $this->as($this->tenant, fn () => CorporateVoucherRedemption::query()->where('corporate_voucher_id', $single->id)->count()));
        $this->assertSame(0.0, $this->fresh($single)->balance);
    }

    public function test_a_cancelled_order_gives_the_value_back_once(): void
    {
        $this->shop();
        [$voucher] = $this->vouchers($this->contract());
        $this->sell(2, [['code' => $voucher->code]], 'corporate')->assertOk();
        $order = $this->as($this->tenant, fn () => Order::query()->latest('id')->first());
        $this->assertSame('used', $this->fresh($voucher)->status);

        $this->as($this->tenant, function () use ($order) {
            app(OrderService::class)->transition($order, 'cancelled', $this->cashier);
            $this->assertSame(0, app(CorporateVouchers::class)->refundOrder($order));
        });
        $this->assertSame(['active', 200.0], [$this->fresh($voucher)->status, $this->fresh($voucher)->balance]);
        $this->assertNotNull($this->as($this->tenant, fn () => CorporateVoucherRedemption::query()->first()->refunded_at));

        // not in the shift, not in the statement total; the voucher works again
        $shift = $this->as($this->tenant, fn () => Shift::query()->first());
        $this->assertSame(0.0, app(ShiftService::class)->summary($shift)['by_method']['corporate']);
        $csv = $this->actingAs($this->owner($this->tenant))->get($this->dashUrl("/corporate/{$voucher->corporate_contract_id}/statement?format=csv"))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('EVA-0001', $csv);
        $this->sell(1, [['code' => $voucher->code]], 'corporate')->assertOk();
    }

    public function test_a_linked_voucher_works_only_with_the_guests_mobile_and_shows_on_their_card(): void
    {
        $this->shop();
        [$voucher, $requested, $mine] = $this->vouchers($this->contract(['mode' => 'balance']));

        // the cashier links it (paper in hand): from then on the code alone is refused, the mobile works
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/vouchers/link'), ['code' => $voucher->code, 'phone' => '+20 10 1234 5678'])->assertOk();
        $this->assertNotNull($this->fresh($voucher)->customer_id);
        $this->check($voucher->code)->assertOk()->assertJsonPath('vouchers.0.ok', false)->assertJsonPath('vouchers.0.linked', '010*****678');
        $this->sell(1, [['code' => $voucher->code]], 'corporate')->assertStatus(422);
        $this->check(self::PHONE)->assertOk()->assertJsonPath('vouchers.0.serial', 'EVA-0001')->assertJsonPath('vouchers.0.ok', true)->assertJsonPath('vouchers.0.code', null);
        $this->sell(1, [['id' => $voucher->id]], 'corporate', '01099999999')->assertStatus(422);
        $this->sell(1, [['id' => $voucher->id]], 'corporate', self::PHONE)->assertOk();
        $this->assertSame(140.0, $this->fresh($voucher)->balance);

        // the guest's own page shows this voucher only, and a typed mobile is a request the cashier confirms
        $this->get($this->subUrl('cafe-a', "voucher/{$requested->code}"))->assertOk()->assertSee('200')->assertSee('Eva Pharma')
            ->assertDontSee($voucher->serial)->assertSee('data-link-request', false);
        $this->post($this->subUrl('cafe-a', "voucher/{$requested->code}"), ['phone' => '01111111111'])->assertSessionHasNoErrors();
        $this->assertSame('01111111111', $this->fresh($requested)->pending_phone);
        $this->assertNull($this->fresh($requested)->customer_id);
        $this->check('01111111111')->assertOk()->assertJsonPath('vouchers.0.ok', false)->assertJsonPath('vouchers.0.pending', '011*****111');
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/vouchers/link'), ['id' => $requested->id, 'phone' => '01222222222'])->assertStatus(422);
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/vouchers/link'), ['id' => $requested->id, 'phone' => '01111111111'])->assertOk()
            ->assertJsonPath('voucher.ok', true);
        $this->assertNotNull($this->fresh($requested)->customer_id);

        // with the guest's card on this phone, the link is immediate; the card lists "my vouchers"
        $token = $this->as($this->tenant, fn () => Customer::query()->where('phone', self::PHONE)->value('card_token'));
        $this->withCookie('card_cafe-a', $token)->post($this->subUrl('cafe-a', "voucher/{$mine->code}"))->assertSessionHasNoErrors();
        $this->assertSame($this->fresh($voucher)->customer_id, $this->fresh($mine)->customer_id);
        $this->get($this->subUrl('cafe-a', "card/$token"))->assertOk()->assertSee('فاوتشراتي')->assertSee('data-corporate-voucher="EVA-0001"', false)
            ->assertSee('data-corporate-voucher="EVA-0003"', false)->assertDontSee('data-corporate-voucher="EVA-0002"', false)->assertSee('140');
        // someone else's linked voucher can't be taken over from the public page
        $this->post($this->subUrl('cafe-a', "voucher/{$voucher->code}"), ['phone' => '01555555555'])->assertSessionHasErrors('phone');
    }

    public function test_printing_counts_copies_and_is_logged_and_exports_work(): void
    {
        $this->shop();
        $contract = $this->contract();
        $owner = $this->owner($this->tenant);

        $this->actingAs($owner)->get($this->dashUrl("/corporate/{$contract->id}/print"))->assertOk()
            ->assertSee('data-print-voucher="EVA-0003"', false)->assertDontSee('data-copy', false)->assertSee('<svg', false);
        $this->assertSame([1, 1, 1], array_map(fn ($v) => $v->print_count, $this->vouchers($contract)));
        [$first] = $this->vouchers($contract);
        $this->actingAs($owner)->get($this->dashUrl("/corporate/vouchers/{$first->id}/receipt"))->assertRedirect();
        $this->actingAs($owner)->get($this->dashUrl("/corporate/{$contract->id}/print?layout=receipt&ids={$first->id}"))->assertOk()
            ->assertSee('data-copy', false)->assertSee('نسخة')->assertSee($first->formattedCode());
        $this->assertSame(2, $this->fresh($first)->print_count);
        $this->assertSame(2, $this->as($this->tenant, fn () => AuditLog::query()->where('action', 'corporate.printed')->count()));
        $this->actingAs($owner)->get($this->dashUrl("/corporate/{$contract->id}/print?unprinted=1"))->assertNotFound();

        $csv = $this->actingAs($owner)->get($this->dashUrl("/corporate/{$contract->id}/export?format=csv"))->assertOk()->streamedContent();
        $this->assertStringContainsString('EVA-0002', $csv);
        $this->actingAs($owner)->get($this->dashUrl("/corporate/{$contract->id}/export?format=xlsx"))->assertOk()->assertDownload();

        // extend the whole contract, then one voucher
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/{$contract->id}/extend"), ['expires_on' => now()->addMonths(6)->format('Y-m-d')])->assertSessionHasNoErrors();
        $this->assertSame(now()->addMonths(6)->format('Y-m-d'), $this->fresh($first)->expires_on->format('Y-m-d'));
        $this->actingAs($this->manager)->post($this->dashUrl("/corporate/vouchers/{$first->id}/extend"), ['expires_on' => now()->subDay()->format('Y-m-d')])->assertSessionHasErrors('expires_on');
        $this->actingAs($this->manager)->get($this->dashUrl('/corporate?q=EVA-0002'))->assertOk()->assertSee('data-found="EVA-0002"', false);
    }

    public function test_another_restaurants_code_is_simply_not_found(): void
    {
        $this->shop();
        $this->contract();
        $b = $this->restaurant('cafe-b', 'enterprise');
        $theirs = $this->as($b, fn () => app(CorporateVouchers::class)->createContract([
            'name' => 'Other Co', 'voucher_value' => 500, 'quantity' => 1, 'expires_on' => now()->addMonth()->format('Y-m-d'), 'mode' => 'single',
        ], $this->owner($b)));
        $code = $this->as($b, fn () => CorporateVoucher::query()->firstOrFail());

        $this->check($code->code)->assertStatus(422)->assertJsonFragment(['message' => 'مفيش فاوتشر بالكود ده.']);
        $this->sell(1, [['code' => $code->code]], 'corporate')->assertStatus(422);
        $this->actingAs($this->cashier)->postJson($this->dashUrl("/pos/vouchers/{$code->id}/exception"), ['reason' => 'Trying it'])->assertNotFound();
        $this->get($this->subUrl('cafe-a', "voucher/{$code->code}"))->assertNotFound();
        $this->get($this->subUrl('cafe-b', "voucher/{$code->code}"))->assertOk()->assertSee('Other Co');
        $this->actingAs($this->owner($this->tenant))->get($this->dashUrl("/corporate/{$theirs->id}"))->assertNotFound();
        $this->assertSame(500.0, $this->as($b, fn () => CorporateVoucher::query()->first()->balance));
    }
}
