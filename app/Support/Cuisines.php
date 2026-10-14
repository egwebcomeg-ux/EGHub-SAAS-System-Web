<?php

namespace App\Support;

/**
 * What a place serves, picked in Settings (several allowed, stored comma-separated in the `cuisine` setting).
 * The key is the directory address (egmenu.app/restaurants/{city}/{cuisine}); `schema` is the servesCuisine value.
 */
final class Cuisines
{
    /** key => [arabic, english] */
    public const ALL = [
        'coffee' => ['قهوة', 'Coffee'],
        'desserts' => ['حلويات', 'Desserts'],
        'bakery' => ['مخبوزات', 'Bakery'],
        'egyptian' => ['أكل مصري', 'Egyptian'],
        'grills' => ['مشويات', 'Grills'],
        'seafood' => ['أسماك ومأكولات بحرية', 'Seafood'],
        'pizza' => ['بيتزا', 'Pizza'],
        'burgers' => ['برجر', 'Burgers'],
        'shawarma' => ['شاورما', 'Shawarma'],
        'koshary' => ['كشري', 'Koshary'],
        'oriental' => ['أكل شرقي', 'Oriental'],
        'italian' => ['إيطالي', 'Italian'],
        'asian' => ['آسيوي', 'Asian'],
        'healthy' => ['أكل صحي', 'Healthy'],
        'breakfast' => ['فطار', 'Breakfast'],
        'juices' => ['عصاير', 'Juices'],
    ];

    public static function exists(?string $key): bool
    {
        return $key !== null && isset(self::ALL[$key]);
    }

    public static function name(string $key): string
    {
        if (! self::exists($key)) {
            return '';
        }

        return app()->getLocale() === 'en' ? self::ALL[$key][1] : self::ALL[$key][0];
    }

    /** "coffee,desserts" (the stored setting) → ['coffee', 'desserts'], unknown keys dropped. */
    public static function parse(?string $stored): array
    {
        return array_values(array_filter(explode(',', (string) $stored), fn ($k) => self::exists($k)));
    }

    /** English names for schema.org servesCuisine. @return list<string> */
    public static function schemaNames(?string $stored): array
    {
        return array_map(fn ($k) => self::ALL[$k][1], self::parse($stored));
    }
}
