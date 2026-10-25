<?php

namespace Tests\Feature;

use App\Support\BlogCalculators;
use App\Support\PackagingPicker;
use App\Support\PostBlocks;
use Tests\TestCase;

/** The interactive blocks of blog articles: what each one draws, its server-side fallback, and the allow-list. */
class PostBlocksTest extends TestCase
{
    public function test_a_calculator_draws_labelled_inputs_and_a_worked_example_with_its_formula(): void
    {
        $html = PostBlocks::render("مقدمة\n\n[[calculator packaging]]\n\nخاتمة", 'my-post');

        $this->assertStringContainsString('<p>مقدمة</p>', $html);
        $this->assertStringContainsString('<p>خاتمة</p>', $html);
        $this->assertStringContainsString('data-bcalc="packaging"', $html);
        $this->assertStringContainsString('<label for="b-my-post-1-box"', $html);
        $this->assertStringContainsString('id="b-my-post-1-box" name="box" type="number"', $html);
        // without JavaScript: the example numbers, the results worked out on the server, and the formula
        $this->assertStringContainsString('مثال بالأرقام:', $html);
        $this->assertStringContainsString('<span data-out="total" data-decimals="2">12.75</span>', $html);
        $this->assertStringContainsString('<span data-out="share" data-decimals="1">8.5</span>', $html);
        $this->assertStringContainsString('<span data-out="priced" data-decimals="0">165</span>', $html);
        $this->assertStringContainsString('<span data-out="monthly" data-decimals="0">3,825</span>', $html);
        $this->assertStringContainsString('المعادلة:', $html);
    }

    public function test_the_calculator_formulas(): void
    {
        $this->assertSame(['price' => 150.0, 'rounded' => 150.0, 'actual' => 30.0, 'left' => 105.0], BlogCalculators::compute('food-cost'));
        $this->assertSame(['price' => 80.0, 'rounded' => 80.0, 'actual' => 30.0, 'left' => 56.0], BlogCalculators::compute('food-cost', ['cost' => 24]));
        $cake = BlogCalculators::compute('cake-price');
        $this->assertSame([535.0, 1530.0, 765.0], [$cake['cost'], $cake['price'], $cake['per_kilo']]);
        $this->assertSame(['expected' => 220.0, 'to_make' => 212.0], BlogCalculators::compute('season-stock'));
        // a fall in sales is allowed, a negative stock is not; on hand above the need makes nothing
        $this->assertSame(['expected' => 180.0, 'to_make' => 0.0], BlogCalculators::compute('season-stock', ['change' => -10, 'on_hand' => 500, 'safety' => -5]));

        // nonsense in, no division by zero out
        $zero = BlogCalculators::compute('food-cost', ['target' => 0]);
        $this->assertNull($zero['price']);
        $this->assertSame('—', BlogCalculators::format($zero['price'], 2));
        // text falls back to the default, a negative price to zero
        $this->assertSame(['total' => 6.75, 'share' => 4.5], array_intersect_key(BlogCalculators::compute('packaging', ['price' => 'abc', 'box' => -3]), ['total' => 0, 'share' => 0]));
        $this->assertSame([], BlogCalculators::compute('nope'));
        $this->assertSame('1,530', BlogCalculators::format(1530.0, 0));
        $this->assertSame('12.75', BlogCalculators::format(12.75, 2));
        $this->assertSame('8', BlogCalculators::format(8.0, 2));
    }

