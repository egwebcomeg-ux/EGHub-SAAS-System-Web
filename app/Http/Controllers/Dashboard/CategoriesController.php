<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\ImageService;
use App\Services\Menu\MenuTranslations;
use App\Support\MenuLayouts;
use App\Support\PrepKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoriesController extends Controller
{
    public function store(Request $request, ImageService $images): RedirectResponse
    {
        $data = $this->validated($request);
        $data['sort_order'] = (int) Category::query()->max('sort_order') + 1;
        if ($request->hasFile('image')) {
            $data['image'] = $images->store($request->file('image'), 'category');
        }
        Category::query()->create($data);

        return back()->with('ok', __('Category added.'));
    }

    public function update(Request $request, Category $category, ImageService $images): RedirectResponse
    {
        $data = $this->validated($request);
        $data['is_active'] = $request->boolean('is_active');
        if ($request->hasFile('image')) {
            $images->delete($category->image);
            $data['image'] = $images->store($request->file('image'), 'category');
        }
        $category->update($data);
        if ($request->has('tr')) {
            MenuTranslations::save($category, $request->validate([
                'tr' => 'array',
                'tr.*.name' => 'nullable|string|max:80',
                'tr.*.description' => 'nullable|string|max:200',
            ])['tr']);
        }

        return back()->with('ok', __('Category saved.'));
    }

    public function destroy(Category $category, ImageService $images): RedirectResponse
    {
        if ($category->items()->exists()) {
            return back()->withErrors(['category' => __('Move or delete the items of this category first.')]);
        }
        $images->delete($category->image);
        $category->delete();
        MenuTranslations::forget('category', [$category->id]);

        return back()->with('ok', __('Category deleted.'));
    }

    /** Drag & drop order: {ids: [3, 1, 2]} */
    public function reorder(Request $request): JsonResponse
    {
        $ids = array_map('intval', (array) $request->input('ids', []));
        foreach ($ids as $i => $id) {
            Category::query()->whereKey($id)->update(['sort_order' => $i]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Name, texts and the optional daypart (visible_from–visible_until on chosen weekdays; both times or none).
     *
     * @return array<string,mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'name_en' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:200',
            'description_en' => 'nullable|string|max:200',
            'image' => 'nullable|file|max:5120',
            'visible_from' => 'nullable|date_format:H:i|required_with:visible_until',
            'visible_until' => 'nullable|date_format:H:i|required_with:visible_from',
            'visible_days' => 'nullable|array',
            'visible_days.*' => 'integer|between:0,6',
            'prep_kind' => ['nullable', Rule::in(array_keys(PrepKind::KINDS))],
            'menu_layout' => ['nullable', Rule::in(MenuLayouts::OVERRIDES)],
        ]);
        if (empty($data['prep_kind'])) {
            unset($data['prep_kind']); // a new category guesses it from its name (Category::booted)
        }
        // its own layout on the menu, or (empty) the menu setting; untouched when the form does not send it
        if ($request->has('menu_layout')) {
            $data['menu_layout'] = ($data['menu_layout'] ?? null) ?: null;
        }
        unset($data['image']);
        $days = array_values(array_unique(array_map('intval', $data['visible_days'] ?? [])));
        sort($days);
        $data['visible_from'] = ($data['visible_from'] ?? null) ?: null;
        $data['visible_until'] = ($data['visible_until'] ?? null) ?: null;
        $data['visible_days'] = $days !== [] && count($days) < 7 ? $days : null;

        return $data + ['name_en' => '', 'description' => '', 'description_en' => ''];
    }
}
