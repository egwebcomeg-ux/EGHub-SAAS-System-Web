<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Entitlements;
use App\Services\Menu\MenuCatalog;
use App\Services\Screens\ScreenPlaylist;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public order-number board for a screen in the hall: numbers only (preparing / ready), no names or items. The same
 * numbers as the TV "ready numbers" slide (ScreenPlaylist::numbers), called pager tickets included.
 */
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

        return response()->json(ScreenPlaylist::numbers($this->branch($request)));
    }

    private function branch(Request $request): Branch
    {
        return Branch::query()->where('is_active', true)->where('slug', (string) $request->query('branch', ''))->first()
            ?? MenuCatalog::defaultBranch();
    }
}
