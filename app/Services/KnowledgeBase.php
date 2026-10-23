<?php

namespace App\Services;

use App\Models\KbArticle;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;

/**
 * The restaurant's knowledge base ("الأسئلة والأجوبة") as the staff screens and the guest menu read it. The shown
 * answers (active, in an active category or none) are cached per restaurant for CACHE_SECONDS in both languages, and
 * the cache is dropped whenever an answer or a category is saved or deleted (KbArticle / KbCategory events).
 * The guest menu only ever gets the public ones, and only while the plan has the `knowledge_base` feature.
 */
final class KnowledgeBase
{
    public const FEATURE = 'knowledge_base';

    public const CACHE_SECONDS = 3600;

    /** Whether this staff member gets the knowledge-base button on the call center, the POS and the order board. */
    public static function canOpen(?User $user): bool
    {
        return $user !== null && $user->can('kb.view') && Entitlements::current()->allows(self::FEATURE);
    }

    public static function forget(?string $tenantId): void
    {
        if ($tenantId) {
            Cache::forget(self::cacheKey($tenantId));
        }
    }

    /**
     * The shown answers of the current restaurant, both languages, in category then answer order (cached).
     *
     * @return list<array{id:int, category_id:?int, category:?string, category_en:?string, question:string, question_en:?string,
     *     answer:string, answer_en:?string, visibility:string, tags:?string}>
     */
    public static function rows(): array
    {
        $tenantId = (string) tenant()->getTenantKey();

        return Cache::remember(self::cacheKey($tenantId), self::CACHE_SECONDS, fn () => KbArticle::query()->shown()->with('category')->get()
            ->sortBy(fn (KbArticle $a) => [$a->category ? 0 : 1, $a->category?->sort_order ?? 0, $a->category_id ?? 0, $a->sort_order, $a->id])
            ->map(fn (KbArticle $a) => [
                'id' => $a->id, 'category_id' => $a->category_id,
                'category' => $a->category?->name, 'category_en' => $a->category?->name_en,
                'question' => $a->question, 'question_en' => $a->question_en,
                'answer' => $a->answer, 'answer_en' => $a->answer_en,
                'visibility' => $a->visibility, 'tags' => $a->tags,
            ])->values()->all());
    }

    /**
     * What the staff drawer loads: every shown answer, internal and public, in the dashboard's language.
     *
     * @return array{categories: list<array{id:int, name:string}>, articles: list<array<string, mixed>>}
     */
    public static function staffFeed(): array
    {
        $articles = array_map(fn (array $r) => [
            'id' => $r['id'],
            'category_id' => $r['category_id'],
            'category' => $r['category'] !== null ? tr($r['category'], $r['category_en']) : null,
            'question' => tr($r['question'], $r['question_en']),
            'answer' => tr($r['answer'], $r['answer_en']),
            'html' => (string) self::format(tr($r['answer'], $r['answer_en'])),
            'public' => $r['visibility'] === 'public',
            'tags' => (string) $r['tags'],
        ], self::rows());

        $categories = [];
        foreach ($articles as $a) {
            if ($a['category_id'] !== null && ! isset($categories[$a['category_id']])) {
                $categories[$a['category_id']] = ['id' => $a['category_id'], 'name' => $a['category']];
            }
        }

        return ['categories' => array_values($categories), 'articles' => $articles];
    }

    /**
     * The guest's frequently asked questions: public shown answers in the guest's language (Arabic when the English
     * text is empty); none at all when the plan does not include the knowledge base.
     *
     * @return list<array{id:int, question:string, answer:string}>
     */
    public static function publicArticles(): array
    {
        if (! Entitlements::current()->allows(self::FEATURE)) {
            return [];
        }

        return array_values(array_map(fn (array $r) => [
            'id' => $r['id'],
            'question' => tr($r['question'], $r['question_en']),
            'answer' => tr($r['answer'], $r['answer_en']),
        ], array_filter(self::rows(), fn (array $r) => $r['visibility'] === 'public')));
    }

    public static function hasPublic(): bool
    {
        return self::publicArticles() !== [];
    }

    /**
     * schema.org FAQPage of the public answers, for the about page.
     *
     * @param  list<array{id:int, question:string, answer:string}>  $articles
     * @return array<string, mixed>
     */
    public static function faqSchema(array $articles): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            '@id' => tenant()->url('about').'#faq',
            'inLanguage' => app()->getLocale(),
            'mainEntity' => array_map(fn (array $a) => [
                '@type' => 'Question',
                'name' => $a['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a['answer']],
            ], $articles),
        ];
    }

    /**
     * An answer as safe HTML: every character escaped, line breaks kept, and http(s) addresses turned into links
     * (opened in a new tab). Nothing else of HTML ever passes through.
     */
    public static function format(string $text): HtmlString
    {
        $parts = preg_split('~(https?://[^\s<>"\'`]+)~iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $html = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $html .= nl2br(e($part), false);

                continue;
            }
            // punctuation right after an address ends the sentence, not the link
            $url = (string) preg_replace('~[.,;:!?)\]}،؛]+$~u', '', $part);
            $html .= '<a href="'.e($url).'" target="_blank" rel="noopener nofollow ugc">'.e($url).'</a>'.e(substr($part, strlen($url)));
        }

        return new HtmlString($html);
    }

    private static function cacheKey(string $tenantId): string
    {
        return "kb:articles:$tenantId";
    }
}
