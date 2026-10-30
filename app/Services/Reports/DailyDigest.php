<?php

namespace App\Services\Reports;

use App\Models\AuditLog;
use App\Models\CorporateBooking;
use App\Models\IngredientStock;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\DailyDigestReport;
use App\Services\Entitlements;
use App\Services\Push\StaffPush;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * «تقرير المدير اليومي»: every morning the owners and managers get what happened yesterday, the things a manager
 * signs off: sales by payment method, every cancellation and refund with its reason and who approved it, the
 * discounts given, each closed shift's cash against what was expected, orders left open, company visits waiting for
 * a decision and the ingredients running low. By email (owners and managers with an email) and a push to their
 * phones. Setting daily_digest (on by default); a day without any order or event sends nothing.
 */
final class DailyDigest
{
    /** A shift whose counted cash is this far from the expected is flagged. */
    public const CASH_TOLERANCE = 1.0;

    /** @return int restaurants sent */
    public function run(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::parse(local_today());
        $day = $today->subDay();
        $sent = 0;
        foreach (Tenant::query()->where('status', 'active')->orderBy('id')->get() as $tenant) {
            tenancy()->initialize($tenant);
            try {
                Settings::flush();
                if (Settings::get('daily_digest', '1') === '1' && Settings::get('daily_digest_sent_for') !== $day->toDateString()
                    && Entitlements::for($tenant)->allows('daily_reports') && $this->send($tenant, $day)) {
                    Settings::put(['daily_digest_sent_for' => $day->toDateString()]); // a rerun never sends the same day twice
                    $sent++;
                }
            } catch (\Throwable $e) {
                report($e); // one restaurant's failure never stops the others
            } finally {
                tenancy()->end();
                Settings::flush();
                Entitlements::flush();
            }
        }

        return $sent;
    }

    /** Builds and sends $day's digest of the current restaurant. False when there is nothing to tell or nobody to tell. */
    public function send(Tenant $tenant, CarbonImmutable $day): bool
    {
        $digest = $this->build($day);
        $managers = User::query()->where('is_active', true)->role(['owner', 'manager'])->get();
        if ($digest['empty'] || $managers->isEmpty()) {
            return false;
        }
        $digest['restaurant'] = tr(Settings::get('brand_name'), Settings::get('brand_name_en')) ?: (string) $tenant->name;
        $withEmail = $managers->filter(fn (User $u) => filled($u->email));
        if ($withEmail->isNotEmpty()) {
            Notification::send($withEmail, new DailyDigestReport($digest));
        }
        app(StaffPush::class)->dailyDigest($digest);

        return true;
    }

    /**
     * @return array{date:string, empty:bool, orders:int, revenue:float, methods:array<string,float>, discounts:float,
     *               cancelled:list<array{order:string, reason:?string, approved_by:?string, by:?string}>,
     *               refunds:list<array{order:string, amount:float, reason:string, approved_by:?string, by:?string}>,
     *               shifts:list<array{user:string, expected:float, counted:float, difference:float}>,
     *               open_orders:int, visits_waiting:int, low_stock:list<string>}
     */
    public function build(CarbonImmutable $day): array
    {
        $from = local_day_start($day->toDateString());
        $to = local_day_end($day->toDateString());
        $sold = Order::query()->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled');
        $paid = (clone $sold)->where('payment_status', 'paid');
        $methods = (clone $paid)->selectRaw('payment_method, SUM(total) as total')->groupBy('payment_method')->pluck('total', 'payment_method')
            ->map(fn ($v) => round((float) $v, 2))->all();

        $cancelled = AuditLog::query()->where('action', 'order.cancelled')->whereBetween('created_at', [$from, $to])->with('user:id,name')->orderBy('id')->get()
            ->map(fn (AuditLog $a) => ['order' => $a->subject, 'reason' => $a->meta['reason'] ?? null, 'approved_by' => $a->meta['approved_by'] ?? null, 'by' => $a->user?->name])->all();
        $refunds = OrderRefund::query()->whereBetween('created_at', [$from, $to])->with(['order:id,number', 'approver:id,name', 'creator:id,name'])->orderBy('id')->get()
            ->map(fn (OrderRefund $r) => ['order' => '#'.$r->order?->number, 'amount' => round($r->amount, 2), 'reason' => (string) $r->reason, 'approved_by' => $r->approver?->name, 'by' => $r->creator?->name])->all();
        $shifts = Shift::query()->whereBetween('closed_at', [$from, $to])->with('user:id,name')->orderBy('id')->get()
            ->map(fn (Shift $s) => ['user' => (string) $s->user?->name, 'expected' => round((float) $s->expected_cash, 2), 'counted' => round((float) $s->closing_cash, 2),
                'difference' => round((float) $s->closing_cash - (float) $s->expected_cash, 2)])->all();
        $openOrders = Order::query()->whereIn('status', ['new', 'preparing', 'ready', 'on_way'])->whereNull('scheduled_for')->where('created_at', '<=', $to)->count();
        $visits = Entitlements::current()->allows('corporate_vouchers') ? CorporateBooking::query()->where('status', 'requested')->count() : 0;
        $low = Entitlements::current()->allows('inventory')
            ? IngredientStock::query()->with('ingredient:id,name,min_stock,is_active')
                ->whereHas('ingredient', fn ($q) => $q->where('is_active', true)->where('min_stock', '>', 0)->whereColumn('ingredient_stocks.quantity', '<=', 'ingredients.min_stock'))
                ->limit(20)->get()->map(fn (IngredientStock $s) => (string) $s->ingredient->name)->unique()->values()->all()
            : [];

        $digest = [
            'date' => $day->format('Y/m/d'), 'orders' => (clone $sold)->count(), 'revenue' => round((float) (clone $paid)->sum('total'), 2),
            'methods' => $methods, 'discounts' => round((float) (clone $sold)->sum('discount'), 2),
            'cancelled' => $cancelled, 'refunds' => $refunds, 'shifts' => $shifts, 'open_orders' => $openOrders,
            'visits_waiting' => $visits, 'low_stock' => $low,
        ];
        $digest['empty'] = $digest['orders'] === 0 && ! $cancelled && ! $refunds && ! $shifts && ! $visits;

        return $digest;
    }

    /** The shifts whose cash was off. @param array{shifts:list<array{difference:float}>} $digest */
    public static function cashOff(array $digest): array
    {
        return array_values(array_filter($digest['shifts'], fn (array $s) => abs($s['difference']) >= self::CASH_TOLERANCE));
    }
}
