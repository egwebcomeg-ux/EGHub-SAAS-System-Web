<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A blog article of the marketing site (platform level). Arabic is the primary text; English is optional and falls
 * back to Arabic. Bodies are Markdown rendered with raw HTML stripped, so an article can never carry scripts.
 */
class Post extends Model
{
    protected $guarded = ['id'];

    /** Words per minute used for the reading-time badge (Arabic readers, prose). */
    public const WORDS_PER_MINUTE = 180;

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'published_at' => 'datetime'];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)->whereNotNull('published_at')->where('published_at', '<=', now())
            ->orderByDesc('published_at');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'author_id');
    }

    public function isLive(): bool
    {
        return $this->is_published && $this->published_at && $this->published_at->isPast();
    }

    public function displayTitle(): string
    {
        return tr($this->title, $this->title_en);
    }

    public function displayExcerpt(): string
    {
        return tr($this->excerpt, $this->excerpt_en);
    }

    public function displayDescription(): string
    {
        return tr($this->meta_description, $this->meta_description_en) ?: Str::limit(strip_tags($this->displayExcerpt() ?: $this->body), 155, '');
    }

    /** The Markdown body in the current language (English falls back to Arabic). */
    public function markdown(): string
    {
        return app()->getLocale() === 'en' && trim((string) $this->body_en) !== '' ? (string) $this->body_en : (string) $this->body;
    }

    /** Whether the text shown is Arabic (drives dir/lang on the article when the site is in English). */
    public function isArabicBody(): bool
    {
        return ! (app()->getLocale() === 'en' && trim((string) $this->body_en) !== '');
    }

    public static function render(string $markdown): string
    {
        return (string) Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 10]);
    }

    public function html(): string
    {
        return self::render($this->markdown());
    }

    public function readingMinutes(): int
    {
        $words = str_word_count(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', strip_tags($this->markdown())) ?? '', 0, 'أبتثجحخدذرزسشصضطظعغفقكلمنهويةىءآإؤئ');
        $arabic = preg_match_all('/[\p{Arabic}]+/u', $this->markdown());

        return max(1, (int) ceil(max($words, $arabic) / self::WORDS_PER_MINUTE));
    }

    /** A URL-safe slug: Latin titles are slugified; Arabic ones fall back to post-{n} unless a slug was typed. */
    public static function slugFor(string $title, ?string $wanted = null, ?int $ignoreId = null): string
    {
        // an Arabic title is not transliterated (that gives unreadable slugs): it falls back to post-{n}
        $base = $wanted || ! preg_match('/\p{Arabic}/u', $title) ? Str::slug((string) ($wanted ?: $title)) : '';
        if ($base === '') {
            $base = 'post-'.((int) self::query()->max('id') + 1);
        }
        $slug = $base;
        for ($i = 2; self::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists(); $i++) {
            $slug = "$base-$i";
        }

        return $slug;
    }
}
