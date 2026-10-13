<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\View\View;

/** Marketing site. A temporary page until the real landing (Phase 5); plans and prices come from the database. */
class LandingController extends Controller
{
    public function home(): View
    {
        $plans = Plan::query()->where('is_public', true)->with('features')->orderBy('sort_order')->get();
        return view('landing.home', [
            'plans'  => $plans,
            'addons' => \App\Models\AddonPrice::query()->where('is_active', true)->where('type', 'feature')->orderBy('sort_order')->get(),
            'matrix' => $plans->mapWithKeys(fn (Plan $p) => [$p->id => $p->entitlements()]),
        ]);
    }
}
