<?php

namespace App\Support;

/**
 * The "which box?" picker a blog article embeds with `[[box-picker]]` (App\Support\PostBlocks): the reader picks the
 * food, how hot it travels and how wet it is, and gets a packaging type with the reason. Every answer is worked out
 * here; the page carries the whole table as data, so landing.js only looks it up, and without JavaScript the page
 * shows the usual answer for each food.
 *
 * Materials are named by type (PP, PET, kraft, aluminium, bagasse), never by supplier or brand.
 */
final class PackagingPicker
{
    /** @return array<string, string> */
    public static function foods(): array
    {
        return [
            'mains' => 'أرز وطواجن وأطباق رئيسية',
            'grill' => 'مشويات وفراخ',
            'fried' => 'مقليات وبطاطس وكريسبي',
            'sandwich' => 'ساندوتشات وبرجر',
            'pizza' => 'بيتزا وفطير',
            'pasta' => 'مكرونة',
            'soup' => 'شوربة',
            'salad' => 'سلطات',
            'eastern' => 'حلويات شرقية بالشربات',
            'cake' => 'تورت وجاتوه',
            'drink' => 'مشروبات',
        ];
    }

    /** @return array<string, string> */
    public static function temperatures(): array
    {
        return ['hot' => 'سخن', 'room' => 'عادي (حرارة الجو)', 'cold' => 'ساقع أو مبرّد'];
    }

    /** @return array<string, string> */
    public static function textures(): array
    {
        return ['dry' => 'ناشف', 'sauce' => 'فيه صوص', 'liquid' => 'سايل'];
    }

    /** The usual way each food travels: the answer shown for it without JavaScript. @return array<string, array{0:string, 1:string}> */
    public static function typical(): array
    {
        return [
            'mains' => ['hot', 'sauce'], 'grill' => ['hot', 'dry'], 'fried' => ['hot', 'dry'], 'sandwich' => ['hot', 'dry'],
            'pizza' => ['hot', 'dry'], 'pasta' => ['hot', 'sauce'], 'soup' => ['hot', 'liquid'], 'salad' => ['cold', 'sauce'],
            'eastern' => ['room', 'sauce'], 'cake' => ['cold', 'dry'], 'drink' => ['cold', 'liquid'],
        ];
    }

