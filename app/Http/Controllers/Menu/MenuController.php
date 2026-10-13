<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\DiningTable;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Public menu of the current restaurant (tenancy initialised from the URL). Full menu experience comes in Phase 2. */
class MenuController extends Controller
{
    public function show(Request $request): View
    {
        $table = null;
        if ($id = $request->session()->get('table.' . tenant()->getTenantKey())) {
            $table = DiningTable::query()->where('is_active', true)->find($id);
        }

        return view('menu.show', [
            'settings'   => Settings::all(),
            'categories' => Category::query()->where('is_active', true)->orderBy('sort_order')
                ->with(['items' => fn ($q) => $q->where('is_active', true)])->get(),
            'table'      => $table,
        ]);
    }

    /** QR of a table: remember the table for this restaurant, then show the menu on a clean URL. */
    public function table(Request $request, string $token): RedirectResponse
    {
        $table = DiningTable::query()->where('qr_token', $token)->where('is_active', true)->firstOrFail();
        $request->session()->put('table.' . tenant()->getTenantKey(), $table->id);
        return redirect()->route('menu.show');
    }
}
