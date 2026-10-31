<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of a subscription's status or plan (a platform row, keyed by restaurant). Written by
 * Subscription::booted() on every save that changes them, whatever saved it; the code that knows why wraps the save
 * in SubscriptionChange::because(), anything else is "admin" (an admin is signed in) or "system".
 */
class SubscriptionChange extends Model
{
    public $timestamps = false;

    /** cause => English label (the translation key) */
    public const CAUSES = [
        'signup' => 'Sign-up',
        'payment' => 'Paid invoice',
        'admin' => 'Changed by an admin',
        'trial_ended' => 'Trial ended',
        'period_ended' => 'Period ended without renewal',
        'grace_ended' => 'Grace period ended',
        'import' => 'Imported',
        'system' => 'System',
    ];

    protected $guarded = ['id'];

    private static ?string $cause = null;

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Runs $callback with $cause recorded on every subscription change it makes.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function because(string $cause, Closure $callback): mixed
    {
        $previous = self::$cause;
        self::$cause = $cause;
        try {
            return $callback();
        } finally {
            self::$cause = $previous;
        }
    }

    /** Records the change $subscription was just created or updated with (nothing when neither the status nor the plan moved). */
    public static function recordFor(Subscription $subscription, bool $created): void
    {
        if (! $created && ! $subscription->wasChanged(['status', 'plan_id'])) {
            return;
        }
        $admin = auth('admin')->user();
        static::query()->create([
            'subscription_id' => $subscription->id,
            'tenant_id' => $subscription->tenant_id,
            'from_status' => $created ? null : $subscription->getOriginal('status'),
            'to_status' => (string) $subscription->status,
            'from_plan_id' => $created ? null : $subscription->getOriginal('plan_id'),
            'to_plan_id' => $subscription->plan_id,
            'cause' => self::$cause ?? ($admin ? 'admin' : 'system'),
            'admin_id' => $admin instanceof PlatformAdmin ? $admin->id : null,
            'changed_at' => now(),
        ]);
    }

    public function causeLabel(): string
    {
        return isset(self::CAUSES[$this->cause]) ? __(self::CAUSES[$this->cause]) : $this->cause;
    }
}
