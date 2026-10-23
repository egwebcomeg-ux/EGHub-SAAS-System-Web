<?php

namespace App\Support;

/**
 * The suggested questions of the knowledge base ("Add suggested questions"), by business type. Every answer is a
 * template: what only the owner knows is left in [brackets] to fill in, nothing is made up, and they are added as
 * drafts (inactive) so nothing reaches the team or a guest before the owner wrote the real answer.
 */
final class KnowledgeBaseTemplates
{
    /** category key => [arabic, english] */
    public const CATEGORIES = [
        'place' => ['المواعيد والمكان', 'Hours & place'],
        'ordering' => ['الطلب والتوصيل', 'Ordering & delivery'],
        'food' => ['الأكل والمشروبات', 'Food & drinks'],
        'payment' => ['الدفع', 'Payment'],
        'team' => ['للفريق', 'For the team'],
    ];

    /**
     * @return list<array{category:string, visibility:string, question:string, question_en:string, answer:string, answer_en:string, tags:string}>
     */
    public static function for(string $businessType): array
    {
        $all = self::all();
        $keys = match ($businessType) {
            'restaurant' => ['hours', 'parking', 'delivery_areas', 'delivery_time', 'booking', 'payment', 'allergens', 'kids', 'cancel', 'complaint'],
            'both' => ['hours', 'parking', 'wifi', 'delivery_areas', 'delivery_time', 'booking', 'payment', 'allergens', 'kids', 'cancel', 'complaint'],
            'shop' => ['hours', 'parking', 'delivery_areas', 'delivery_time', 'bulk', 'payment', 'allergens', 'cancel', 'complaint'],
            default => ['hours', 'wifi', 'parking', 'milk', 'delivery_areas', 'delivery_time', 'payment', 'allergens', 'cancel', 'complaint'],
        };

        return array_map(fn (string $key) => $all[$key], $keys);
    }

    /** @return array<string, array{category:string, visibility:string, question:string, question_en:string, answer:string, answer_en:string, tags:string}> */
    private static function all(): array
    {
        $q = fn (string $category, string $visibility, string $question, string $questionEn, string $answer, string $answerEn, string $tags) => [
            'category' => $category, 'visibility' => $visibility, 'question' => $question, 'question_en' => $questionEn,
            'answer' => $answer, 'answer_en' => $answerEn, 'tags' => $tags,
        ];

        return [
            'hours' => $q('place', 'public', 'مواعيد العمل إيه؟', 'What are your opening hours?',
                "بنفتح من [ساعة الفتح] لحد [ساعة القفل].\n[أيام الإجازة أو المواعيد المختلفة يوم الجمعة، لو فيه]",
                "We open from [opening time] to [closing time].\n[Days off or different Friday hours, if any]", 'مواعيد, ساعات, hours, open'),
            'parking' => $q('place', 'public', 'فيه مكان أركن فيه العربية؟', 'Is there parking?',
                '[فيه / مفيش] ركنة قريبة: [مكان الركنة، وهل فيه فاليه].',
                '[Yes / No]: [where to park, and whether there is valet parking].', 'ركنة, جراج, parking, valet'),
            'wifi' => $q('place', 'public', 'فيه واي فاي؟', 'Is there Wi-Fi?',
                '[فيه / مفيش] واي فاي. [اسم الشبكة، والباسورد بيتطلب من مين]',
                '[Yes / No]. [The network name, and who to ask for the password]', 'واي فاي, انترنت, wifi'),
            'delivery_areas' => $q('ordering', 'public', 'بتوصلوا لأنهي مناطق؟', 'Which areas do you deliver to?',
                "بنوصل لـ [المناطق].\nرسوم التوصيل [الرسوم] والحد الأدنى للطلب [الحد الأدنى].",
                "We deliver to [areas].\nThe delivery fee is [fee] and the minimum order is [minimum].", 'توصيل, مناطق, delivery, areas'),
            'delivery_time' => $q('ordering', 'public', 'الطلب بيوصل في قد إيه؟', 'How long does delivery take?',
                'في العادي من [عدد الدقايق] دقيقة، وفي وقت الزحمة ممكن يوصل [عدد الدقايق] دقيقة.',
                'Usually [minutes] minutes; at busy times up to [minutes] minutes.', 'توصيل, وقت, delivery, time'),
            'booking' => $q('ordering', 'public', 'إزاي أحجز ترابيزة؟', 'How do I book a table?',
                '[من لينك المنيو (حجز ترابيزة)، أو بالتليفون على رقم الحجز]. [أقل عدد أفراد أو عربون، لو فيه]',
                '[From the menu link (Book a table), or by phone on the booking number]. [Minimum party size or deposit, if any]', 'حجز, ترابيزة, booking, table'),
            'bulk' => $q('ordering', 'public', 'بتعملوا طلبات كبيرة أو بالحجز؟', 'Do you take large or advance orders?',
                '[أيوه / لأ]. [قبلها بكام يوم، وهل فيه عربون]',
                '[Yes / No]. [How many days ahead, and whether a deposit is needed]', 'طلبات كبيرة, مناسبات, bulk, events'),
            'payment' => $q('payment', 'public', 'بتقبلوا إيه في الدفع؟', 'Which payment methods do you take?',
                'كاش، و[كارت / إنستاباي / محفظة موبايل].',
                'Cash, and [card / InstaPay / mobile wallet].', 'دفع, كاش, فيزا, payment, card'),
            'milk' => $q('food', 'public', 'فيه بدائل للبن؟', 'Do you have milk alternatives?',
                '[فيه / مفيش]: [لبن الشوفان / اللوز / خالي اللاكتوز]. [بفرق سعر كام، لو فيه]',
                '[Yes / No]: [oat / almond / lactose-free milk]. [Any extra charge]', 'لبن, نباتي, milk, vegan'),
            'allergens' => $q('food', 'public', 'الأكل فيه مسببات حساسية؟', 'Do your dishes contain allergens?',
                "الأصناف اللي فيها [مكسرات / جلوتين / ألبان / بيض]: [الأصناف].\nلو عندك حساسية قول للفريق قبل الطلب.",
                "Items with [nuts / gluten / dairy / eggs]: [items].\nIf you have an allergy, tell the team before you order.", 'حساسية, مكسرات, جلوتين, allergy, gluten'),
            'kids' => $q('food', 'public', 'فيه منيو أطفال؟', 'Do you have a kids menu?',
                '[فيه / مفيش] منيو أطفال. [الأصناف، وهل فيه كراسي أطفال]',
                '[Yes / No]. [The dishes, and whether there are high chairs]', 'أطفال, kids, children'),
            'cancel' => $q('team', 'internal', 'العميل عايز يلغي أو يرجّع طلب، أعمل إيه؟', 'A guest wants to cancel or return an order: what do I do?',
                '[الطلب يتلغي قبل ما يبدأ تحضيره من غير رسوم. بعد كده: (السياسة)]. [المرتجع بموافقة المدير]',
                '[An order can be cancelled for free before it is prepared. After that: (the policy)]. [Refunds need a manager]', 'إلغاء, مرتجع, cancel, refund'),
            'complaint' => $q('team', 'internal', 'عميل بيشتكي من طلب، أعمل إيه؟', 'A guest complains about an order: what do I do?',
                "اعتذر واسأل عن رقم الطلب.\n[مين يتبلّغ (المدير / الشيف) وإيه اللي نقدر نعرضه: تبديل، خصم، كوبون]",
                "Apologise and ask for the order number.\n[Who to tell (manager / chef) and what we can offer: a replacement, a discount, a voucher]", 'شكوى, complaint'),
        ];
    }
}
