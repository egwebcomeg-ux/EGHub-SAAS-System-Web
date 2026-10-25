<?php

namespace App\Support;

/**
 * The calculators a blog article can embed with a line `[[calculator name]]` (App\Support\PostBlocks). Only the names
 * listed here exist; anything else stays plain text. The page shows the formula and a worked example with the default
 * numbers (computed here, so it reads right without JavaScript); resources/js/landing.js recomputes live as the reader
 * types, with the same formulas.
 *
 * Every default is an example the reader replaces with their own numbers, never a market price.
 */
final class BlogCalculators
{
    /**
     * @return array<string, array{title:string, intro:string, formula:string, inputs:array<string, array{label:string, default:float, step:float, suffix:string}>, outputs:array<string, array{label:string, suffix:string, decimals:int}>}>
     */
    public static function all(): array
    {
        $pound = 'جنيه';

        return [
            'packaging' => [
                'title' => 'حاسبة تكلفة التغليف للطلب',
                'intro' => 'اكتب سعر كل حاجة بتخرج مع الطلب، والحاسبة تقولك تكلفتها ونسبتها من سعر الصنف.',
                'formula' => 'تكلفة التغليف = العلبة + علب الصوص الصغيرة + الكيس + الاستيكر + المناديل والمعالق. النسبة = تكلفة التغليف ÷ سعر الصنف × 100. تكلفة الشهر = تكلفة التغليف × عدد الطلبات في الشهر.',
                'inputs' => [
                    'box' => self::input('العلبة الأساسية', 6, 0.25, $pound),
                    'sides' => self::input('علب الصوص والسلطة الصغيرة', 2, 0.25, $pound),
                    'bag' => self::input('الكيس أو الشنطة', 2.5, 0.25, $pound),
                    'seal' => self::input('استيكر القفل', 0.75, 0.25, $pound),
                    'extras' => self::input('المناديل والمعالق', 1.5, 0.25, $pound),
                    'price' => self::input('سعر الصنف في المنيو', 150, 5, $pound),
                    'orders' => self::input('عدد طلبات التيك أواي والدليفري في الشهر', 300, 10, 'طلب'),
                ],
                'outputs' => [
                    'total' => self::output('تكلفة التغليف للطلب', $pound, 2),
                    'share' => self::output('نسبتها من سعر الصنف', '٪', 1),
                    'priced' => self::output('سعر الصنف لو حمّلتها عليه (مقرّب لأقرب 5)', $pound, 0),
                    'monthly' => self::output('تكلفة التغليف في الشهر', $pound, 0),
                ],
            ],
            'food-cost' => [
                'title' => 'حاسبة السعر من تكلفة الخامات',
                'intro' => 'اكتب تكلفة خامات الصنف والنسبة اللي إنت مستهدفها، والحاسبة تطلع سعر مقترح.',
                'formula' => 'السعر المقترح = تكلفة الخامات ÷ (النسبة المستهدفة ÷ 100)، وبعدين بنقرّبه لفوق لأقرب 5 جنيه. اللي يفضل = السعر بعد التقريب − الخامات − التغليف.',
                'inputs' => [
                    'cost' => self::input('تكلفة خامات الصنف', 45, 0.5, $pound),
                    'target' => self::input('نسبة تكلفة الخامات المستهدفة', 30, 1, '٪'),
                    'packaging' => self::input('تكلفة التغليف لو بيتباع تيك أواي', 0, 0.25, $pound),
                ],
                'outputs' => [
                    'price' => self::output('السعر المقترح', $pound, 2),
                    'rounded' => self::output('بعد التقريب لأقرب 5', $pound, 0),
                    'actual' => self::output('نسبة الخامات الفعلية بعد التقريب', '٪', 1),
                    'left' => self::output('اللي يفضل بعد الخامات والتغليف', $pound, 2),
                ],
            ],
            'cake-price' => [
                'title' => 'حاسبة سعر التورتة حسب الوزن',
                'intro' => 'اكتب تكلفة الكيلو والتزيين والعلبة، والحاسبة تطلع سعر التورتة كلها وسعر الكيلو.',
                'formula' => 'التكلفة = (تكلفة خامات الكيلو × الوزن) + التزيين الخاص + العلبة والقاعدة. السعر = التكلفة ÷ (النسبة المستهدفة ÷ 100)، مقرّب لفوق لأقرب 10 جنيه.',
                'inputs' => [
                    'kilo' => self::input('تكلفة خامات الكيلو', 220, 5, $pound),
                    'weight' => self::input('وزن التورتة', 2, 0.25, 'كيلو'),
                    'decor' => self::input('تزيين خاص (طباعة، فيجرز، كتابة)', 60, 5, $pound),
                    'box' => self::input('العلبة والقاعدة', 35, 1, $pound),
                    'target' => self::input('نسبة التكلفة المستهدفة', 35, 1, '٪'),
                ],
                'outputs' => [
                    'cost' => self::output('تكلفة التورتة', $pound, 2),
                    'price' => self::output('السعر المقترح (مقرّب لأقرب 10)', $pound, 0),
                    'per_kilo' => self::output('يعني الكيلو بكام', $pound, 0),
                ],
            ],
            'season-stock' => [
                'title' => 'حاسبة كمية الموسم',
                'intro' => 'من مبيعات نفس الموسم السنة اللي فاتت، اعرف تحضّر قد إيه. استعمل نفس الوحدة في كل الخانات: علبة، كيلو، أو صينية.',
                'formula' => 'المتوقع = مبيعات الموسم اللي فات × (1 + التغيير المتوقع ÷ 100). المطلوب تحضيره = المتوقع × (1 + هامش الأمان ÷ 100) − الموجود عندك، ومقرّب لفوق.',
                'inputs' => [
                    'last' => self::input('اللي اتباع في نفس الموسم السنة اللي فاتت', 200, 1, 'وحدة'),
                    'change' => self::input('التغيير المتوقع (بالسالب لو متوقع أقل)', 10, 1, '٪'),
                    'safety' => self::input('هامش أمان', 10, 1, '٪'),
                    'on_hand' => self::input('الموجود عندك جاهز أو في المخزن', 30, 1, 'وحدة'),
                ],
                'outputs' => [
                    'expected' => self::output('المتوقع تبيعه', 'وحدة', 0),
                    'to_make' => self::output('المطلوب تحضيره أو تطلبه', 'وحدة', 0),
                ],
            ],
            'stock-count' => [
                'title' => 'حاسبة فرق الجرد',
                'intro' => 'لخامة واحدة: رصيدها في الجرد اللي فات، واللي اشتريته، واللي المفروض اتصرف حسب المبيعات، واللي اتعد النهارده. استعمل نفس الوحدة في كل الخانات: كيلو، لتر، أو حتة.',
                'formula' => 'المفروض يكون موجود = رصيد الجرد اللي فات + المشتريات − الاستهلاك المفروض. الفرق = المفروض − اللي اتعد (بالسالب لو لقيت أكتر من المفروض). نسبة الفرق = الفرق ÷ الاستهلاك المفروض × 100. قيمة الفرق = الفرق × تكلفة الوحدة.',
                'inputs' => [
                    'opening' => self::input('الرصيد في الجرد اللي فات', 20, 0.5, 'وحدة'),
                    'purchases' => self::input('اللي اشتريته من ساعتها', 100, 0.5, 'وحدة'),
                    'used' => self::input('المفروض اتصرف حسب المبيعات والوصفات', 95, 0.5, 'وحدة'),
                    'counted' => self::input('اللي اتعد في الجرد', 18, 0.5, 'وحدة'),
                    'unit_cost' => self::input('تكلفة الوحدة', 120, 1, $pound),
                ],
                'outputs' => [
                    'gap' => self::output('الفرق: الناقص من المخزن', 'وحدة', 2),
                    'share' => self::output('نسبة الفرق من الاستهلاك', '٪', 1),
                    'value' => self::output('قيمة الفرق', $pound, 0),
                    'expected' => self::output('المفروض كان يكون موجود', 'وحدة', 2),
                ],
            ],
        ];
    }

