<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One phone call from a linked sales phone's call log: who, which number, how long, and the summary written after it. No audio. */
class SalesCall extends Model
{
    protected $table = 'crm_calls';

    protected $guarded = ['id'];

    /** direction => [arabic, english, badge classes] */
    public const DIRECTIONS = [
        'incoming' => ['داخلة', 'Incoming', 'bg-sky-100 text-sky-800'],
        'outgoing' => ['خارجة', 'Outgoing', 'bg-violet-100 text-violet-800'],
        'missed' => ['فايتة', 'Missed', 'bg-red-100 text-red-700'],
        'rejected' => ['مرفوضة', 'Rejected', 'bg-amber-100 text-amber-800'],
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'duration_seconds' => 'integer',
            'summarized_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'admin_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    /** @param Builder<SalesCall> $query */
    public function scopeVisibleTo(Builder $query, PlatformAdmin $admin): void
    {
        if (! $admin->allows('crm.team')) {
            $query->where('admin_id', $admin->id);
        }
    }

    /** @param Builder<SalesCall> $query */
    public function scopeMissedNotReturned(Builder $query): void
    {
        $query->whereIn('direction', ['missed', 'rejected'])->whereNull('returned_at');
    }

    public function isVisibleTo(PlatformAdmin $admin): bool
    {
        return $admin->allows('crm.team') || $this->admin_id === $admin->id;
    }

    public function wasTalked(): bool
    {
        return $this->duration_seconds > 0;
    }

    public function directionLabel(): string
    {
        return Lead::pick(self::DIRECTIONS[$this->direction] ?? [$this->direction, $this->direction]);
    }

    public function directionClasses(): string
    {
        return self::DIRECTIONS[$this->direction][2] ?? 'bg-navy-100 text-navy-600';
    }

    public function durationLabel(): string
    {
        return self::formatDuration($this->duration_seconds);
    }

    public static function formatDuration(int $seconds): string
    {
        return $seconds >= 3600 ? gmdate('G:i:s', $seconds) : gmdate('i:s', $seconds);
    }

    /** Who the number belongs to, or the number itself. */
    public function contactName(): string
    {
        return $this->lead?->tenant?->name ?? $this->prospect?->name ?? (string) ($this->phone ?: __('Hidden number'));
    }
}
