<?php

namespace App\Support;

/**
 * Egyptian cities a restaurant can pick in Settings → "Showing up in search". The slug is the directory address
 * (eghub.app/restaurants/{slug}) and the stored setting value, so it must never change once published.
 */
final class Cities
{
    /** slug => [arabic, english] */
    public const ALL = [
        'cairo' => ['القاهرة', 'Cairo'],
        'giza' => ['الجيزة', 'Giza'],
        'alexandria' => ['الإسكندرية', 'Alexandria'],
        'new-cairo' => ['القاهرة الجديدة', 'New Cairo'],
        '6th-of-october' => ['6 أكتوبر', '6th of October'],
        'sheikh-zayed' => ['الشيخ زايد', 'Sheikh Zayed'],
        'obour' => ['العبور', 'Obour'],
        '10th-of-ramadan' => ['العاشر من رمضان', '10th of Ramadan'],
        'mansoura' => ['المنصورة', 'Mansoura'],
        'tanta' => ['طنطا', 'Tanta'],
        'zagazig' => ['الزقازيق', 'Zagazig'],
        'ismailia' => ['الإسماعيلية', 'Ismailia'],
        'port-said' => ['بورسعيد', 'Port Said'],
        'suez' => ['السويس', 'Suez'],
        'damanhur' => ['دمنهور', 'Damanhur'],
        'banha' => ['بنها', 'Banha'],
        'shebin-el-kom' => ['شبين الكوم', 'Shebin El Kom'],
        'kafr-el-sheikh' => ['كفر الشيخ', 'Kafr El Sheikh'],
        'damietta' => ['دمياط', 'Damietta'],
        'beni-suef' => ['بني سويف', 'Beni Suef'],
        'fayoum' => ['الفيوم', 'Fayoum'],
        'minya' => ['المنيا', 'Minya'],
        'assiut' => ['أسيوط', 'Assiut'],
        'sohag' => ['سوهاج', 'Sohag'],
        'qena' => ['قنا', 'Qena'],
        'luxor' => ['الأقصر', 'Luxor'],
        'aswan' => ['أسوان', 'Aswan'],
        'hurghada' => ['الغردقة', 'Hurghada'],
        'sharm-el-sheikh' => ['شرم الشيخ', 'Sharm El Sheikh'],
        'marsa-matrouh' => ['مرسى مطروح', 'Marsa Matrouh'],
    ];

    public static function exists(?string $slug): bool
    {
        return $slug !== null && isset(self::ALL[$slug]);
    }

    /** Name in the current language, "" for an unknown slug. */
    public static function name(?string $slug): string
    {
        if (! self::exists($slug)) {
            return '';
        }

        return app()->getLocale() === 'en' ? self::ALL[$slug][1] : self::ALL[$slug][0];
    }

    /** @return array<string, string> slug => name in the current language */
    public static function options(): array
    {
        return collect(self::ALL)->map(fn ($n, $slug) => self::name($slug))->all();
    }
}
