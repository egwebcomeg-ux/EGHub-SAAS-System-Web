<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AddonPrice;
use App\Models\Plan;
use Illuminate\View\View;

/** Marketing site: home, terms and privacy. Plans, add-ons and prices come from the database. */
class LandingController extends Controller
{
    public function home(): View
    {
        $plans = Plan::query()->where('is_public', true)->with('features')->orderBy('sort_order')->get();

        return view('landing.home', [
            'plans' => $plans,
            'addons' => AddonPrice::query()->where('is_active', true)->where('type', 'feature')->orderBy('sort_order')->get(),
            'matrix' => $plans->mapWithKeys(fn (Plan $p) => [$p->id => $p->entitlements()]),
        ]);
    }

    public function terms(): View
    {
        return view('landing.terms');
    }

    public function privacy(): View
    {
        return view('landing.privacy');
    }
}
