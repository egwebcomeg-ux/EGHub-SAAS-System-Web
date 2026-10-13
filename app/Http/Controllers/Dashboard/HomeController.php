<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Plan;
use App\Services\Entitlements;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        $rights = Entitlements::current();
        $limits = [];
        foreach (array_keys(Plan::LIMITS) as $key) {
            $limits[$key] = ['label' => Plan::label($key), 'used' => $rights->usage($key), 'limit' => $rights->limit($key)];
        }

        return view('dashboard.home', [
            'subscription' => tenant()->subscription()->with('plan')->first(),
            'limits'       => $limits,
            'stats'        => [
                'categories'   => Category::query()->count(),
                'items'        => MenuItem::query()->count(),
                'orders_today' => Order::query()->where('created_at', '>=', today())->count(),
                'customers'    => Customer::query()->count(),
            ],
        ]);
    }
}
