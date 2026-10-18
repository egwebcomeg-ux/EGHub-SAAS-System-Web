<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Customer;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Plan;
use App\Services\Entitlements;
use App\Services\MenuSetupRequest;
use App\Services\ModuleChoice;
use App\Services\Onboarding;
use App\Support\BusinessType;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(MenuSetupRequest $menuSetup): View
    {
        $rights = Entitlements::current();
        $limits = [];
        foreach (array_keys(Plan::LIMITS) as $key) {
            $limits[$key] = ['label' => Plan::label($key), 'used' => $rights->usage($key), 'limit' => $rights->limit($key)];
        }

        // ingredients at or under their alert level in any branch (inventory pack)
        $lowStock = $rights->allows('inventory') && request()->user()->can('inventory.manage')
            ? IngredientStock::query()->join('ingredients', 'ingredients.id', '=', 'ingredient_stocks.ingredient_id')
                ->where('ingredients.is_active', true)->where('ingredients.min_stock', '>', 0)
                ->whereColumn('ingredient_stocks.quantity', '<=', 'ingredients.min_stock')
                ->distinct()->count('ingredient_stocks.ingredient_id')
            : 0;

        $items = MenuItem::query()->count();
        $canBuy = request()->user()->can('billing.manage');

        return view('dashboard.home', [
            'onboarding' => Onboarding::visibleTo(request()->user()) ? Onboarding::status(request()->user()) : null,
            'moduleShortcuts' => ModuleChoice::hasChoice() ? ModuleChoice::shortcuts(request()->user()) : null,
            'canPickModules' => ModuleChoice::canChange(request()->user()),
            'lowStock' => $lowStock,
            'businessType' => BusinessType::current(),
            'suggestions' => $canBuy ? array_slice(BusinessType::recommendations(), 0, 4) : [],
            'menuSetupUrl' => $canBuy && $menuSetup->shouldSuggest($items) ? MenuSetupRequest::url() : null,
            'subscription' => tenant()->subscription()->with('plan')->first(),
            'limits' => $limits,
            'stats' => [
                'categories' => Category::query()->count(),
                'items' => $items,
                'orders_today' => Order::query()->where('created_at', '>=', today())->count(),
                'customers' => Customer::query()->count(),
            ],
        ]);
    }
}
