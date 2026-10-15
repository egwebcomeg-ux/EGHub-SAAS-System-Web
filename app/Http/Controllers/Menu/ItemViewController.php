<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\MenuItemView;
use Illuminate\Http\Response;

/** Beacon sent by the public menu when a guest opens an item: one more view for today (no personal data kept). */
class ItemViewController extends Controller
{
    public function store(int $item): Response
    {
        if (MenuItem::query()->whereKey($item)->where('is_active', true)->exists()) {
            MenuItemView::bump($item);
        }

        return response()->noContent();
    }
}
