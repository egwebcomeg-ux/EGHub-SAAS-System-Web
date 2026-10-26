<?php

namespace App\Models;

use App\Models\Concerns\HasTwoFactor;
use App\Notifications\ResetPasswordLink;
use App\Support\AdminPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Platform owner (admin.<domain>). Separate table and guard from restaurant staff. What each admin may do comes from
 * its role plus optional extra permissions (App\Support\AdminPermissions), never from the restaurant staff roles.
 */
class PlatformAdmin extends Authenticatable
{
    protected $guarded = ['id'];

    use HasTwoFactor, Notifiable;

    protected $hidden = ['password', 'remember_token'];

    /** Same as the column defaults, so a freshly created model already knows them. */
    protected $attributes = ['role' => 'owner', 'is_active' => true];

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordLink(route('admin.password.reset', ['token' => $token, 'email' => $this->email])));
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'permissions' => 'array',
            'is_active' => 'boolean',
            'crm_discount_max' => 'integer',
        ];
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /** May this admin use $permission (role permissions + extra ones)? A switched-off admin may do nothing. */
    public function allows(string $permission): bool
    {
        return $this->is_active && in_array($permission, $this->grantedPermissions(), true);
    }

    /** @return list<string> */
    public function grantedPermissions(): array
    {
        $extra = array_values(array_intersect((array) ($this->permissions ?? []), array_keys(AdminPermissions::ALL)));

        return array_values(array_unique([...AdminPermissions::of((string) $this->role), ...$extra]));
    }

    /**
     * The most % this admin may give a restaurant on a sales call: 0 without crm.discount; the platform owner up to
     * TenantDiscount::CAP; anyone else their own limit (crm_discount_max) or their role's default, never above the cap.
     */
    public function discountLimit(): int
    {
        if (! $this->allows('crm.discount')) {
            return 0;
        }
        if ($this->isOwner()) {
            return TenantDiscount::CAP;
        }
        $limit = $this->crm_discount_max ?? AdminPermissions::DISCOUNT_DEFAULTS[(string) $this->role] ?? 10;

        return max(0, min(TenantDiscount::CAP, (int) $limit));
    }

    public function roleName(): string
    {
        return AdminPermissions::roleLabel((string) $this->role);
    }

    /** @param Builder<PlatformAdmin> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Active admins who handle $permission: who gets the emails about payments, domains and service requests.
     *
     * @return Collection<int, PlatformAdmin>
     */
    public static function notifiable(string $permission): Collection
    {
        return static::query()->active()->get()->filter(fn (PlatformAdmin $admin) => $admin->allows($permission))->values();
    }
}
