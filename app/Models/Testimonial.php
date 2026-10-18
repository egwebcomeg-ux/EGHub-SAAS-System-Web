<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A real owner's quote on the marketing site (platform level). Only quotes the owner agreed to (consent_at) can be
 * published; an optional restaurant link shows its logo and opens its live menu.
 */
class Testimonial extends Model
{
    protected $guarded = ['id'];

    /** How many quotes the home page shows at most. */
    public const HOME_LIMIT = 6;

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'consent_at' => 'datetime', 'rating' => 'integer', 'sort' => 'integer'];
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_published', true)->whereNotNull('consent_at')->orderBy('sort')->orderByDesc('id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isLive(): bool
    {
        return $this->is_published && $this->consent_at !== null;
    }

    public function displayQuote(): string
    {
        return tr($this->quote, $this->quote_en);
    }

    public function displayRole(): string
    {
        return tr($this->role, $this->role_en);
    }

    /** The linked restaurant's live menu, only while it is active. */
    public function menuUrl(): ?string
    {
        return $this->tenant && ! $this->tenant->isSuspended() ? Tenant::pathUrl($this->tenant->id) : null;
    }
}
