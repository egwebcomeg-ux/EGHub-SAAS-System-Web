<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What an EgWeb admin did on the platform side (outside any restaurant, so it outlives a deleted one). */
class PlatformAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'admin_id');
    }

    /** @param  array<string, mixed>  $meta */
    public static function record(?PlatformAdmin $admin, string $action, string $subject = '', array $meta = []): self
    {
        return static::query()->create([
            'admin_id' => $admin?->id, 'action' => $action, 'subject' => mb_substr($subject, 0, 255),
            'meta' => $meta ?: null, 'ip' => request()->ip(),
        ]);
    }
}