    public static function exists(string $name): bool
    {
        return array_key_exists($name, self::all());
    }

    /**
     * The results for the given inputs (missing ones take the defaults; negatives count as zero, except a change
     * percentage). A result that has no meaning (a division by zero) is null.
     *
     * @param  array<string, float|int|string|null>  $values
     * @return array<string, float|null>
     */
    public static function compute(string $name, array $values = []): array
    {
        $calculator = self::all()[$name] ?? null;
        if ($calculator === null) {
            return [];
        }
        $v = [];
        foreach ($calculator['inputs'] as $key => $input) {
            $number = is_numeric($values[$key] ?? null) ? (float) $values[$key] : $input['default'];
            $v[$key] = $key === 'change' ? $number : max(0.0, $number);
        }
        $divide = fn (float $a, float $b): ?float => $b > 0 ? $a / $b : null;
        $roundUp = fn (?float $value, int $to): ?float => $value === null ? null : ceil(round($value, 6) / $to) * $to;

        switch ($name) {
            case 'packaging':
                $total = $v['box'] + $v['sides'] + $v['bag'] + $v['seal'] + $v['extras'];
                $share = $divide($total * 100, $v['price']);

                return ['total' => $total, 'share' => $share, 'priced' => $roundUp($v['price'] + $total, 5), 'monthly' => $total * $v['orders']];
            case 'food-cost':
                $price = $divide($v['cost'], $v['target'] / 100);
                $rounded = $roundUp($price, 5);

                return ['price' => $price, 'rounded' => $rounded, 'actual' => $rounded ? $divide($v['cost'] * 100, $rounded) : null, 'left' => $rounded === null ? null : $rounded - $v['cost'] - $v['packaging']];
            case 'cake-price':
                $cost = $v['kilo'] * $v['weight'] + $v['decor'] + $v['box'];
                $price = $roundUp($divide($cost, $v['target'] / 100), 10);

                return ['cost' => $cost, 'price' => $price, 'per_kilo' => $price === null ? null : $divide($price, $v['weight'])];
            case 'stock-count':
                $expected = $v['opening'] + $v['purchases'] - $v['used'];
                $gap = $expected - $v['counted'];

                return ['expected' => $expected, 'gap' => $gap, 'share' => $divide($gap * 100, $v['used']), 'value' => $gap * $v['unit_cost']];
            default: // season-stock
                $expected = $v['last'] * (1 + $v['change'] / 100);

                return ['expected' => ceil(round(max(0.0, $expected), 6)), 'to_make' => max(0.0, ceil(round($expected * (1 + $v['safety'] / 100) - $v['on_hand'], 6)))];
        }
    }

    /** A number as the calculator shows it: thousands separated, at most $decimals places, no trailing zeros; — when null. */
    public static function format(?float $value, int $decimals): string
    {
        if ($value === null) {
            return '—';
        }
        $text = number_format($value, $decimals, '.', ',');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** @return array{label:string, default:float, step:float, suffix:string} */
    private static function input(string $label, float $default, float $step, string $suffix): array
    {
        return compact('label', 'default', 'step', 'suffix');
    }

    /** @return array{label:string, suffix:string, decimals:int} */
    private static function output(string $label, string $suffix, int $decimals): array
    {
        return compact('label', 'suffix', 'decimals');
    }
}
