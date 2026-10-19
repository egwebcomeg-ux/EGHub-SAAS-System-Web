<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\User;
use App\Services\Audit;
use App\Services\Entitlements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * One page for every discount code of the restaurant (App\Models\Coupon): the campaign and automation coupons
 * (feature campaigns, marketing.manage) and the loyalty-card vouchers — public codes, codes held by one guest and
 * printed batches (feature loyalty_stamps, loyalty.manage). Each side shows only to whoever may run it today;
 * creating a voucher and a batch still posts to VouchersController, giving one to a guest needs vouchers.give.
 */
class CouponsController extends Controller
{
    public const STATUSES = ['active', 'used', 'expired', 'paused'];

    /** The loyalty-card vouchers (public codes, guest codes, batches). */
    public static function managesVouchers(User $user): bool
    {
        return Entitlements::current()->allows('loyalty_stamps') && $user->can('loyalty.manage');
    }

    /** The coupons sent by campaigns and automations. */
    public static function managesCampaignCoupons(User $user): bool
    {
        return Entitlements::current()->allows('campaigns') && $user->can('marketing.manage');
    }

    public static function givesVouchers(User $user): bool
    {
        return Entitlements::current()->allows('loyalty_stamps') && $user->can('vouchers.give');
    }

    /** Whether the page (and its sidebar entry) is for this user at all. */
    public static function visibleFor(User $user): bool
    {
        return self::managesVouchers($user) || self::managesCampaignCoupons($user);
    }

    /** @return list<string> the coupon kinds (Coupon::KINDS) this user sees */
    public static function kindsFor(User $user): array
    {
        return array_values(array_merge(
            self::managesCampaignCoupons($user) ? ['campaign'] : [],
            self::managesVouchers($user) ? ['public', 'guest', 'batch'] : [],
        ));
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless(self::visibleFor($user), 403);
        $kinds = self::kindsFor($user);
        $q = trim((string) $request->query('q', ''));
        $kind = in_array($request->query('kind'), $kinds, true) ? (string) $request->query('kind') : '';
        $status = in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : '';
        $vouchers = self::managesVouchers($user);
        $batch = $vouchers ? trim((string) $request->query('batch', '')) : '';

        $coupons = $this->visible($user)->with(['customer', 'campaigns:id,name,coupon_id'])
            ->withCount(['orders as orders_count' => fn ($o) => $o->where('status', '!=', 'cancelled')])
            ->withSum(['orders as revenue' => fn ($o) => $o->where('status', '!=', 'cancelled')], 'total')
            ->withSum(['orders as discount_given' => fn ($o) => $o->where('status', '!=', 'cancelled')], 'coupon_discount')
            ->when($q !== '', fn (Builder $w) => $w->where(fn (Builder $w) => $w->where('code', 'like', '%'.Coupon::normalize($q).'%')
                ->orWhere('note', 'like', "%$q%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('phone', 'like', '%'.(normalize_phone($q) ?? $q).'%')->orWhere('name', 'like', "%$q%"))
                ->orWhereHas('campaigns', fn (Builder $c) => $c->where('name', 'like', "%$q%"))))
            ->when($kind !== '', fn (Builder $w) => $w->ofKind($kind))
            ->when($batch !== '', fn (Builder $w) => $w->where('batch', $batch))
            ->when($status !== '', fn (Builder $w) => VouchersController::whereStatus($w, $status))
            ->latest('id')->paginate(30)->withQueryString();

        $orders = DB::table('orders')->where('tenant_id', tenant()->getTenantKey())->where('status', '!=', 'cancelled')
            ->whereIn('coupon_id', $this->visible($user)->select('id'));

        return view('dashboard.coupons.index', [
            'coupons' => $coupons, 'q' => $q, 'kind' => $kind, 'status' => $status, 'batch' => $batch, 'kinds' => $kinds,
            'managesVouchers' => $vouchers,
            'managesCampaigns' => self::managesCampaignCoupons($user),
            'givesVouchers' => self::givesVouchers($user),
            'batches' => $vouchers ? Coupon::query()->vouchers()->whereNotNull('batch')->groupBy('batch')
                ->selectRaw('batch, count(*) as codes, sum(uses) as used, max(id) as last_id')->orderByDesc('last_id')->limit(50)->get() : collect(),
            'stats' => [
                'total' => $this->visible($user)->count(),
                'active' => VouchersController::whereStatus($this->visible($user), 'active')->count(),
                'orders' => (clone $orders)->count(),
                'revenue' => round((float) (clone $orders)->sum('total'), 2),
                'discount' => round((float) (clone $orders)->sum('coupon_discount'), 2),
            ],
            'categories' => $vouchers ? Category::query()->orderBy('sort_order')->get() : collect(),
            'items' => $vouchers ? MenuItem::query()->orderBy('name')->get(['id', 'name', 'name_en', 'category_id']) : collect(),
            'prefix' => Coupon::defaultPrefix(),
        ]);
    }

    /** Pause or resume one code, whatever made it, for whoever runs that kind of code. */
    public function toggle(Request $request, Coupon $coupon): RedirectResponse
    {
        abort_unless(in_array($coupon->kind(), self::kindsFor($request->user()), true), 403);
        $coupon->update(['is_active' => ! $coupon->is_active]);
        Audit::record($coupon->is_active ? 'coupon.resumed' : 'coupon.paused', $coupon->code, ['source' => $coupon->source]);

        return back()->with('ok', $coupon->is_active ? __('Coupon resumed.') : __('Coupon paused.'));
    }

    /** A single-use voucher for a guest by mobile, like the counter desk's quick action (vouchers.give). */
    public function give(Request $request): RedirectResponse
    {
        $data = $request->validate(VouchersController::giveRules($request) + [
            'phone' => 'required|string|max:20',
            'name' => 'nullable|string|max:100',
        ]);
        $phone = normalize_phone($data['phone']);
        if (! $phone) {
            return back()->withInput()->withErrors(['phone' => __('Enter a valid Egyptian mobile number.')]);
        }
        $customer = Customer::query()->firstOrCreate(['phone' => $phone], ['name' => mb_substr((string) ($data['name'] ?? ''), 0, 100), 'source' => 'pos']);
        $voucher = VouchersController::issue($customer, $data, $request->user());

        return back()->with('ok', __('Voucher :code given: :value.', ['code' => $voucher->code, 'value' => $voucher->headline()]));
    }

    /** @return Builder<Coupon> the codes of the kinds this user sees */
    private function visible(User $user): Builder
    {
        $sources = array_merge(
            self::managesCampaignCoupons($user) ? Coupon::KINDS['campaign'] : [],
            self::managesVouchers($user) ? ['voucher', 'batch'] : [],
        );

        return Coupon::query()->whereIn('source', $sources ?: ['-']);
    }
}