    /**
     * The packaging for one choice; an unknown choice falls back to the first food, hot and dry.
     *
     * @return array{box:string, reason:string, tip:string}
     */
    public static function recommend(string $food, string $temperature, string $texture): array
    {
        $food = array_key_exists($food, self::foods()) ? $food : 'mains';
        $temperature = array_key_exists($temperature, self::temperatures()) ? $temperature : 'hot';
        $texture = array_key_exists($texture, self::textures()) ? $texture : 'dry';
        $hot = $temperature === 'hot';

        if ($food === 'drink') {
            return $hot
                ? self::answer('كوباية ورق بحيطة دبل وغطا محكم، وحامل أكواب لو الطلب فيه أكتر من مشروب', 'الحيطة الدبل بتحمي إيد العميل وبتحافظ على السخونية، والحامل بيمنع الكوباية تميل في الشنطة.', 'متملاش الكوباية للآخر، وحط استيكر على فتحة الشرب في الغطا.')
                : self::answer('كوباية بلاستيك شفافة بغطا قبة أو غطا مسطح، وحامل أكواب', 'الشفاف بيبيّن المشروب، والغطا المحكم مع الحامل بيقلّلوا الدلق.', 'الشفاط يتحط جنب الكوباية في كيس، مش مغروز في الغطا، ولو عندك ماكينة لحام أغطية تبقى أضمن.');
        }
        if ($food === 'soup' || $texture === 'liquid') {
            return self::answer(
                $hot ? 'علبة PP مدورة بغطا بيقفل بضغط محكم (أو غطا قلاووظ)' : 'علبة PP أو PET مدورة بغطا محكم',
                'السوايل بتسرّب من الأركان، والعلبة المدورة مفيهاش ركن.'.($hot ? ' والـ PP بيستحمل السخونية.' : ''),
                'اسأل المورّد إن العلبة مناسبة للأكل'.($hot ? ' السخن' : '').'، متملاهاش للآخر، حط استيكر على حرف الغطا، وخليها واقفة في الشنطة.',
            );
        }

        return match ($food) {
            'cake' => self::answer('كرتونة تورتة بقاعدة كرتون مقوّى، أعلى من التورتة بكام سنتي', 'القاعدة بتشيل التورتة وقت النقل من غير ما تتني، والارتفاع بيحمي التزيين والكتابة.', 'متتحطش فوقها حاجة، ولو فيها كريمة تتنقل مبرّدة ومن غير لفّة طويلة.'),
            'eastern' => self::answer('صينية ألومنيوم بغطا، أو علبة PP مستطيلة', 'الألومنيوم بيستحمل السمنة والسخونية وشكله مألوف في الحلويات الشرقية.', $texture === 'sauce' ? 'لو المسافة طويلة، الشربات في علبة صغيرة لوحده عشان القرمشة تفضل.' : 'رص القطع صف واحد، ومتحطش ورق فوقها يلزق في الوش.'),
            'pizza' => self::answer('علبة بيتزا كرتون مضلّع فيها فتحات تهوية', 'الكرتون المضلّع بيعزل السخونية، والفتحات بتطلّع البخار عشان العجينة ما تطرّاش.', 'متحطش أكتر من علبتين أو تلاتة فوق بعض في الشنطة.'),
            'fried' => self::answer('علبة أو كيس ورق كرافت مبطّن ضد الدهون، فيه فتحة تهوية', 'البخار هو اللي بيطرّي المقلي. لو قفلته في علبة بلاستيك محكمة هيوصل طري.', 'الصوص في علبة صغيرة لوحده، ومتحطش المقلي تحت أكل سخن فيه صوص.'),
            'sandwich' => self::answer('علبة كرافت أو كرتون بغطا مفصلي، وورقة تغليف مبطّنة حوالين الساندوتش', 'الورقة بتمسك الساندوتش متجمّع، والعلبة بتحميه من الضغط في الشنطة.', $texture === 'sauce' ? 'الصوص الزيادة في علبة صغيرة لوحده عشان العيش ما يشربش.' : 'لو فيه بطاطس معاه، تتحط في علبة لوحدها مش جنبه.'),
            'salad' => self::answer('علبة PET شفافة بغطا محكم', 'الشفاف بيبيّن الطزاجة.', 'التتبيلة في علبة صغيرة لوحدها عشان الخضار يفضل مقرمش.'),
            default => match (true) {
                $hot && $texture === 'dry' => self::answer('علبة ألومنيوم بغطا كرتون، أو علبة من البجاس (ألياف القصب)', 'الاتنين بيستحملوا السخونية وشكلهم كويس، والبجاس أخف وأقرب للطبيعة.', 'سيب الأكل يهدى دقيقة قبل ما تقفل، عشان البخار ما يبلّش الغطا.'),
                $hot => self::answer('علبة PP بغطا محكم، بتقسيمة لو فيه أكتر من صنف', 'الـ PP بيستحمل السخونية والصوص من غير ما يسرّب، والتقسيمة بتمنع الصوص يغرّق الأرز.', 'لو هتكتب على العلبة إنها تدخل الميكروويف، اتأكد إن المورّد كاتب كده عليها.'),
                default => self::answer('علبة PP أو PET بغطا محكم', 'خفيفة، بتتقفل كويس، وبتترص فوق بعض في الشنطة والتلاجة.', 'اكتب اسم الصنف على الاستيكر عشان العميل يلاقي اللي عايزه من غير ما يفتح كل العلب.'),
            },
        };
    }

    /** Every answer, keyed food|temperature|texture, for the page script. @return array<string, array{box:string, reason:string, tip:string}> */
    public static function matrix(): array
    {
        $matrix = [];
        foreach (array_keys(self::foods()) as $food) {
            foreach (array_keys(self::temperatures()) as $temperature) {
                foreach (array_keys(self::textures()) as $texture) {
                    $matrix["$food|$temperature|$texture"] = self::recommend($food, $temperature, $texture);
                }
            }
        }

        return $matrix;
    }

    /** @return array{box:string, reason:string, tip:string} */
    private static function answer(string $box, string $reason, string $tip): array
    {
        return compact('box', 'reason', 'tip');
    }
}
