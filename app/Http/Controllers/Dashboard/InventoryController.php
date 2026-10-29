<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\PackagingRule;
use App\Models\RecipeLine;
use App\Models\StockMovement;
use App\Services\Audit;
use App\Services\Inventory\StockService;
use App\Services\Inventory\VarianceReport;
use App\Services\Menu\MenuCatalog;
use App\Services\Settings;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inventory pack: ingredients and stock per branch, receiving / waste / counts, recipes and food cost, and the stock
 * variance report. Count sheets, suppliers and purchases have their own controllers (StockCountsController,
 * PurchasesController).
 */
class InventoryController extends Controller
{
    private const MAX_RECIPE_LINES = 30;

    public function index(Request $request): View
    {
        $branch = self::currentBranch($request);
        $stock = IngredientStock::query()->where('branch_id', $branch->id)->pluck('quantity', 'ingredient_id');
        $kind = self::kindFilter($request);
        $ingredients = Ingredient::query()->when($kind, fn ($q) => $q->where('kind', $kind))->orderBy('name')->get()->each(function (Ingredient $i) use ($stock) {
            $i->setAttribute('on_hand', (float) ($stock[$i->id] ?? 0));
            $i->setAttribute('is_low', $i->is_active && $i->min_stock > 0 && $i->getAttribute('on_hand') <= $i->min_stock);
        });

        return view('dashboard.inventory.index', [
            'branch' => $branch, 'branches' => self::branchChoices($request), 'ingredients' => $ingredients,
            'isManager' => Permissions::isManager($request->user()), 'kind' => $kind,
            'stockValue' => round($ingredients->sum(fn (Ingredient $i) => max(0, $i->getAttribute('on_hand')) * $i->cost_per_unit), 2),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Ingredient::query()->create($this->validated($request));

        return back()->with('ok', __('Ingredient saved.'));
    }

    public function update(Request $request, Ingredient $ingredient): RedirectResponse
    {
        $ingredient->update($this->validated($request) + ['is_active' => $request->boolean('is_active')]);

        return back()->with('ok', __('Ingredient saved.'));
    }

    /** Receive goods, record waste, or (a manager) correct one ingredient to a physical count, for the chosen branch. */
    public function move(Request $request, StockService $stock): RedirectResponse
    {
        $branch = self::currentBranch($request);
        $data = $request->validate([
            'ingredient_id' => ['required', Rule::exists('ingredients', 'id')->where('tenant_id', tenant()->getTenantKey())],
            'type' => ['required', Rule::in(['purchase', 'waste', 'count'])],
            'quantity' => 'required|numeric|min:0|max:1000000',
            'total_cost' => 'nullable|numeric|min:0|max:10000000',
            'note' => 'nullable|string|max:200',
        ]);
        if ($data['type'] === 'count' && ! Permissions::isManager($request->user())) {
            return back()->withErrors(['type' => __('Only a manager can correct the stock to a count.')])->withInput();
        }
        $ingredient = Ingredient::query()->findOrFail($data['ingredient_id']);
        $qty = (float) $data['quantity'];
        if ($data['type'] !== 'count' && $qty <= 0) {
            return back()->withErrors(['quantity' => __('Enter a quantity.')])->withInput();
        }
        match ($data['type']) {
            'purchase' => $stock->purchase($ingredient, $branch, $qty, (float) ($data['total_cost'] ?? 0), $request->user(), $data['note'] ?? null),
            'waste' => $stock->waste($ingredient, $branch, $qty, $request->user(), $data['note'] ?? null),
            'count' => $stock->count($ingredient, $branch, $qty, $request->user(), $data['note'] ?? null),
        };
        Audit::record('stock.'.$data['type'], $ingredient->name, ['ingredient_id' => $ingredient->id, 'branch_id' => $branch->id, 'quantity' => $qty, 'note' => $data['note'] ?? null]);

        return back()->with('ok', __('Stock updated.'));
    }

    public function movements(Request $request): View
    {
        $branch = self::currentBranch($request);
        $query = StockMovement::query()->where('branch_id', $branch->id)->with(['ingredient', 'user', 'order:id,number', 'purchase:id', 'stockCount:id'])->latest('id');
        if ($request->filled('type') && isset(StockMovement::TYPES[$request->query('type')])) {
            $query->where('type', $request->query('type'));
        }
        if ($request->filled('ingredient')) {
            $query->where('ingredient_id', (int) $request->query('ingredient'));
        }
        if ($kind = self::kindFilter($request)) {
            $query->whereHas('ingredient', fn ($q) => $q->where('kind', $kind));
        }

        return view('dashboard.inventory.movements', [
            'branch' => $branch, 'branches' => self::branchChoices($request), 'movements' => $query->paginate(50)->withQueryString(),
            'ingredients' => Ingredient::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Every item with its ingredient cost and food-cost percentage (items above the target are flagged), its cost
     * with the takeaway packaging, and the packaging every takeaway / delivery order takes.
     */
    public function recipes(Request $request, StockService $stock): View
    {
        $sums = RecipeLine::query()->whereNull('item_option_id')->join('ingredients', 'ingredients.id', '=', 'recipe_lines.ingredient_id')
            ->groupBy('recipe_lines.menu_item_id')
            ->selectRaw('recipe_lines.menu_item_id, SUM(CASE WHEN recipe_lines.takeaway_only = ? THEN 0 ELSE recipe_lines.quantity * ingredients.cost_per_unit END) as cost,
                SUM(recipe_lines.quantity * ingredients.cost_per_unit) as takeaway_cost', [true])
            ->get()->keyBy('menu_item_id');
        $costs = $sums->map(fn ($row) => (float) $row->cost);
        $takeawayCosts = $sums->map(fn ($row) => (float) $row->takeaway_cost);

        $target = $stock->foodCostTarget();
        $categories = Category::query()->orderBy('sort_order')->with(['items' => fn ($q) => $q->orderBy('sort_order')])->get();
        $isAbove = fn (MenuItem $item) => isset($costs[$item->id]) && $item->price > 0 && round((float) $costs[$item->id] / $item->price * 100) > $target;

        return view('dashboard.inventory.recipes', [
            'categories' => $categories, 'costs' => $costs, 'takeawayCosts' => $takeawayCosts, 'target' => $target, 'onlyAbove' => $request->boolean('above'),
            'aboveCount' => $categories->flatMap->items->filter($isAbove)->count(), 'isAbove' => $isAbove,
            'rules' => PackagingRule::query()->with('ingredient')->get()->sortBy(fn (PackagingRule $r) => [$r->order_type, $r->ingredient?->name])->values(),
            'orderPackaging' => $stock->orderPackagingCosts(),
            'packaging' => Ingredient::query()->where('is_active', true)->orderByRaw("kind = 'packaging' desc")->orderBy('name')->get(),
        ]);
    }

    /** Adds (or changes the quantity of) packaging taken once by every takeaway or delivery order. */
    public function storePackagingRule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ingredient_id' => ['required', Rule::exists('ingredients', 'id')->where('tenant_id', tenant()->getTenantKey())],
            'order_type' => ['required', Rule::in(array_keys(PackagingRule::ORDER_TYPES))],
            'quantity' => 'required|numeric|gt:0|max:1000',
        ]);
        PackagingRule::query()->updateOrCreate(['ingredient_id' => (int) $data['ingredient_id'], 'order_type' => $data['order_type']], ['quantity' => (float) $data['quantity']]);

        return back()->with('ok', __('Packaging per order saved.'));
    }

    public function destroyPackagingRule(PackagingRule $rule): RedirectResponse
    {
        $rule->delete();

        return back()->with('ok', __('Packaging per order saved.'));
    }

    /** The food cost % above which items are flagged on Recipes & cost. */
    public function foodCostTarget(Request $request): RedirectResponse
    {
        $data = $request->validate(['food_cost_target' => 'required|numeric|min:1|max:100']);
        Settings::put(['food_cost_target' => (string) round((float) $data['food_cost_target'], 1)]);

        return back()->with('ok', __('Food cost target saved.'));
    }

    /**
     * Opening, purchases, sales usage, waste, count corrections and closing per ingredient for a period, with what
     * the sales and waste do not explain, biggest money loss first. ?format=csv|xlsx downloads it.
     */
    public function variance(Request $request): View|Response
    {
        $branch = self::currentBranch($request);
        $today = CarbonImmutable::today();
        $from = $request->filled('from') ? rescue(fn () => CarbonImmutable::parse((string) $request->query('from')), $today->startOfMonth(), false) : $today->startOfMonth();
        $to = $request->filled('to') ? rescue(fn () => CarbonImmutable::parse((string) $request->query('to')), $today, false) : $today;
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->subDays(366);
        }
        $kind = self::kindFilter($request);
        $rows = (new VarianceReport($branch, $from, $to, $kind))->rows();

        if (in_array($request->query('format'), ['csv', 'xlsx'], true)) {
            return CorporateContractsController::download(VarianceReport::sheet($rows),
                'stock-variance-'.$from->format('Ymd').'-'.$to->format('Ymd'), (string) $request->query('format'), __('Stock variance'));
        }

        return view('dashboard.inventory.variance', [
            'branch' => $branch, 'branches' => self::branchChoices($request), 'rows' => $rows, 'totals' => VarianceReport::totals($rows),
            'from' => $from, 'to' => $to, 'kind' => $kind, 'kindTotals' => VarianceReport::totalsByKind($rows),
            'orderPackaging' => app(StockService::class)->orderPackagingCosts(),
        ]);
    }

    public function recipe(MenuItem $item): View
    {
        return view('dashboard.inventory.recipe', [
            'item' => $item->load('optionGroups.options'),
            'lines' => RecipeLine::query()->where('menu_item_id', $item->id)->with('ingredient')->orderBy('id')->get(),
            'ingredients' => Ingredient::query()->where('is_active', true)->orderBy('name')->get(),
            'cost' => app(StockService::class)->itemCost($item),
            'takeawayCost' => app(StockService::class)->itemCost($item, true),
            'max' => self::MAX_RECIPE_LINES,
        ]);
    }

    /** Replaces the item's recipe with the posted rows (empty rows are skipped). */
    public function saveRecipe(Request $request, MenuItem $item): RedirectResponse
    {
        $tenant = tenant()->getTenantKey();
        $request->validate([
            'lines' => 'array|max:'.self::MAX_RECIPE_LINES,
            'lines.*.ingredient_id' => ['nullable', Rule::exists('ingredients', 'id')->where('tenant_id', $tenant)],
            'lines.*.quantity' => 'nullable|numeric|min:0|max:100000',
            'lines.*.option_id' => ['nullable', 'integer'],
            'lines.*.takeaway_only' => ['nullable', 'boolean'],
        ]);
        $optionIds = $item->optionGroups()->with('options:id,item_option_group_id')->get()->flatMap->options->pluck('id')->all();

        DB::transaction(function () use ($request, $item, $optionIds) {
            RecipeLine::query()->where('menu_item_id', $item->id)->delete();
            foreach ((array) $request->input('lines', []) as $row) {
                $qty = (float) ($row['quantity'] ?? 0);
                if (empty($row['ingredient_id']) || $qty <= 0) {
                    continue;
                }
                $option = ! empty($row['option_id']) && in_array((int) $row['option_id'], $optionIds, true) ? (int) $row['option_id'] : null;
                RecipeLine::query()->create([
                    'menu_item_id' => $item->id, 'ingredient_id' => (int) $row['ingredient_id'], 'item_option_id' => $option, 'quantity' => $qty,
                    'takeaway_only' => ! empty($row['takeaway_only']),
                ]);
            }
        });

        return back()->with('ok', __('Recipe saved.'));
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'unit' => ['required', Rule::in(array_keys(Ingredient::UNITS))],
            'cost_per_unit' => 'nullable|numeric|min:0|max:1000000',
            'min_stock' => 'nullable|numeric|min:0|max:1000000',
            'kind' => ['nullable', Rule::in(array_keys(Ingredient::KINDS))],
        ]);

        return ['cost_per_unit' => (float) ($data['cost_per_unit'] ?? 0), 'min_stock' => (float) ($data['min_stock'] ?? 0),
            'kind' => $data['kind'] ?? 'ingredient'] + $data;
    }

    /** The ?kind= filter of the inventory pages (ingredient | packaging), null for both. */
    public static function kindFilter(Request $request): ?string
    {
        $kind = (string) $request->query('kind');

        return isset(Ingredient::KINDS[$kind]) ? $kind : null;
    }

    /** The branch inventory pages work on: the staff member's own, else the one picked (kept in the session). */
    public static function currentBranch(Request $request): Branch
    {
        if ($id = $request->user()->branch_id) {
            return Branch::query()->findOrFail($id);
        }
        if ($request->filled('branch')) {
            $request->session()->put('inventory.branch', (int) $request->input('branch'));
        }

        return Branch::query()->find((int) $request->session()->get('inventory.branch')) ?? MenuCatalog::defaultBranch();
    }

    /** @return Collection<int,Branch> the branches to pick from (none for staff tied to one branch) */
    public static function branchChoices(Request $request): Collection
    {
        return $request->user()->branch_id ? collect() : Branch::query()->orderBy('sort_order')->get();
    }
}
