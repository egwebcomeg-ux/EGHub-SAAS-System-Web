<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Services\Audit;
use App\Services\Qr;
use App\Services\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Discount vouchers of the loyalty card (feature loyalty_stamps): single codes, codes held by one guest, printed
 * batches of single-use codes, and what each brought in. Guests use them like any coupon (App\Services\Orders\Coupons).
 */
class VouchersController extends Controller
{
    public const MAX_BATCH = 500;

    public const PRINT_LAYOUTS = ['a6', 'a4'];

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['active', 'used', 'expired', 'paused'], true) ? (string) $request->query('status') : '';
        $batch = trim((string) $request->query('batch', ''));

        $vouchers = Coupon::query()->vouchers()->with('customer')
            ->withCount(['orders as orders_count' => fn ($o) => $o->where('status', '!=', 'cancelled')])
            ->withSum(['orders as revenue' => fn ($o) => $o->where('status', '!=', 'cancelled')], 'total')
            ->withSum(['orders as discount_given' => fn ($o) => $o->where('status', '!=', 'cancelled')], 'coupon_discount')
            ->when($q !== '', fn (Builder $w) => $w->where(fn (Builder $w) => $w->where('code', 'like', '%'.Coupon::normalize($q).'%')
                ->orWhere('note', 'like', "%$q%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('phone', 'like', '%'.(normalize_phone($q) ?? $q).'%')->orWhere('name', 'like', "%$q%"))))
            ->when($batch !== '', fn (Builder $w) => $w->where('batch', $batch))
            ->when($status !== '', fn (Builder $w) => $this->whereStatus($w, $status))
            ->latest('id')->paginate(30)->withQueryString();

        $orders = DB::table('orders')->where('tenant_id', tenant()->getTenantKey())->where('status', '!=', 'cancelled')
            ->whereIn('coupon_id', Coupon::query()->vouchers()->select('id'));

        return view('dashboard.loyalty.vouchers', [
            'vouchers' => $vouchers, 'q' => $q, 'status' => $status, 'batch' => $batch,
            'batches' => Coupon::query()->vouchers()->whereNotNull('batch')->groupBy('batch')
                ->selectRaw('batch, count(*) as codes, sum(uses) as used, max(id) as last_id')->orderByDesc('last_id')->limit(50)->get(),
            'stats' => [
                'total' => Coupon::query()->vouchers()->count(),
                'active' => $this->whereStatus(Coupon::query()->vouchers(), 'active')->count(),
                'orders' => (clone $orders)->count(),
                'revenue' => round((float) (clone $orders)->sum('total'), 2),
                'discount' => round((float) (clone $orders)->sum('coupon_discount'), 2),
            ],
            'categories' => Category::query()->orderBy('sort_order')->get(),
            'items' => MenuItem::query()->orderBy('name')->get(['id', 'name', 'name_en', 'category_id']),
            'prefix' => Coupon::defaultPrefix(),
        ]);
    }

    /** One voucher: a typed or generated code, optionally held by one guest (by mobile). */
    public function store(Request $request): RedirectResponse
    {
        $request->merge(['code' => Coupon::normalize((string) $request->input('code', ''))]);
        $data = $request->validate($this->rules() + [
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Z0-9_-]{3,30}$/', Rule::unique('coupons', 'code')->where('tenant_id', tenant()->getTenantKey())],
            'max_uses' => 'nullable|integer|min:1|max:1000000',
            'phone' => 'nullable|string|max:20',
            'name' => 'nullable|string|max:100',
        ], [], ['code' => __('Voucher code')]);

        $customer = null;
        if (($data['phone'] ?? '') !== '') {
            $phone = normalize_phone($data['phone']);
            if (! $phone) {
                return back()->withInput()->withErrors(['phone' => __('Enter a valid Egyptian mobile number.')]);
            }
            $customer = Customer::query()->firstOrCreate(['phone' => $phone], ['name' => mb_substr((string) ($data['name'] ?? ''), 0, 100), 'source' => 'pos']);
        }

        $voucher = Coupon::query()->create($this->attributes($data, $request) + [
            'code' => ($data['code'] ?? '') !== '' ? $data['code'] : Coupon::generateCode(),
            'source' => 'voucher', 'customer_id' => $customer?->id,
            'max_uses' => $customer ? ($data['max_uses'] ?? 1) : ($data['max_uses'] ?? null),
        ]);
        Audit::record('voucher.created', $voucher->code, ['customer' => $customer?->id, 'type' => $voucher->type, 'value' => $voucher->value]);

        return redirect()->route('dash.vouchers')->with('ok', __('Voucher :code created.', ['code' => $voucher->code]));
    }

    /** N unique single-use codes with the same rules, grouped under one batch name (print, CSV). */
    public function batch(Request $request): RedirectResponse
    {
        $request->merge(['prefix' => Coupon::normalize((string) $request->input('prefix', ''))]);
        $data = $request->validate($this->rules() + [
            'count' => 'required|integer|min:1|max:'.self::MAX_BATCH,
            'batch' => 'nullable|string|max:40',
            'prefix' => ['nullable', 'string', 'regex:/^[A-Z0-9]{2,8}$/'],
        ]);
        $name = trim((string) ($data['batch'] ?? '')) ?: __('Batch').' '.now()->format('Y-m-d H:i');
        if (Coupon::query()->where('batch', $name)->exists()) {
            return back()->withInput()->withErrors(['batch' => __('A batch with this name already exists.')]);
        }

        $attributes = $this->attributes($data, $request) + ['source' => 'batch', 'batch' => $name, 'max_uses' => 1];
        DB::transaction(function () use ($data, $attributes) {
            for ($i = 0; $i < (int) $data['count']; $i++) {
                Coupon::query()->create($attributes + ['code' => Coupon::generateCode(($data['prefix'] ?? '') ?: null)]);
            }
        });
        Audit::record('voucher.batch', $name, ['count' => (int) $data['count']]);

        return redirect()->route('dash.vouchers', ['batch' => $name])->with('ok', __(':n vouchers created in :batch.', ['n' => (int) $data['count'], 'batch' => $name]));
    }

    /** Pause or resume one voucher, or a whole batch. */
    public function toggle(Request $request, Coupon $voucher): RedirectResponse
    {
        abort_unless(in_array($voucher->source, ['voucher', 'batch'], true), 404);
        $voucher->update(['is_active' => ! $voucher->is_active]);
        Audit::record($voucher->is_active ? 'voucher.resumed' : 'voucher.paused', $voucher->code);

        return back()->with('ok', $voucher->is_active ? __('Voucher resumed.') : __('Voucher paused.'));
    }

    public function toggleBatch(Request $request): RedirectResponse
    {
        $data = $request->validate(['batch' => 'required|string|max:40', 'active' => 'required|boolean']);
        $count = Coupon::query()->vouchers()->where('batch', $data['batch'])->update(['is_active' => (bool) $data['active']]);
        abort_if($count === 0, 404);
        Audit::record($data['active'] ? 'voucher.resumed' : 'voucher.paused', $data['batch'], ['count' => $count]);

        return back()->with('ok', $data['active'] ? __('Batch resumed.') : __('Batch paused.'));
    }

    /** The codes of one batch as CSV (to hand to a printer or a partner). */
    public function export(Request $request): StreamedResponse
    {
        $batch = trim((string) $request->query('batch', ''));
        $vouchers = Coupon::query()->vouchers()->where('batch', $batch)->orderBy('id')->get();
        abort_if($batch === '' || $vouchers->isEmpty(), 404);
        $names = $vouchers->first()->qualifyingNames();
        $rows = $vouchers->map(fn (Coupon $v) => [
            $v->code, $v->headline(), $v->conditions($names), $v->starts_at?->format('Y-m-d'), $v->expires_at?->format('Y-m-d'),
            $v->uses, $this->stateLabel($v->state()), $v->menuUrl(),
        ]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('Code'), __('Discount'), __('Conditions'), __('Starts'), __('Expires'), __('Uses'), __('Status'), __('Link')]);
            foreach ($rows as $row) {
                fputcsv($out, csv_row($row));
            }
            fclose($out);
        }, 'vouchers-'.$vouchers->first()->id.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Printable vouchers (A6 one per page, or 8 per A4): one voucher, a batch, or the listed ids. */
    public function print(Request $request): View
    {
        $layout = in_array($request->query('layout'), self::PRINT_LAYOUTS, true) ? (string) $request->query('layout') : 'a6';
        $query = Coupon::query()->vouchers()->orderBy('id');
        if ($request->filled('batch')) {
            $query->where('batch', (string) $request->query('batch'));
        } else {
            $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids', ''))));
            $query->whereIn('id', $ids);
        }
        $vouchers = $query->limit(self::MAX_BATCH)->get();
        abort_if($vouchers->isEmpty(), 404);
        $names = [];

        return view('dashboard.loyalty.vouchers-print', [
            'layout' => $layout, 'settings' => Settings::all(),
            'cards' => $vouchers->map(function (Coupon $v) use (&$names) {
                $key = json_encode($v->qualifying);
                $names[$key] ??= $v->qualifyingNames();

                return ['voucher' => $v, 'conditions' => $v->conditions($names[$key]), 'qr' => Qr::svg($v->menuUrl(), 240, '#111111')];
            }),
            'query' => $request->only(['batch', 'ids']),
        ]);
    }

    /** Counter desk: a voucher for the guest on the panel, usable once with their mobile. */
    public function give(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['percent', 'amount'])],
            'value' => ['required', 'numeric', 'min:1', $request->input('type') === 'percent' ? 'max:100' : 'max:100000'],
            'days' => 'required|integer|min:1|max:365',
            'min_order' => 'nullable|numeric|min:0|max:100000',
        ]);
        $voucher = Coupon::query()->create([
            'code' => Coupon::generateCode(), 'source' => 'voucher', 'customer_id' => $customer->id,
            'type' => $data['type'], 'value' => $data['value'], 'min_order' => $data['min_order'] ?? 0,
            'expires_at' => now()->addDays((int) $data['days'])->endOfDay(), 'max_uses' => 1, 'once_per_customer' => true,
            'note' => __('Given at the counter by :name', ['name' => mb_substr((string) $request->user()->name, 0, 60)]),
        ]);
        Audit::record('voucher.given', $voucher->code, ['customer' => $customer->id, 'type' => $voucher->type, 'value' => $voucher->value]);

        return redirect()->route('dash.loyalty.desk', ['q' => $customer->phone])
            ->with('ok', __('Voucher :code given: :value.', ['code' => $voucher->code, 'value' => $voucher->headline()]));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        $tenant = tenant()->getTenantKey();

        return [
            'type' => ['required', Rule::in(['percent', 'amount'])],
            'value' => ['required', 'numeric', 'min:1', request()->input('type') === 'percent' ? 'max:100' : 'max:100000'],
            'max_discount' => 'nullable|numeric|min:1|max:100000',
            'min_order' => 'nullable|numeric|min:0|max:100000',
            'starts_at' => 'nullable|date',
            'expires_at' => ['nullable', 'date', 'after_or_equal:today', function (string $attribute, mixed $value, \Closure $fail) {
                if (request()->filled('starts_at') && strtotime((string) $value) < strtotime((string) request()->input('starts_at'))) {
                    $fail(__('The end date must be after the start date.'));
                }
            }],
            'note' => 'nullable|string|max:200',
            'categories' => 'array|max:100',
            'categories.*' => ['integer', Rule::exists('categories', 'id')->where('tenant_id', $tenant)],
            'items' => 'array|max:300',
            'items.*' => ['integer', Rule::exists('menu_items', 'id')->where('tenant_id', $tenant)],
        ];
    }

    /**
     * The voucher rules shared by a single voucher and a batch.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, Request $request): array
    {
        $categories = array_values(array_map('intval', $data['categories'] ?? []));
        $items = array_values(array_map('intval', $data['items'] ?? []));

        return [
            'type' => $data['type'], 'value' => $data['value'],
            'max_discount' => $data['type'] === 'percent' ? ($data['max_discount'] ?? null) : null,
            'min_order' => $data['min_order'] ?? 0,
            'starts_at' => isset($data['starts_at']) ? Carbon::parse($data['starts_at'])->startOfDay() : null,
            'expires_at' => isset($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : null,
            'once_per_customer' => $request->boolean('once_per_customer'),
            'note' => ($data['note'] ?? '') !== '' ? $data['note'] : null,
            'qualifying' => $categories || $items ? ['categories' => $categories, 'items' => $items] : null,
        ];
    }

    private function whereStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'active' => $query->usable()->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now())),
            'used' => $query->whereNotNull('max_uses')->whereColumn('uses', '>=', 'max_uses'),
            'expired' => $query->whereNotNull('expires_at')->where('expires_at', '<=', now()),
            'paused' => $query->where('is_active', false),
        };
    }

    public static function stateLabel(string $state): string
    {
        return [
            'active' => __('Active'), 'paused' => __('Paused'), 'used' => __('Used up'), 'expired' => __('Expired'), 'scheduled' => __('Not started yet'),
        ][$state] ?? $state;
    }
}
