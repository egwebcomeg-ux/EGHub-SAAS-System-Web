<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use App\Services\KnowledgeBase;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A section of the knowledge base ("Delivery", "Payment", "Policies"); an inactive one hides its answers everywhere. */
class KbCategory extends TenantModel
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn (self $category) => KnowledgeBase::forget($category->tenant_id));
        static::deleted(fn (self $category) => KnowledgeBase::forget($category->tenant_id));
    }

    public function articles(): HasMany
    {
        return $this->hasMany(KbArticle::class, 'category_id');
    }

    public function label(): string
    {
        return tr($this->name, $this->name_en);
    }
}
