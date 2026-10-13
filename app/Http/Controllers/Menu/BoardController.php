<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Order;
use App\Services\Entitlements;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Public order-number board for a screen in the hall: numbers only (preparing / ready), no names or items. */
class BoardController extends Controller
{
    public function show(Request $request): View
    {
        abort_unless(Entitlements::current()->allows('customer_display'), 404);
        return view('menu.board', ['settings' => Settings::all(), 'branch' => $this->branch($request)]);
    }

    public function feed(Request $request): JsonResponse
    {
        abort_unless(Entitlements::current()->allows('customer_display'), 404);
        $orders = Order::query()->where('branch_id', $this->branch($request)->id)
            ->where(fn ($q) => $q->whereIn('status', ['new', 'preparing'])
                ->orWhere(fn ($q) => $q->where('status', 'ready')->where('ready_at', '>=', now()->subMinutes(30))))
            ->orderBy('id')->get(['number', 'status']);

        return response()->json([
            'preparing' => $orders->whereIn('status', ['new', 'preparing'])->pluck('number')->values(),
            'ready' => $orders->where('status', 'ready')->pluck('number')->values(),
        ]);
    }

    private function branch(Request $request): Branch
    {
        return Branch::query()->where('is_active', true)->where('slug', (string) $request->query('branch', ''))->first()
            ?? \App\Services\Menu\MenuCatalog::defaultBranch();
    }
}
