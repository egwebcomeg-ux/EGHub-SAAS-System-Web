<?php

namespace App\Support;

use App\Models\Post;
use Illuminate\Support\Str;

/**
 * The interactive blocks a blog article can carry in its Markdown body. A block is a line of its own; anything that is
 * not one of these exact forms stays ordinary text (and so is escaped like the rest of the article):
 *
 *     [[calculator packaging]]          a calculator from App\Support\BlogCalculators
 *     [[box-picker]]                    the "which box?" picker (App\Support\PackagingPicker)
 *     [[checklist Before the order leaves]]
 *     - one line per item
 *     [[/checklist]]
 *     [[quiz Check yourself]]
 *     ? the question
 *     - a wrong answer
 *     + the right answer
 *     = why (shown after answering)
 *     [[/quiz]]
 *
 * The blocks are drawn by Blade views (resources/views/components/blog) from plain strings, escaped there; item and
 * explanation text may use inline Markdown (bold, links), rendered with raw HTML stripped like the article body.
 */
final class PostBlocks
{
    /** blocks drawn per article at most; later ones stay text */
    public const MAX_BLOCKS = 12;

    public const MAX_ITEMS = 40;

    public const MAX_QUESTIONS = 12;

    private const OPEN = '/^\[\[(calculator|box-picker|checklist|quiz)(?:[ \t]+([^\[\]\n]{1,120}))?\]\]$/u';

    /**
     * The body split into Markdown runs and blocks.
     *
     * @return list<array{type:'markdown', text:string}|array{type:'calculator', name:string}|array{type:'box-picker'}|array{type:'checklist', title:string, items:list<string>}|array{type:'quiz', title:string, questions:list<array{question:string, options:list<array{text:string, correct:bool}>, explanation:string}>}>
     */
    public static function parse(string $markdown): array
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $segments = [];
        $buffer = [];
        $blocks = 0;
        $fence = false;
        $flush = function () use (&$buffer, &$segments): void {
            if (trim(implode("\n", $buffer)) !== '') {
                $segments[] = ['type' => 'markdown', 'text' => implode("\n", $buffer)];
            }
            $buffer = [];
        };

        for ($i = 0, $count = count($lines); $i < $count; $i++) {
            $line = $lines[$i];
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '```') || str_starts_with($trimmed, '~~~')) {
                $fence = ! $fence;
            }
            if ($fence || $blocks >= self::MAX_BLOCKS || ! preg_match(self::OPEN, $trimmed, $m)) {
                $buffer[] = $line;

                continue;
            }
            $type = $m[1];
            $argument = trim($m[2] ?? '');
            $block = null;

            if ($type === 'calculator') {
                $block = BlogCalculators::exists($argument) ? ['type' => 'calculator', 'name' => $argument] : null;
            } elseif ($type === 'box-picker') {
                $block = $argument === '' ? ['type' => 'box-picker'] : null;
            } else {
                $end = null;
                for ($j = $i + 1; $j < $count; $j++) {
                    if (trim($lines[$j]) === "[[/$type]]") {
                        $end = $j;
                        break;
                    }
                }
                if ($end !== null) {
                    $inner = array_slice($lines, $i + 1, $end - $i - 1);
                    $block = $type === 'checklist' ? self::checklist($argument, $inner) : self::quiz($argument, $inner);
                    $i = $end;
                }
            }

            if ($block === null) {
                $buffer[] = $line;

                continue;
            }
            $flush();
            $blocks++;
            if ($block !== []) {
                $segments[] = $block;
            }
        }
        $flush();

        return $segments;
    }

    /** The article HTML: Markdown runs rendered safely, blocks drawn by their views; $key names the blocks (ids, saved ticks). */
    public static function render(string $markdown, string $key): string
    {
        $key = Str::slug($key) ?: 'post';
        $html = '';
        $n = 0;
        foreach (self::parse($markdown) as $segment) {
            if ($segment['type'] === 'markdown') {
                $html .= Post::render($segment['text']);

                continue;
            }
            $id = 'b-'.$key.'-'.(++$n);
            $html .= match ($segment['type']) {
                'calculator' => view('components.blog.calculator', ['id' => $id, 'name' => $segment['name'], 'calculator' => BlogCalculators::all()[$segment['name']], 'example' => BlogCalculators::compute($segment['name'])])->render(),
                'box-picker' => view('components.blog.box-picker', ['id' => $id])->render(),
                'checklist' => view('components.blog.checklist', ['id' => $id, 'title' => $segment['title'], 'items' => $segment['items']])->render(),
                'quiz' => view('components.blog.quiz', ['id' => $id, 'title' => $segment['title'], 'questions' => $segment['questions']])->render(),
            };
        }

        return $html;
    }

    /** The body as plain prose, without block markup lines (for reading time). */
    public static function prose(string $markdown): string
    {
        return (string) preg_replace('/^[ \t]*\[\[\/?(calculator|box-picker|checklist|quiz)\b[^\n]*\]\][ \t]*$/mu', '', $markdown);
    }

    /** Inline Markdown of an item or an explanation, raw HTML stripped and unsafe links dropped. */
    public static function inline(string $text): string
    {
        return trim((string) Str::inlineMarkdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]));
    }

    /**
     * @param  list<string>  $lines
     * @return array{type:'checklist', title:string, items:list<string>}|array{}
     */
    private static function checklist(string $title, array $lines): array
    {
        $items = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*[-*]\s+(.+)$/u', $line, $m) && count($items) < self::MAX_ITEMS) {
                $items[] = Str::limit(trim($m[1]), 300, '');
            }
        }

        return $items === [] ? [] : ['type' => 'checklist', 'title' => $title, 'items' => $items];
    }

    /**
     * @param  list<string>  $lines
     * @return array{type:'quiz', title:string, questions:list<array{question:string, options:list<array{text:string, correct:bool}>, explanation:string}>}|array{}
     */
    private static function quiz(string $title, array $lines): array
    {
        $questions = [];
        $current = null;
        $close = function () use (&$current, &$questions): void {
            $correct = $current === null ? 0 : count(array_filter($current['options'], fn (array $option) => $option['correct']));
            if ($current !== null && count($current['options']) >= 2 && $correct === 1 && count($questions) < self::MAX_QUESTIONS) {
                $questions[] = $current;
            }
            $current = null;
        };
        foreach ($lines as $line) {
            if (! preg_match('/^\s*([?+=-])\s+(.+)$/u', $line, $m)) {
                continue;
            }
            $text = Str::limit(trim($m[2]), 300, '');
            if ($m[1] === '?') {
                $close();
                $current = ['question' => $text, 'options' => [], 'explanation' => ''];
            } elseif ($current !== null && $m[1] === '=') {
                $current['explanation'] = $text;
            } elseif ($current !== null && count($current['options']) < 6) {
                $current['options'][] = ['text' => $text, 'correct' => $m[1] === '+'];
            }
        }
        $close();

        return $questions === [] ? [] : ['type' => 'quiz', 'title' => $title, 'questions' => $questions];
    }
}
