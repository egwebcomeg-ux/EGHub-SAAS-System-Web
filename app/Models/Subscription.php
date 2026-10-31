<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Subscription extends Model
{
    public const STATUSES = [
        'trialing' => ['فترة تجريبية', 'Trial'],
        'active' => ['نشط', 'Active'],
        'past_due' => ['متأخر السداد', 'Past due'],
        'canceled' => ['ملغي', 'Canceled'],
    ];

    protected $guarded = ['id'];

    /**
     * No subscription is ever moved onto an archived plan (admin plan change, a new restaurant, a paid invoice):
     * the change is refused with a validation error. One already on it before archiving is left alone.
     * Every new subscription and every change of its status or plan goes into its history (SubscriptionChange).
     */
    protected static function booted(): void
    {
        static::created(fn (Subscription $subscription) => SubscriptionChange::recordFor($subscription, true));
        static::updated(fn (Subscription $subscription) => SubscriptionChange::recordFor($subscription, false));
        static::saving(function (Subscription $subscription) {
            if ($subscription->isDirty('plan_id') && Plan::query()->whereKey($subscription->plan_id)->whereNotNull('archived_at')->exists()) {
                throw ValidationException::withMessages(['plan_id' => __('This plan is archived and can no longer be assigned.')]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_ends_at' => 'datetime',
            'reminded_at' => 'datetime',
            'period_value' => 'float',
            'branch_offer_until' => 'datetime',
            'branch_offer_reminded_at' => 'datetime',
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

    /** Its status and plan changes (SubscriptionChange), oldest first by id. */
    public function history(): HasMany
    {
        return $this->hasMany(SubscriptionChange::class);
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

    /** Calendar days until the end, a started day counting as one (DST changes don't add a day). */
    public function daysLeft(): int
    {
        $end = $this->endsAt();
        if (! $end || $end->isPast()) {
            return 0;
        }
        $diff = now()->diff($end);

        return $diff->days + (($diff->h || $diff->i || $diff->s) ? 1 : 0);
    }
}
