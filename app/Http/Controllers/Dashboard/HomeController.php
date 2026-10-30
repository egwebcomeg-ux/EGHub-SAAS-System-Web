<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Reservation;
use App\Services\Entitlements;
use App\Services\MenuSetupRequest;
use App\Services\ModuleChoice;
use App\Services\Onboarding;
use App\Support\BusinessType;
use App\Support\DashboardApps;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** The dashboard home: an apps screen (one tile per area, App\Support\DashboardApps) with the day's numbers above it. */
class HomeController extends Controller
{
    /** An order that is still being handled: the number on the Orders tile. */
    private const OPEN_STATUSES = ['new', 'preparing', 'ready', 'on_way'];

    public function index(MenuSetupRequest $menuSetup): View|RedirectResponse
    {
        $rights = Entitlements::current();
        // a delivery driver has one screen: their deliveries (the future driver app opens the same page)
        if (request()->user()->hasRole('driver') && $rights->allows('delivery_zones|online_ordering')) {
            return redirect()->route('dash.driver');
        }
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
        $user = request()->user();
        $canBuy = $user->can('billing.manage');
        // one "your next step" card: the setup steps while they last, then the suggestions for the place
        $onboarding = Onboarding::visibleTo($user) ? Onboarding::status($user) : null;
        $settingUp = $onboarding !== null && ! $onboarding['hidden'];
        $home = DashboardApps::home($user);
        $routes = array_merge(array_column($home['more'], 'route'), ...array_map(fn (array $g) => array_column($g['apps'], 'route'), $home['groups']));

        return view('dashboard.home', [
            // the sidebar reads the same list instead of working it out again
            'dashboardApps' => DashboardApps::forUser($user),
            'home' => $home,
            'badges' => $this->badges(array_filter($routes), $lowStock),
            'onboarding' => $onboarding,
            'settingUp' => $settingUp,
            'nextSteps' => $settingUp ? array_slice(array_values(array_filter($onboarding['steps'], fn (array $s) => ! $s['done'] && ! $s['skipped'])), 0, 2) : [],
            'canPickModules' => ModuleChoice::canChange($user),
            'lowStock' => $lowStock,
            'businessType' => BusinessType::current(),
            'suggestions' => $canBuy && ! $settingUp ? array_slice(BusinessType::recommendations(), 0, 4) : [],
            'menuSetupUrl' => $canBuy && $menuSetup->shouldSuggest($items) ? MenuSetupRequest::url() : null,
            'subscription' => tenant()->subscription()->with('plan')->first(),
            'limits' => $limits,
            'stats' => [
                'orders_today' => Order::query()->where('created_at', '>=', local_day_start())->count(),
                'customers' => Customer::query()->count(),
                'items' => $items,
            ],
        ]);
    }

    /**
     * The small counters on the tiles, one cheap query each and only for the tiles this user sees: open orders,
     * orders in the kitchen, bookings waiting for a confirmation, delivery orders without a driver, low ingredients.
     *
     * @param  list<string>  $routes
     * @return array<string, int> route => count (zero counts are left out)
     */
    private function badges(array $routes, int $lowStock): array
    {
        $counts = [];
        foreach ($routes as $route) {
            $n = match ($route) {
                'dash.orders' => Order::query()->whereIn('status', self::OPEN_STATUSES)->count(),
                'dash.kitchen' => Order::query()->whereIn('status', ['new', 'preparing'])->count(),
                'dash.reservations' => Reservation::query()->where('status', 'pending')->count(),
                'dash.drivers' => Order::query()->where('type', 'delivery')->whereIn('status', self::OPEN_STATUSES)->whereNull('driver_id')->count(),
                'dash.inventory' => $lowStock,
                default => 0,
            };
            if ($n > 0) {
                $counts[$route] = $n;
            }
        }

        return $counts;
    }
}
