<?php

namespace App\Services\Import;

/**
 * The one shape every import source (spreadsheet, AI from a photo/PDF) is turned into before the owner reviews it:
 *   ['categories' => [['name', 'name_en', 'items' => [['name', 'name_en', 'description', 'description_en', 'price',
 *     'sizes' => [['name', 'price']], 'extras' => [['name', 'price']]]]]]]
 * Everything is clamped here, so neither a messy sheet nor a model's output can push oversized or odd values in.
 */
final class MenuDraft
{
    public const MAX_CATEGORIES = 40;

    public const MAX_ITEMS = 400;

    /** JSON schema given to the AI providers (structured output). */
    public static function schema(): array
    {
        $choice = ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'price' => ['type' => 'number']],
            'required' => ['name', 'price'], 'additionalProperties' => false];
        $item = ['type' => 'object', 'properties' => [
            'name' => ['type' => 'string'], 'name_en' => ['type' => 'string'],
            'description' => ['type' => 'string'], 'description_en' => ['type' => 'string'],
            'price' => ['type' => 'number'],
            'sizes' => ['type' => 'array', 'items' => $choice],
            'extras' => ['type' => 'array', 'items' => $choice],
        ], 'required' => ['name', 'name_en', 'description', 'description_en', 'price', 'sizes', 'extras'], 'additionalProperties' => false];

        return ['type' => 'object', 'properties' => ['categories' => ['type' => 'array', 'items' => [
            'type' => 'object', 'properties' => [
                'name' => ['type' => 'string'], 'name_en' => ['type' => 'string'], 'items' => ['type' => 'array', 'items' => $item],
            ], 'required' => ['name', 'name_en', 'items'], 'additionalProperties' => false,
        ]]], 'required' => ['categories'], 'additionalProperties' => false];
    }

    /** Instructions for reading a menu photo/PDF (all providers). */
    public static function prompt(): string
    {
        return <<<'TXT'
            You are reading the menu of a restaurant or cafe in Egypt from the attached photo(s) or PDF.
            Extract every section and every item exactly as printed. Rules:
            - Keep names in the language printed. Put the Arabic name in "name"; if only English is printed, put it in both "name" and "name_en".
            - Fill "name_en" with the English name (translate from Arabic when it is not printed). Same for categories.
            - "price" is a number in Egyptian pounds without currency text. If an item has several sizes with their own prices
              (e.g. S/M/L, صغير/وسط/كبير, single/double), set "price" to the smallest one and list every size in "sizes" with
              price = (its price - the smallest price), so the smallest size has 0.
            - Paid add-ons printed for an item or a section go in "extras" with their own price.
            - Leave descriptions empty unless the menu prints one. Do not invent items, prices or descriptions.
            - If a price is unreadable, use 0.
            Return only the JSON object that matches the schema.
            TXT;
    }

    /** Cleans any decoded structure into a valid draft. @return array{categories: list<array>} */
    public static function normalize(mixed $raw): array
    {
        $out = [];
        $count = 0;
        foreach (array_slice((array) ($raw['categories'] ?? []), 0, self::MAX_CATEGORIES) as $cat) {
            $name = self::text($cat['name'] ?? '', 80);
            $items = [];
            foreach ((array) ($cat['items'] ?? []) as $item) {
                if ($count >= self::MAX_ITEMS) {
                    break;
                }
                $itemName = self::text($item['name'] ?? '', 100);
                if ($itemName === '') {
                    continue;
                }
                $items[] = [
                    'name' => $itemName, 'name_en' => self::text($item['name_en'] ?? '', 100),
                    'description' => self::text($item['description'] ?? '', 500), 'description_en' => self::text($item['description_en'] ?? '', 500),
                    'price' => self::price($item['price'] ?? 0),
                    'sizes' => self::choices($item['sizes'] ?? []), 'extras' => self::choices($item['extras'] ?? []),
                ];
                $count++;
            }
            if ($items === []) {
                continue;
            }
            $out[] = ['name' => $name !== '' ? $name : __('Menu'), 'name_en' => self::text($cat['name_en'] ?? '', 80), 'items' => $items];
        }

        return ['categories' => $out];
    }

    public static function itemCount(array $draft): int
    {
        return array_sum(array_map(fn ($c) => count($c['items']), $draft['categories'] ?? []));
    }

    /** "صغير:0، كبير:15" / "S:0 | L:15" → [[name, price]] */
    public static function parseChoices(string $text): array
    {
        $out = [];
        foreach (preg_split('/\s*[|،,;\n]\s*/u', trim($text)) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            [$name, $price] = array_pad(preg_split('/\s*[:=]\s*/u', $part, 2) ?: [], 2, '0');
            $out[] = ['name' => $name, 'price' => $price];
        }

        return self::choices($out);
    }

    public static function choicesText(array $choices): string
    {
        return implode(' | ', array_map(fn ($c) => $c['name'].':'.money($c['price']), $choices));
    }

    private static function choices(mixed $list): array
    {
        $out = [];
        foreach (array_slice((array) $list, 0, 20) as $c) {
            $name = self::text(is_array($c) ? ($c['name'] ?? '') : '', 60);
            if ($name !== '') {
                $out[] = ['name' => $name, 'price' => self::price(is_array($c) ? ($c['price'] ?? 0) : 0)];
            }
        }

        return $out;
    }

    private static function text(mixed $v, int $max): string
    {
        return mb_substr(self::clean($v), 0, $max);
    }

    /** Trims and drops the invisible marks Arabic spreadsheets carry (BOM, zero-width, direction marks). */
    public static function clean(mixed $v): string
    {
        $s = preg_replace('/[\x{FEFF}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', (string) (is_scalar($v) ? $v : '')) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    /** Accepts 45, "45", "45.5", "٤٥ ج.م", "45 EGP". */
    private static function price(mixed $v): float
    {
        $s = strtr((string) (is_scalar($v) ? $v : '0'), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', ',' => '']);
        preg_match('/\d+(?:\.\d+)?/', $s, $m);

        return min(100000, max(0, round((float) ($m[0] ?? 0), 2)));
    }
}
