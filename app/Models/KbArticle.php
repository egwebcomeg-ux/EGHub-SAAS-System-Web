<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use App\Services\KnowledgeBase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\HtmlString;

/**
 * One question and its answer in the restaurant's knowledge base. Internal answers are for the team only (the call
 * center, the POS and the order board); public ones also show to guests on the about page of the menu.
 */
class KbArticle extends TenantModel
{
    /** visibility => [arabic, english] */
    public const VISIBILITIES = [
        'internal' => ['للفريق بس', 'Staff only'],
        'public' => ['للفريق وللعملاء على المنيو', 'Staff and guests on the menu'],
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn (self $article) => KnowledgeBase::forget($article->tenant_id));
        static::deleted(fn (self $article) => KnowledgeBase::forget($article->tenant_id));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(KbCategory::class, 'category_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Active answers whose category (if any) is active too. */
    public function scopeShown(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('category_id')->orWhereHas('category', fn (Builder $c) => $c->where('is_active', true)));
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    public function questionText(): string
    {
        return tr($this->question, $this->question_en);
    }

    public function answerText(): string
    {
        return tr($this->answer, $this->answer_en);
    }

    public function answerHtml(): HtmlString
    {
        return KnowledgeBase::format($this->answerText());
    }

    public static function visibilityLabel(string $visibility): string
    {
        $l = self::VISIBILITIES[$visibility] ?? [$visibility, $visibility];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }
}
