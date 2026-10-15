<?php

namespace App\Services\Reports;

use App\Models\Branch;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTransaction;
use App\Models\MenuItem;
use App\Models\MenuItemView;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The "advanced" part of the reports (feature advanced_reports): item opens vs orders, an hour × weekday heat map,
 * branches side by side, sources and order types, loyalty, new vs returning guests per week, and the same range
 * compared with the period just before it. Aggregated in PHP like SalesReport (same code on MySQL and SQLite).
 */
final class AdvancedReport
{
    /** Items opened at least this often in the range can appear in "viewed but rarely ordered". */
    public const MIN_VIEWS = 5;

    /** @var Collection<int, Order>|null sold (not cancelled) orders of the range */
    private ?Collection $sold = null;

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?int $branchId = null,
    ) {}

    /** The period of the same length just before this one (7 days → the 7 days before). */
    public function previous(): self
    {
        $days = (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;

        return new self($this->from->subDays($days), $this->from->subDay(), $this->branchId);
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        return [
            'comparison' => $this->comparison(),
            'items' => $this->items(),
            'heatmap' => $this->heatmap(),
            'branches' => $this->branches(),
            'sources' => $this->split('source'),
            'types' => $this->split('type'),
            'loyalty' => Entitlements::current()->allows('loyalty_stamps|loyalty_points') ? $this->loyalty() : null,
            'weeks' => $this->weeks(),
        ];
    }

    /**
     * Headline numbers of this period and the previous one, with the change in percent (null when the previous
     * period had nothing to compare with).
     *
     * @return array<string, array{current:float, previous:float, delta:?float}>
     */
    public function comparison(): array
    {
        $now = $this->kpis();
        $before = $this->previous()->kpis();
        $out = [];
        foreach ($now as $key => $value) {
            $out[$key] = ['current' => $value, 'previous' => $before[$key], 'delta' => self::delta($value, $before[$key])];
        }

        return $out;
    }

    /** Change from $previous to $current in percent, one decimal; null when $previous is 0. */
    public static function delta(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return null;
        }

        return round(($current - $previous) * 100 / $previous, 1);
    }

    /** @return array{revenue:float, orders:float, average:float, guests:float} */
    public function kpis(): array
    {
        $sold = $this->sold();
        $revenue = round((float) $sold->sum('total'), 2);

        return [
            'revenue' => $revenue,
            'orders' => (float) $sold->count(),
            'average' => $sold->count() ? round($revenue / $sold->count(), 2) : 0.0,
            'guests' => (float) $sold->pluck('customer_id')->filter()->unique()->count(),
        ];
    }

    /**
     * Every item opened or ordered in the range: opens (item sheets), quantity ordered, orders that had it, and the
     * share of opens that ended in an order. "low" lists the most opened items that were rarely ordered.
     *
     * @return array{rows: list<array{id:int, name:string, views:int, qty:int, orders:int, conversion:?float}>, low: list<array<string,mixed>>}
     */
    public function items(): array
    {
        $views = MenuItemView::query()->whereBetween('day', [$this->from->startOfDay(), $this->to->endOfDay()])
            ->selectRaw('menu_item_id, SUM(views) as views')->groupBy('menu_item_id')->pluck('views', 'menu_item_id')
            ->map(fn ($v) => (int) $v);
        $orderIds = $this->sold()->pluck('id');
        $ordered = $orderIds->isEmpty() ? collect() : OrderItem::query()->whereIn('order_id', $orderIds)->whereNotNull('menu_item_id')
            ->selectRaw('menu_item_id, SUM(quantity) as qty, COUNT(DISTINCT order_id) as orders')->groupBy('menu_item_id')->get()
            ->keyBy('menu_item_id');
        $ids = $views->keys()->merge($ordered->keys())->unique()->values();
        $names = MenuItem::query()->whereIn('id', $ids)->get(['id', 'name', 'name_en'])->keyBy('id');

        $rows = $ids->filter(fn ($id) => $names->has($id))->map(function ($id) use ($views, $ordered, $names) {
            $v = (int) ($views[$id] ?? 0);
            $orders = (int) ($ordered[$id]->orders ?? 0);

            return [
                'id' => (int) $id, 'name' => tr($names[$id]->name, $names[$id]->name_en), 'views' => $v,
                'qty' => (int) ($ordered[$id]->qty ?? 0), 'orders' => $orders,
                'conversion' => $v > 0 ? round(min($orders, $v) * 100 / $v, 1) : null,
            ];
        })->sortByDesc('views')->values();

        $low = $rows->filter(fn ($r) => $r['views'] >= self::MIN_VIEWS)
            ->sortBy([['conversion', 'asc'], ['views', 'desc']])->take(10)->values()->all();

        return ['rows' => $rows->all(), 'low' => $low];
    }

    /**
     * Orders per weekday (rows, Saturday first like the Egyptian week) and hour (columns), local time.
     *
     * @return array{cells: array<int, array<int, int>>, max: int, days: list<int>}
     */
    public function heatmap(): array
    {
        $days = [6, 0, 1, 2, 3, 4, 5]; // Carbon dayOfWeek: 0 = Sunday … 6 = Saturday
        $cells = array_fill_keys($days, array_fill(0, 24, 0));
        foreach ($this->sold() as $o) {
            $at = $o->created_at->timezone(config('app.timezone'));
            $cells[$at->dayOfWeek][(int) $at->format('G')]++;
        }

        return ['cells' => $cells, 'max' => max(array_map('max', $cells)), 'days' => $days];
    }

    /**
     * Branches side by side (only when the restaurant has more than one and no branch filter is set).
     *
     * @return list<array{id:int, name:string, revenue:float, orders:int, average:float, share:float}>
     */
    public function branches(): array
    {
        $branches = Branch::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'name_en']);
        if ($this->branchId || $branches->count() < 2) {
            return [];
        }
        $sold = $this->sold();
        $total = max(0.01, (float) $sold->sum('total'));

        return $branches->map(function (Branch $b) use ($sold, $total) {
            $orders = $sold->where('branch_id', $b->id);
            $revenue = round((float) $orders->sum('total'), 2);

            return [
                'id' => $b->id, 'name' => tr($b->name, $b->name_en), 'revenue' => $revenue, 'orders' => $orders->count(),
                'average' => $orders->count() ? round($revenue / $orders->count(), 2) : 0.0,
                'share' => round($revenue * 100 / $total, 1),
            ];
        })->sortByDesc('revenue')->values()->all();
    }

    /**
     * Orders grouped by "source" (QR, online, POS, call center...) or "type" (dine-in, takeaway, delivery).
     *
     * @return list<array{key:string, label:string, orders:int, revenue:float, share:float}>
     */
    public function split(string $field): array
    {
        $sold = $this->sold();
        $count = max(1, $sold->count());
        $labels = $field === 'source' ? Order::SOURCES : Order::TYPES;

        return $sold->groupBy(fn ($o) => (string) $o->{$field})->map(fn ($group, $key) => [
            'key' => (string) $key,
            'label' => isset($labels[$key]) ? (app()->getLocale() === 'en' ? $labels[$key][1] : $labels[$key][0]) : ($key === 'kiosk' ? __('Kiosk') : (string) $key),
            'orders' => $group->count(),
            'revenue' => round((float) $group->sum('total'), 2),
            'share' => round($group->count() * 100 / $count, 1),
        ])->sortByDesc('orders')->values()->all();
    }

    /** @return array{cards:int, new_cards:int, stamps:int, points:int, rewards_redeemed:int} */
    public function loyalty(): array
    {
        $range = [$this->from->startOfDay(), $this->to->endOfDay()];
        $tx = fn (string $type) => (int) LoyaltyTransaction::query()->where('type', $type)->whereBetween('created_at', $range)
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))->sum('amount');

        return [
            'cards' => LoyaltyCard::query()->count(),
            'new_cards' => LoyaltyCard::query()->whereBetween('created_at', $range)->count(),
            'stamps' => $tx('stamp'),
            'points' => $tx('points'),
            'rewards_redeemed' => LoyaltyReward::query()->where('status', 'redeemed')->whereBetween('redeemed_at', $range)->count(),
        ];
    }

    /**
     * Guests with a mobile number per week (weeks start on Saturday): new = their first order ever was that week.
     *
     * @return list<array{week:string, new:int, returning:int}>
     */
    public function weeks(): array
    {
        $sold = $this->sold()->filter(fn ($o) => $o->customer_id);
        $first = $sold->isEmpty() ? collect() : Order::query()->whereIn('customer_id', $sold->pluck('customer_id')->unique())
            ->where('status', '!=', 'cancelled')->selectRaw('customer_id, MIN(created_at) as first_at')->groupBy('customer_id')
            ->pluck('first_at', 'customer_id');

        $weeks = [];
        for ($start = $this->weekStart($this->from); $start->lte($this->to); $start = $start->addWeek()) {
            $weeks[$start->toDateString()] = ['week' => $start->toDateString(), 'new' => [], 'returning' => []];
        }
        foreach ($sold as $o) {
            $key = $this->weekStart(CarbonImmutable::instance($o->created_at))->toDateString();
            if (! isset($weeks[$key])) {
                continue;
            }
            $firstAt = isset($first[$o->customer_id]) ? CarbonImmutable::parse($first[$o->customer_id]) : $o->created_at;
            $isNew = $this->weekStart($firstAt)->toDateString() === $key;
            $weeks[$key][$isNew ? 'new' : 'returning'][$o->customer_id] = true;
        }
        foreach ($weeks as $key => $w) {
            $weeks[$key]['returning'] = count(array_diff_key($w['returning'], $w['new']));
            $weeks[$key]['new'] = count($w['new']);
        }

        return array_values($weeks);
    }

    public static function kpiLabel(string $key): string
    {
        return match ($key) {
            'revenue' => __('Sales'),
            'orders' => __('Orders'),
            'average' => __('Average order'),
            'guests' => __('Guests with a mobile number'),
            default => $key,
        };
    }

    /** Day name for Carbon's dayOfWeek (0 = Sunday). */
    public static function dayName(int $day): string
    {
        return CarbonImmutable::now()->startOfWeek(0)->addDays($day)->locale(app()->getLocale())->dayName;
    }

    /** @return Collection<int, Order> */
    private function sold(): Collection
    {
        return $this->sold ??= Order::query()->whereBetween('created_at', [$this->from->startOfDay(), $this->to->endOfDay()])
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))
            ->where('status', '!=', 'cancelled')
            ->get(['id', 'branch_id', 'customer_id', 'type', 'source', 'total', 'created_at']);
    }

    private function weekStart(\DateTimeInterface $at): CarbonImmutable
    {
        $day = CarbonImmutable::instance($at)->timezone(config('app.timezone'))->startOfDay();

        return $day->subDays(($day->dayOfWeek + 1) % 7); // back to Saturday
    }
}
