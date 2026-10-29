<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Services\Audit;
use App\Services\Inventory\StockService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Stock counts (جرد) of a branch: start a sheet for every ingredient or a few, type what is on the shelf and save it
 * as a draft to finish later; a manager posts it (StockService::postCount) and it becomes read-only.
 */
class StockCountsController extends Controller
{
    public function index(Request $request): View
    {
        $branch = InventoryController::currentBranch($request);
        $value = StockCountLine::query()->selectRaw('SUM(adjustment * unit_cost)')->whereColumn('stock_count_lines.stock_count_id', 'stock_counts.id');

        return view('dashboard.inventory.counts', [
            'branch' => $branch, 'branches' => InventoryController::branchChoices($request),
            'counts' => StockCount::query()->where('branch_id', $branch->id)->with(['creator:id,name', 'poster:id,name'])
                ->withCount(['lines', 'lines as counted_count' => fn ($q) => $q->whereNotNull('counted')])
                ->addSelect(['value' => $value])->latest('id')->paginate(30),
            'ingredients' => Ingredient::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /** A new draft sheet for the current branch: every active item, only the ingredients or the packaging, or the ones ticked. */
    public function store(Request $request): RedirectResponse
    {
        $branch = InventoryController::currentBranch($request);
        $data = $request->validate([
            'scope' => ['required', Rule::in(['all', 'some', ...array_keys(Ingredient::KINDS)])],
            'ingredient_ids' => 'required_if:scope,some|array|max:500',
            'ingredient_ids.*' => ['integer', Rule::exists('ingredients', 'id')->where('tenant_id', tenant()->getTenantKey())],
            'note' => 'nullable|string|max:200',
        ]);
        $ids = Ingredient::query()->where('is_active', true)
            ->when($data['scope'] === 'some', fn ($q) => $q->whereIn('id', $data['ingredient_ids'] ?? []))
            ->when(isset(Ingredient::KINDS[$data['scope']]), fn ($q) => $q->where('kind', $data['scope']))->orderBy('name')->pluck('id');
        if ($ids->isEmpty()) {
            return back()->withErrors(['ingredient_ids' => __('Choose at least one ingredient to count.')])->withInput();
        }

        $count = DB::transaction(function () use ($branch, $data, $ids, $request) {
            $count = StockCount::query()->create(['branch_id' => $branch->id, 'note' => $data['note'] ?? null, 'created_by' => $request->user()->id]);
            foreach ($ids as $id) {
                StockCountLine::query()->create(['stock_count_id' => $count->id, 'ingredient_id' => $id]);
            }

            return $count;
        });

        return redirect()->route('dash.inventory.counts.show', $count);
    }

    /** The sheet: expected (system) against counted, and the difference in quantity and money. */
    public function show(Request $request, StockCount $count): View
    {
        $this->authorizeBranch($request, $count);
        $count->load(['branch', 'creator:id,name', 'poster:id,name']);
        $live = IngredientStock::query()->where('branch_id', $count->branch_id)->pluck('quantity', 'ingredient_id');
        $lines = $count->lines()->with('ingredient')->get()->sortBy(fn (StockCountLine $l) => $l->ingredient?->name)->values()
            ->each(function (StockCountLine $line) use ($count, $live) {
                $expected = $count->isDraft() && $line->counted === null ? (float) ($live[$line->ingredient_id] ?? 0) : (float) $line->expected;
                $cost = $count->isDraft() ? (float) $line->ingredient?->cost_per_unit : (float) $line->unit_cost;
                $diff = $line->counted === null ? null : round($line->counted - $expected, 3);
                $line->setAttribute('shown_expected', $expected);
                $line->setAttribute('diff', $diff);
                $line->setAttribute('diff_value', $diff === null ? null : round($diff * $cost, 2));
            });

        return view('dashboard.inventory.count', [
            'count' => $count, 'lines' => $lines, 'isManager' => Permissions::isManager($request->user()),
            'shortage' => round($lines->where('diff_value', '<', 0)->sum('diff_value'), 2),
            'surplus' => round($lines->where('diff_value', '>', 0)->sum('diff_value'), 2),
        ]);
    }

    /** Saves the quantities typed so far; the sheet stays a draft. */
    public function save(Request $request, StockCount $count, StockService $stock): RedirectResponse
    {
        $this->authorizeBranch($request, $count);
        if (! $count->isDraft()) {
            return back()->withErrors(['count' => __('This count is posted and can no longer change.')]);
        }
        $stock->saveCount($count, $this->counted($request));

        return back()->with('ok', __('Count saved. You can finish it later.'));
    }

    /** A manager saves the last quantities and posts the sheet: the stock of every counted ingredient is corrected. */
    public function post(Request $request, StockCount $count, StockService $stock): RedirectResponse
    {
        $this->authorizeBranch($request, $count);
        abort_unless(Permissions::isManager($request->user()), 403, __('Only a manager can post a stock count.'));
        if (! $count->isDraft()) {
            return back()->withErrors(['count' => __('This count is posted and can no longer change.')]);
        }
        $stock->saveCount($count, $this->counted($request));
        if (! $count->lines()->whereNotNull('counted')->exists()) {
            return back()->withErrors(['count' => __('Enter at least one counted quantity before posting.')]);
        }
        if (! $stock->postCount($count, $request->user())) {
            return back()->withErrors(['count' => __('This count is posted and can no longer change.')]);
        }
        $lines = $count->lines()->whereNotNull('adjustment')->get();
        Audit::record('stock.count_posted', '#'.$count->id, [
            'stock_count_id' => $count->id, 'branch_id' => $count->branch_id, 'lines' => $lines->count(),
            'adjusted' => $lines->filter(fn ($l) => abs($l->adjustment) >= 0.0005)->count(),
            'value' => round($lines->sum(fn ($l) => $l->adjustment * $l->unit_cost), 2),
        ]);

        return redirect()->route('dash.inventory.counts.show', $count)->with('ok', __('Count posted. The stock now matches the shelf.'));
    }

    /** Throws away a draft sheet (a posted one stays). */
    public function destroy(Request $request, StockCount $count): RedirectResponse
    {
        $this->authorizeBranch($request, $count);
        if (! $count->isDraft()) {
            return back()->withErrors(['count' => __('This count is posted and can no longer change.')]);
        }
        $count->delete();

        return redirect()->route('dash.inventory.counts')->with('ok', __('Draft count deleted.'));
    }

    /** @return array<int|string, mixed> line id => quantity typed ('' = not counted) */
    private function counted(Request $request): array
    {
        $request->validate(['counted' => 'array', 'counted.*' => 'nullable|numeric|min:0|max:1000000']);

        return (array) $request->input('counted', []);
    }

    /** Staff tied to one branch only see that branch's sheets. */
    private function authorizeBranch(Request $request, StockCount $count): void
    {
        $own = $request->user()->branch_id;
        abort_if($own && (int) $own !== (int) $count->branch_id, 404);
    }
}
