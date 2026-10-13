<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    public const STATUSES = [
        'trialing' => ['فترة تجريبية', 'Trial'],
        'active'   => ['نشط', 'Active'],
        'past_due' => ['متأخر السداد', 'Past due'],
        'canceled' => ['ملغي', 'Canceled'],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trial_ends_at'        => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end'   => 'datetime',
            'grace_ends_at'        => 'datetime',
            'reminded_at'          => 'datetime',
            'period_value'         => 'float',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(SubscriptionAddon::class);
    }

    public function statusLabel(): string
    {
        $l = self::STATUSES[$this->status] ?? [$this->status, $this->status];
        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }

    /** When the current paid/trial access ends. */
    public function endsAt(): ?CarbonInterface
    {
        return $this->status === 'trialing' ? $this->trial_ends_at : $this->current_period_end;
    }

    /** Shown as a banner in the dashboard: trial ending, renewal due, or unpaid. */
    public function needsAttention(): bool
    {
        return $this->status === 'past_due' || ($this->endsAt() && $this->endsAt()->lte(now()->addDays(3)));
    }

    public function daysLeft(): int
    {
        $end = $this->endsAt();
        return $end ? max(0, (int) ceil(now()->diffInSeconds($end, false) / 86400)) : 0;
    }
}
