<?php

namespace App\Services\Import;

/**
 * Reads the menu template (or any sheet with similar headers) into a MenuDraft.
 * Columns are found by their header in Arabic or English, so owners can reorder or drop optional columns.
 */
final class ExcelMenuParser
{
    /** column => header words that identify it (lowercase) */
    public const COLUMNS = [
        'category'       => ['القسم', 'category', 'section'],
        'category_en'    => ['القسم (إنجليزي)', 'category (en)', 'category en', 'category_en'],
        'name'           => ['الصنف', 'اسم الصنف', 'item', 'name', 'item name'],
        'name_en'        => ['الصنف (إنجليزي)', 'item (en)', 'name (en)', 'name_en', 'english name'],
        'price'          => ['السعر', 'price'],
        'description'    => ['الوصف', 'description'],
        'description_en' => ['الوصف (إنجليزي)', 'description (en)', 'description_en'],
        'sizes'          => ['الأحجام', 'الاحجام', 'sizes', 'size'],
        'extras'         => ['الإضافات', 'الاضافات', 'extras', 'add-ons', 'addons'],
    ];

    /** Header row of the downloadable template, in COLUMNS order. */
    public static function templateHeader(): array
    {
        return ['القسم', 'القسم (إنجليزي)', 'الصنف', 'الصنف (إنجليزي)', 'السعر', 'الوصف', 'الوصف (إنجليزي)', 'الأحجام', 'الإضافات'];
    }

    /** @param array<int, array<int, string>> $rows first row = header */
    public static function parse(array $rows): array
    {
        if (count($rows) < 2) {
            throw new \RuntimeException(__('The file has no menu rows.'));
        }
        $map = self::map(array_shift($rows));
        if (!isset($map['name'], $map['price'])) {
            throw new \RuntimeException(__('Columns "Item" and "Price" are required. Download the template to see the layout.'));
        }

        $categories = [];
        $current = __('Menu');
        foreach ($rows as $row) {
            $get = fn (string $col) => isset($map[$col]) ? MenuDraft::clean($row[$map[$col]] ?? '') : '';
            if ($get('category') !== '') {
                $current = $get('category');
            }
            $key = mb_strtolower($current);
            $categories[$key] ??= ['name' => $current, 'name_en' => $get('category_en'), 'items' => []];
            if ($categories[$key]['name_en'] === '' && $get('category_en') !== '') {
                $categories[$key]['name_en'] = $get('category_en');
            }
            if ($get('name') === '') {
                continue;
            }
            $categories[$key]['items'][] = [
                'name' => $get('name'), 'name_en' => $get('name_en'), 'price' => $get('price'),
                'description' => $get('description'), 'description_en' => $get('description_en'),
                'sizes' => MenuDraft::parseChoices($get('sizes')), 'extras' => MenuDraft::parseChoices($get('extras')),
            ];
        }
        return MenuDraft::normalize(['categories' => array_values($categories)]);
    }

    /** @return array<string,int> column => index */
    private static function map(array $header): array
    {
        $map = [];
        foreach ($header as $i => $cell) {
            $cell = mb_strtolower(MenuDraft::clean($cell));
            // longest synonyms first so "القسم (إنجليزي)" isn't taken as "القسم"
            foreach (self::byLength() as [$col, $word]) {
                if (!isset($map[$col]) && $cell === $word) {
                    $map[$col] = $i;
                    continue 2;
                }
            }
        }
        return $map;
    }

    private static function byLength(): array
    {
        $pairs = [];
        foreach (self::COLUMNS as $col => $words) {
            foreach ($words as $w) {
                $pairs[] = [$col, mb_strtolower($w)];
            }
        }
        usort($pairs, fn ($a, $b) => mb_strlen($b[1]) <=> mb_strlen($a[1]));
        return $pairs;
    }
}
