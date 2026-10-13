<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\RecipeLine;
use App\Models\StockMovement;
use App\Services\Inventory\StockService;
use App\Services\Menu\MenuCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Inventory pack: ingredients and stock per branch, receiving / waste / counts, recipes and food cost. */
class InventoryController extends Controller
{
    private const MAX_RECIPE_LINES = 30;

    public function index(Request $request): View
    {
        $branch = $this->branch($request);
        $stock = IngredientStock::query()->where('branch_id', $branch->id)->pluck('quantity', 'ingredient_id');
        $ingredients = Ingredient::query()->orderBy('name')->get()->each(function (Ingredient $i) use ($stock) {
            $i->setAttribute('on_hand', (float) ($stock[$i->id] ?? 0));
            $i->setAttribute('is_low', $i->is_active && $i->min_stock > 0 && $i->getAttribute('on_hand') <= $i->min_stock);
        });

        return view('dashboard.inventory.index', [
            'branch' => $branch, 'branches' => $this->branches($request), 'ingredients' => $ingredients,
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

    /** Receive goods, record waste, or enter a physical count, for the chosen branch. */
    public function move(Request $request, StockService $stock): RedirectResponse
    {
        $branch = $this->branch($request);
        $data = $request->validate([
            'ingredient_id' => ['required', Rule::exists('ingredients', 'id')->where('tenant_id', tenant()->getTenantKey())],
            'type' => ['required', Rule::in(['purchase', 'waste', 'count'])],
            'quantity' => 'required|numeric|min:0|max:1000000',
            'total_cost' => 'nullable|numeric|min:0|max:10000000',
            'note' => 'nullable|string|max:200',
        ]);
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
        return back()->with('ok', __('Stock updated.'));
    }

    public function movements(Request $request): View
    {
        $branch = $this->branch($request);
        $query = StockMovement::query()->where('branch_id', $branch->id)->with(['ingredient', 'user', 'order:id,number'])->latest('id');
        if ($request->filled('type') && isset(StockMovement::TYPES[$request->query('type')])) {
            $query->where('type', $request->query('type'));
        }
        if ($request->filled('ingredient')) {
            $query->where('ingredient_id', (int) $request->query('ingredient'));
        }
        return view('dashboard.inventory.movements', [
            'branch' => $branch, 'branches' => $this->branches($request), 'movements' => $query->paginate(50)->withQueryString(),
            'ingredients' => Ingredient::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Every item with its ingredient cost and food-cost percentage. */
    public function recipes(StockService $stock): View
    {
        $costs = RecipeLine::query()->whereNull('item_option_id')->join('ingredients', 'ingredients.id', '=', 'recipe_lines.ingredient_id')
            ->groupBy('recipe_lines.menu_item_id')
            ->selectRaw('recipe_lines.menu_item_id, SUM(recipe_lines.quantity * ingredients.cost_per_unit) as cost')
            ->pluck('cost', 'menu_item_id');
        return view('dashboard.inventory.recipes', [
            'categories' => Category::query()->orderBy('sort_order')->with(['items' => fn ($q) => $q->orderBy('sort_order')])->get(),
            'costs' => $costs,
        ]);
    }

    public function recipe(MenuItem $item): View
    {
        return view('dashboard.inventory.recipe', [
            'item' => $item->load('optionGroups.options'),
            'lines' => RecipeLine::query()->where('menu_item_id', $item->id)->with('ingredient')->orderBy('id')->get(),
            'ingredients' => Ingredient::query()->where('is_active', true)->orderBy('name')->get(),
            'cost' => app(StockService::class)->itemCost($item),
            'max' => self::MAX_RECIPE_LINES,
        ]);
    }

    /** Replaces the item's recipe with the posted rows (empty rows are skipped). */
    public function saveRecipe(Request $request, MenuItem $item): RedirectResponse
    {
        $tenant = tenant()->getTenantKey();
        $request->validate([
            'lines' => 'array|max:' . self::MAX_RECIPE_LINES,
            'lines.*.ingredient_id' => ['nullable', Rule::exists('ingredients', 'id')->where('tenant_id', $tenant)],
            'lines.*.quantity' => 'nullable|numeric|min:0|max:100000',
            'lines.*.option_id' => ['nullable', 'integer'],
        ]);
        $optionIds = $item->optionGroups()->with('options:id,item_option_group_id')->get()->flatMap->options->pluck('id')->all();

        DB::transaction(function () use ($request, $item, $optionIds) {
            RecipeLine::query()->where('menu_item_id', $item->id)->delete();
            foreach ((array) $request->input('lines', []) as $row) {
                $qty = (float) ($row['quantity'] ?? 0);
                if (empty($row['ingredient_id']) || $qty <= 0) {
                    continue;
                }
                $option = !empty($row['option_id']) && in_array((int) $row['option_id'], $optionIds, true) ? (int) $row['option_id'] : null;
                RecipeLine::query()->create([
                    'menu_item_id' => $item->id, 'ingredient_id' => (int) $row['ingredient_id'], 'item_option_id' => $option, 'quantity' => $qty,
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
        ]);
        return ['cost_per_unit' => (float) ($data['cost_per_unit'] ?? 0), 'min_stock' => (float) ($data['min_stock'] ?? 0)] + $data;
    }

    private function branch(Request $request): Branch
    {
        if ($id = $request->user()->branch_id) {
            return Branch::query()->findOrFail($id);
        }
        if ($request->filled('branch')) {
            $request->session()->put('inventory.branch', (int) $request->input('branch'));
        }
        return Branch::query()->find((int) $request->session()->get('inventory.branch')) ?? MenuCatalog::defaultBranch();
    }

    private function branches(Request $request)
    {
        return $request->user()->branch_id ? collect() : Branch::query()->orderBy('sort_order')->get();
    }
}
