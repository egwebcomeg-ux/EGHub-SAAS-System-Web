<?php

namespace App\Services\Import;

use App\Models\Category;
use App\Models\MenuImport;
use App\Models\MenuItem;
use App\Services\Entitlements;
use Illuminate\Support\Facades\DB;

/**
 * Applies a reviewed draft to the menu.
 *   add:    every row becomes a new item (categories are matched by name, created when missing)
 *   update: rows whose name already exists in that category update price/description/options; the rest are added
 * The plan's item limit counts only the items that will be created.
 */
final class MenuImporter
{
    /** @return array{created:int, updated:int} */
    public function apply(MenuImport $import, array $draft, string $mode): array
    {
        $draft = MenuDraft::normalize($draft);
        $categories = Category::query()->get()->keyBy(fn ($c) => mb_strtolower($c->name));
        $existing = MenuItem::query()->get()->groupBy('category_id');

        $toCreate = 0;
        foreach ($draft['categories'] as $cat) {
            $catId = $categories[mb_strtolower($cat['name'])]->id ?? null;
            foreach ($cat['items'] as $item) {
                $found = $mode === 'update' && $catId ? $this->find($existing[$catId] ?? collect(), $item['name']) : null;
                $toCreate += $found ? 0 : 1;
            }
        }
        $rights = Entitlements::current();
        if (! $rights->canAdd('menu_items', $toCreate)) {
            throw new \RuntimeException($rights->upgradeMessage('menu_items'));
        }

        return DB::transaction(function () use ($import, $draft, $mode, $categories, $existing) {
            $created = $updated = 0;
            $order = (int) Category::query()->max('sort_order');
            foreach ($draft['categories'] as $cat) {
                $category = $categories[mb_strtolower($cat['name'])] ?? null;
                if (! $category) {
                    $category = Category::query()->create(['name' => $cat['name'], 'name_en' => $cat['name_en'], 'sort_order' => ++$order]);
                    $categories[mb_strtolower($cat['name'])] = $category;
                }
                $itemOrder = (int) MenuItem::query()->where('category_id', $category->id)->max('sort_order');
                foreach ($cat['items'] as $row) {
                    $item = $mode === 'update' ? $this->find($existing[$category->id] ?? collect(), $row['name']) : null;
                    $values = [
                        'name_en' => $row['name_en'] ?: ($item->name_en ?? ''), 'price' => $row['price'],
                        'description' => $row['description'] ?: ($item->description ?? null),
                        'description_en' => $row['description_en'] ?: ($item->description_en ?? null),
                    ];
                    if ($item) {
                        $item->update($values);
                        $updated++;
                    } else {
                        $item = MenuItem::query()->create($values + ['category_id' => $category->id, 'name' => $row['name'], 'sort_order' => ++$itemOrder]);
                        $created++;
                    }
                    if ($row['sizes'] || $row['extras'] || ! $item->wasRecentlyCreated) {
                        $this->options($item, $row);
                    }
                }
            }
            $import->update(['status' => 'applied']);

            return ['created' => $created, 'updated' => $updated];
        });
    }

    private function find($items, string $name): ?MenuItem
    {
        $needle = mb_strtolower(trim($name));

        return $items->first(fn (MenuItem $i) => mb_strtolower(trim($i->name)) === $needle);
    }

    /** Sizes → required single choice; extras → optional multiple choice. Replaces the item's groups. */
    private function options(MenuItem $item, array $row): void
    {
        if (! $row['sizes'] && ! $row['extras']) {
            return; // keep options the owner made by hand
        }
        $item->optionGroups()->delete();
        foreach ([['sizes', 'الحجم', 'Size', 'single', true], ['extras', 'إضافات', 'Extras', 'multiple', false]] as $i => [$key, $ar, $en, $type, $required]) {
            if (! $row[$key]) {
                continue;
            }
            $group = $item->optionGroups()->create(['name' => $ar, 'name_en' => $en, 'type' => $type, 'is_required' => $required, 'sort_order' => $i]);
            foreach ($row[$key] as $j => $choice) {
                $group->options()->create(['name' => $choice['name'], 'price' => $choice['price'], 'sort_order' => $j, 'is_default' => $type === 'single' && $j === 0]);
            }
        }
    }
}
