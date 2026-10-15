<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchItem;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Per-branch menu (Business): a different price, "not sold here" or "sold out here" for each item.
 * An empty price means "same as the main menu"; rows equal to the main menu are deleted, so nothing drifts.
 */
class BranchMenuController extends Controller
{
    public function edit(Branch $branch): View
    {
        return view('dashboard.branch-menu', [
            'branch' => $branch,
            'categories' => Category::query()->orderBy('sort_order')->with(['items' => fn ($q) => $q->orderBy('sort_order')])->get(),
            'overrides' => BranchItem::query()->where('branch_id', $branch->id)->get()->keyBy('menu_item_id'),
        ]);
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $request->validate([
            'items' => 'array',
            'items.*.price' => 'nullable|numeric|min:0|max:100000',
        ]);
        $validIds = Category::query()->with('items:id,category_id')->get()->flatMap->items->pluck('id')->all();

        DB::transaction(function () use ($request, $branch, $validIds) {
            // every posted row, not only validated keys: a row without a price still carries its checkboxes
            foreach ((array) $request->input('items', []) as $itemId => $row) {
                $row = (array) $row;
                $itemId = (int) $itemId;
                if (! in_array($itemId, $validIds, true)) {
                    continue; // another restaurant's id, or a deleted item
                }
                $price = isset($row['price']) && $row['price'] !== '' ? (float) $row['price'] : null;
                $available = $request->boolean("items.$itemId.available");
                $soldOut = $request->boolean("items.$itemId.sold_out");
                if ($price === null && $available && ! $soldOut) {
                    BranchItem::query()->where('branch_id', $branch->id)->where('menu_item_id', $itemId)->delete();

                    continue;
                }
                BranchItem::query()->updateOrCreate(
                    ['branch_id' => $branch->id, 'menu_item_id' => $itemId],
                    ['price' => $price, 'is_available' => $available, 'is_sold_out' => $soldOut],
                );
            }
        });

        return back()->with('ok', __('Branch menu saved.'));
    }
}
