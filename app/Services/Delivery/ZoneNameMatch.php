<?php

namespace App\Services\Delivery;

use App\Models\DeliveryZone;
use Illuminate\Support\Collection;

/**
 * Areas typed by hand (no drawn zones, no distance rings): the area whose name matches the street, the district or
 * the city of the guest's pin, so the list is already on it. Only a confident match counts: the same name once the
 * spelling is folded (أ/إ/ا, ة/ه, ى/ي, "ال", "شارع", "حي"…), or one name holding all the words of the other, and
 * a single area on top; otherwise the guest chooses. The server still prices the area the guest sends.
 */
final class ZoneNameMatch
{
    /** Words that say what kind of place it is, not which one. */
    private const FILLER = ['شارع', 'ش', 'حي', 'منطقه', 'مدينه', 'ميدان', 'طريق', 'street', 'st', 'road', 'rd', 'district', 'area', 'city', 'the'];

    /**
     * @param  Collection<int, DeliveryZone>  $zones  the branch's active zones
     * @param  list<?string>  $names  the pin's names, the most local first (street, area, city)
     */
    public static function best(Collection $zones, array $names): ?DeliveryZone
    {
        $wanted = collect($names)->map(fn ($n) => self::fold((string) $n))->filter(fn (string $n) => $n !== '')->unique()->values();
        if ($wanted->isEmpty()) {
            return null;
        }

        // for each name of the pin, most local first: the areas matching it, the exact ones before the partial ones
        foreach ($wanted as $name) {
            foreach ([3, 2] as $level) {
                $hits = $zones->filter(fn (DeliveryZone $z) => collect([$z->name, $z->name_en])
                    ->contains(fn ($zoneName) => self::score(self::fold((string) $zoneName), $name) >= $level))->values();
                // a street inside an area both matching: the street (the deeper one) is the answer
                if ($hits->count() === 2 && $hits->contains(fn (DeliveryZone $z) => $hits->contains('id', $z->parent_id))) {
                    $hits = $hits->filter(fn (DeliveryZone $z) => $z->parent_id !== null)->values();
                }
                if ($hits->count() === 1) {
                    return $hits->first();
                }
                if ($hits->count() > 1) {
                    return null; // two areas look the same: the guest chooses
                }
            }
        }

        return null;
    }

    /** 3: the same name, 2: one holds every word of the other (4+ letters), 0: no. */
    private static function score(string $zone, string $place): int
    {
        if ($zone === '' || $place === '') {
            return 0;
        }
        if ($zone === $place) {
            return 3;
        }
        $a = explode(' ', $zone);
        $b = explode(' ', $place);
        $shorter = count($a) <= count($b) ? $a : $b;
        $longer = $shorter === $a ? $b : $a;

        return mb_strlen(implode('', $shorter)) >= 4 && array_diff($shorter, $longer) === [] ? 2 : 0;
    }

    /** "حيّ الإستاد" → "استاد"; "Al-Bahr Street" → "bahr". */
    public static function fold(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s); // tashkeel, tatweel
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        $words = [];
        foreach (explode(' ', $s) as $word) {
            if ($word === '') {
                continue;
            }
            // "ال" / "al" in front of a name: "البحر" = "بحر", "el bahr" = "bahr"
            $word = preg_replace('/^(ال|al|el)(?=\p{L}{3})/u', '', $word) ?? $word;
            if (in_array($word, ['al', 'el'], true) || in_array($word, self::FILLER, true)) {
                continue;
            }
            $words[] = $word;
        }

        return implode(' ', $words);
    }
}
