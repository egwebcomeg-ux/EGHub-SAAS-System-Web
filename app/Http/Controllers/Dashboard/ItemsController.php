<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MenuItem;
use App\Services\Entitlements;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Menu editor: categories with their items, sizes/extras (option groups), images, sold out / hidden. */
class ItemsController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $categories = Category::query()->orderBy('sort_order')->orderBy('id')
            ->with(['items' => fn ($w) => $w->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('name_en', 'like', "%$q%")))
                ->orderBy('sort_order')->orderBy('id')])
            ->get();

        $rights = Entitlements::current();
        return view('dashboard.menu.index', [
            'categories' => $categories, 'q' => $q,
            'canAdd' => $rights->canAdd('menu_items'),
            'usage' => ['used' => $rights->usage('menu_items'), 'limit' => $rights->limit('menu_items')],
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $rights = Entitlements::current();
        if (!$rights->canAdd('menu_items')) {
            return redirect()->route('dash.menu')->withErrors(['limit' => $rights->upgradeMessage('menu_items')]);
        }
        return view('dashboard.menu.item', [
            'item' => new MenuItem(['category_id' => (int) $request->query('category'), 'is_active' => true]),
            'categories' => Category::query()->orderBy('sort_order')->get(),
            'upsell' => Entitlements::current()->allows('upsell'),
            'allItems' => MenuItem::query()->orderBy('name')->get(['id', 'name', 'price']),
        ]);
    }

    public function edit(MenuItem $item): View
    {
        return view('dashboard.menu.item', [
            'item' => $item->load('optionGroups.options', 'pairings', 'comboParts'),
            'categories' => Category::query()->orderBy('sort_order')->get(),
            'upsell' => Entitlements::current()->allows('upsell'),
            'allItems' => MenuItem::query()->whereKeyNot($item->id)->orderBy('name')->get(['id', 'name', 'price']),
        ]);
    }

    public function store(Request $request, ImageService $images): RedirectResponse
    {
        $rights = Entitlements::current();
        if (!$rights->canAdd('menu_items')) {
            return redirect()->route('dash.menu')->withErrors(['limit' => $rights->upgradeMessage('menu_items')]);
        }
        $item = new MenuItem(['sort_order' => (int) MenuItem::query()->max('sort_order') + 1]);
        $this->save($request, $item, $images);
        return redirect()->route('dash.menu')->with('ok', __('Item added.'));
    }

    public function update(Request $request, MenuItem $item, ImageService $images): RedirectResponse
    {
        $this->save($request, $item, $images);
        return redirect()->route('dash.menu')->with('ok', __('Item saved.'));
    }

    public function destroy(MenuItem $item, ImageService $images): RedirectResponse
    {
        $images->delete($item->image);
        $item->delete();
        return redirect()->route('dash.menu')->with('ok', __('Item deleted.'));
    }

    /** Quick toggles from the menu list (also allowed with menu.availability only): sold_out | active. */
    public function toggle(Request $request, MenuItem $item): JsonResponse
    {
        $field = $request->input('field') === 'active' ? 'is_active' : 'is_sold_out';
        if ($field === 'is_active' && !$request->user()->can('menu.manage')) {
            abort(403);
        }
        $item->update([$field => !$item->{$field}]);
        return response()->json(['ok' => true, 'value' => $item->{$field}]);
    }

    /** {ids: [...]} in the new order, inside one category. */
    public function reorder(Request $request): JsonResponse
    {
        foreach (array_map('intval', (array) $request->input('ids', [])) as $i => $id) {
            MenuItem::query()->whereKey($id)->update(['sort_order' => $i]);
        }
        return response()->json(['ok' => true]);
    }

    private function save(Request $request, MenuItem $item, ImageService $images): void
    {
        $tenant = tenant()->getTenantKey();
        $data = $request->validate([
            'category_id'    => ['required', Rule::exists('categories', 'id')->where('tenant_id', $tenant)],
            'name'           => 'required|string|max:100',
            'name_en'        => 'nullable|string|max:100',
            'description'    => 'nullable|string|max:500',
            'description_en' => 'nullable|string|max:500',
            'price'          => 'required|numeric|min:0|max:100000',
            'calories'       => 'nullable|integer|min:0|max:10000',
            'image'          => 'nullable|file|max:5120',
            'groups'                     => 'array|max:10',
            'groups.*.name'              => 'required|string|max:60',
            'groups.*.name_en'           => 'nullable|string|max:60',
            'groups.*.type'              => ['required', Rule::in(['single', 'multiple'])],
            'groups.*.max_choices'       => 'nullable|integer|min:1|max:20',
            'groups.*.options'           => 'required|array|min:1|max:30',
            'groups.*.options.*.name'    => 'required|string|max:60',
            'groups.*.options.*.name_en' => 'nullable|string|max:60',
            'groups.*.options.*.price'   => 'nullable|numeric|min:0|max:100000',
            'pairings'                   => 'array|max:6',
            'pairings.*'                 => [Rule::exists('menu_items', 'id')->where('tenant_id', $tenant)],
            'combo'                      => 'array|max:10',
            'combo.*.item_id'            => ['nullable', Rule::exists('menu_items', 'id')->where('tenant_id', $tenant)],
            'combo.*.qty'                => 'nullable|integer|min:1|max:10',
        ]);

        DB::transaction(function () use ($request, $item, $images, $data) {
            $item->fill([
                'category_id' => $data['category_id'], 'name' => $data['name'], 'name_en' => $data['name_en'] ?? '',
                'description' => $data['description'] ?? null, 'description_en' => $data['description_en'] ?? null,
                'price' => $data['price'], 'calories' => $data['calories'] ?? null,
                'is_active' => $request->boolean('is_active'), 'is_sold_out' => $request->boolean('is_sold_out'),
                'is_featured' => $request->boolean('is_featured'),
            ]);
            if ($request->hasFile('image')) {
                $images->delete($item->image);
                $item->image = $images->store($request->file('image'), 'item');
            } elseif ($request->boolean('remove_image')) {
                $images->delete($item->image);
                $item->image = null;
            }
            $item->save();

            // Pivot rows carry tenant_id like every restaurant row (BelongsToTenant can't fill pivots).
            if (Entitlements::current()->allows('upsell')) {
                $pivot = ['tenant_id' => tenant()->getTenantKey()];
                $item->pairings()->sync(collect($data['pairings'] ?? [])->map('intval')->reject(fn ($id) => $id === $item->id)
                    ->mapWithKeys(fn ($id) => [$id => $pivot])->all());
                $item->comboParts()->sync(collect($data['combo'] ?? [])->filter(fn ($c) => !empty($c['item_id']) && (int) $c['item_id'] !== $item->id)
                    ->mapWithKeys(fn ($c) => [(int) $c['item_id'] => $pivot + ['quantity' => (int) ($c['qty'] ?? 1)]])->all());
            }

            // Option groups are replaced as a whole; past orders keep their own snapshot of names and prices.
            $item->optionGroups()->delete();
            foreach (array_values($data['groups'] ?? []) as $gi => $g) {
                $group = $item->optionGroups()->create([
                    'name' => $g['name'], 'name_en' => $g['name_en'] ?? '', 'type' => $g['type'],
                    'is_required' => !empty($request->input("groups.$gi.is_required")) || false,
                    'max_choices' => $g['type'] === 'multiple' ? ($g['max_choices'] ?? null) : null, 'sort_order' => $gi,
                ]);
                foreach (array_values($g['options']) as $oi => $o) {
                    $group->options()->create([
                        'name' => $o['name'], 'name_en' => $o['name_en'] ?? '', 'price' => $o['price'] ?? 0, 'sort_order' => $oi,
                        'is_default' => $oi === 0 && $g['type'] === 'single',
                    ]);
                }
            }
        });
    }
}
