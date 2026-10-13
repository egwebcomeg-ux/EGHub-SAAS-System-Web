<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $subs = Subscription::query()->with('plan')->get();

        return view('admin.home', [
            'counts' => [
                'tenants'   => Tenant::query()->count(),
                'trialing'  => $subs->where('status', 'trialing')->count(),
                'active'    => $subs->where('status', 'active')->count(),
                'past_due'  => $subs->where('status', 'past_due')->count(),
                'suspended' => Tenant::query()->where('status', 'suspended')->count(),
            ],
            // Monthly recurring revenue of paying restaurants (yearly cycles spread over 12 months).
            'mrr'    => $subs->where('status', 'active')->sum(fn ($s) => $s->plan->priceFor($s->cycle_months) / max(1, $s->cycle_months)),
            'latest' => Tenant::query()->with('subscription.plan')->latest()->limit(8)->get(),
        ]);
    }
}