    public function test_checklist_and_quiz_blocks(): void
    {
        $html = PostBlocks::render(implode("\n", [
            '[[checklist قبل الخروج]]',
            '- راجع **الصوصات**',
            '* المشروبات في حامل',
            'سطر مش بند',
            '[[/checklist]]',
            '[[quiz اختبر نفسك]]',
            '? فين اللحمة النيّة',
            '- فوق',
            '+ تحت',
            '= عشان ما تنقّطش',
            '? سؤال فيه إجابتين صح بيتشال',
            '+ أ',
            '+ ب',
            '? سؤال باختيار واحد بيتشال',
            '+ بس',
            '[[/quiz]]',
        ]), 'p');

        $this->assertStringContainsString('data-bchecklist="b-p-1"', $html);
        $this->assertStringContainsString('قبل الخروج', $html);
        $this->assertStringContainsString('2 بند', $html);
        $this->assertStringContainsString('راجع <strong>الصوصات</strong>', $html);
        $this->assertStringContainsString('<label for="b-p-1-1">', $html);
        $this->assertStringNotContainsString('سطر مش بند', $html);

        $this->assertStringContainsString('data-bquiz', $html);
        $this->assertSame(1, substr_count($html, 'data-bquiz-q'));
        $this->assertStringContainsString('name="b-p-2-0" value="1"', $html);
        $this->assertStringContainsString('<summary>الإجابة</summary>', $html);
        $this->assertStringContainsString('<strong>تحت</strong> — عشان ما تنقّطش', $html);
        $this->assertStringNotContainsString('إجابتين صح', $html);
        $this->assertStringNotContainsString('باختيار واحد', $html);
    }

    public function test_the_box_picker_carries_every_answer_and_shows_the_usual_one_without_javascript(): void
    {
        $this->assertCount(count(PackagingPicker::foods()) * 3 * 3, PackagingPicker::matrix());
        $this->assertStringContainsString('مدورة', PackagingPicker::recommend('soup', 'hot', 'liquid')['box']);
        $this->assertStringContainsString('تهوية', PackagingPicker::recommend('fried', 'hot', 'dry')['box']);
        $this->assertStringContainsString('كرتونة تورتة', PackagingPicker::recommend('cake', 'cold', 'dry')['box']);
        $this->assertSame(PackagingPicker::recommend('mains', 'hot', 'dry'), PackagingPicker::recommend('<script>', 'x', 'y'));
        foreach (PackagingPicker::matrix() as $answer) {
            $this->assertStringNotContainsString('فوم', $answer['box']);
        }

        $html = PostBlocks::render('[[box-picker]]', 'p');
        $this->assertStringContainsString('data-bpicker', $html);
        $this->assertStringContainsString('<label for="b-p-1-food"', $html);
        $this->assertStringContainsString('<option value="soup"', $html);
        $this->assertStringContainsString('data-bpicker-table open', $html);
        $this->assertStringContainsString('<th scope="row">شوربة</th>', $html);
        $this->assertStringContainsString('&quot;soup|hot|liquid&quot;', $html);
    }

    public function test_unknown_and_malicious_block_lines_stay_inert(): void
    {
        $html = PostBlocks::render(implode("\n", [
            '[[calculator evil]]',
            '[[script]]',
            '[[box-picker onload=alert(1)]]',
            '[[checklist <img src=x onerror=alert(1)>]]',
            '- <script>alert(1)</script>بند',
            '- [رابط](javascript:alert(1))',
            '[[/checklist]]',
            '[[quiz مش مقفول]]',
            '? سؤال',
            '+ صح',
            '- غلط',
            '```',
            '[[calculator packaging]]',
            '```',
        ]), '"><script>');

        $this->assertStringNotContainsString('data-bcalc=', $html);
        $this->assertStringNotContainsString('data-bpicker', $html);
        $this->assertStringNotContainsString('data-bquiz', $html);
        $this->assertStringContainsString('[[calculator evil]]', $html);
        $this->assertStringContainsString('[[script]]', $html);
        $this->assertStringContainsString('[[quiz مش مقفول]]', $html);
        $this->assertStringContainsString('<code>[[calculator packaging]]', $html);
        // the checklist is drawn, with everything in it escaped or stripped
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('data-bchecklist="b-script-1"', $html);
    }

    public function test_blocks_per_article_are_capped(): void
    {
        $html = PostBlocks::render(str_repeat("[[calculator food-cost]]\n\n", PostBlocks::MAX_BLOCKS + 2), 'p');

        $this->assertSame(PostBlocks::MAX_BLOCKS, substr_count($html, 'data-bcalc="food-cost"'));
        $this->assertStringContainsString('<p>[[calculator food-cost]]</p>', $html);
    }
}
