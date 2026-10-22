<?php

namespace App\Services\Import;

use App\Models\Category;
use App\Models\MenuItem;

/**
 * The current restaurant's whole menu as sheet rows in the layout the Excel import reads (categories, items, prices,
 * descriptions, sizes, extras, allergens), so what is exported can be edited and imported back. Shared by the menu
 * export (MenuExportController) and the restaurant backup (App\Services\Backups\TenantBackupBuilder).
 */
final class MenuSheet
{
    /**
     * Header + one row per item, in ExcelMenuParser::templateHeader() order with the allergens column at the end.
     *
     * @return array<int, array<int, mixed>>
     */
    public static function rows(): array
    {
        $rows = [ExcelMenuParser::templateHeader()];
        $categories = Category::query()->orderBy('sort_order')->orderBy('id')
            ->with(['items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')->with('optionGroups.options')])->get();
        foreach ($categories as $category) {
            foreach ($category->items as $item) {
                $rows[] = [
                    $category->name, $category->name_en, $item->name, $item->name_en, (float) $item->price,
                    (string) $item->description, (string) $item->description_en,
                    MenuDraft::choicesText(self::choices($item, 'single')), MenuDraft::choicesText(self::choices($item, 'multiple')),
                    implode(' | ', array_column($item->allergenList(), 'key')),
                ];
            }
        }

        return $rows;
    }

    /** Sizes = the options of the single-choice groups; extras = the multiple-choice ones. @return list<array{name:string, price:float}> */
    private static function choices(MenuItem $item, string $type): array
    {
        $out = [];
        foreach ($item->optionGroups->where('type', $type) as $group) {
            foreach ($group->options as $option) {
                $out[] = ['name' => $option->name, 'price' => (float) $option->price];
            }
        }

        return $out;
    }
}
