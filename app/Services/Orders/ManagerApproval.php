<?php

namespace App\Services\Orders;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A manager's OK for something a cashier may not do alone (a refund): the signed-in user has the right, or a manager
 * who has it types their PIN (users.manager_pin, set in Account security) on the cashier's device. Wrong PINs are
 * rate-limited per cashier and per manager, and recorded in the audit log.
 */
final class ManagerApproval
{
    public const ATTEMPTS = 5;

    public const DECAY_SECONDS = 900;

    /** @return Collection<int,User> managers who can approve $permission with their PIN */
    public function approvers(string $permission): Collection
    {
        return User::query()->where('is_active', true)->whereNotNull('manager_pin')->orderBy('name')->get()
            ->filter(fn (User $u) => $u->can($permission))->values();
    }

    /** The user who approves: $by when they have $permission, else the manager whose PIN was typed. */
    public function approve(User $by, string $permission, ?int $approverId, ?string $pin, string $subject): User
    {
        if ($by->can($permission) && (! $approverId || $approverId === $by->id)) {
            return $by;
        }
        if (! $approverId || ! $pin) {
            throw new OrderException(__('A manager must approve: choose the manager and enter their PIN.'));
        }
        $key = 'mgr-pin:'.tenant()->getTenantKey().':'.$by->id;
        $managerKey = 'mgr-pin-manager:'.tenant()->getTenantKey().':'.$approverId;
        foreach ([[$key, self::ATTEMPTS], [$managerKey, self::ATTEMPTS * 2]] as [$k, $max]) {
            if (RateLimiter::tooManyAttempts($k, $max)) {
                throw new OrderException(__('Too many wrong PINs. Try again in :n minutes.', ['n' => max(1, (int) ceil(RateLimiter::availableIn($k) / 60))]));
            }
        }
        $approver = User::query()->where('is_active', true)->find($approverId);
        if (! $approver || ! $approver->manager_pin || ! $approver->can($permission) || ! Hash::check($pin, $approver->manager_pin)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            RateLimiter::hit($managerKey, self::DECAY_SECONDS);
            Audit::record('pos.pin_failed', $subject, ['manager' => $approver?->name, 'cashier' => $by->name, 'for' => $permission]);
            $left = RateLimiter::remaining($key, self::ATTEMPTS);
            throw new OrderException($left > 0 ? __('Wrong PIN. :n tries left.', ['n' => $left]) : __('Too many wrong PINs. Try again in :n minutes.', ['n' => (int) ceil(self::DECAY_SECONDS / 60)]));
        }
        RateLimiter::clear($key);

        return $approver;
    }
}
