<?php

namespace App\Models;

use App\Models\Concerns\HasTwoFactor;
use App\Notifications\ResetPasswordLink;
use App\Support\Permissions;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Restaurant staff member. Scoped to the current tenant once tenancy is initialised; on the shared dashboard
 * host the tenant comes from this user (InitializeTenancyFromUser), never from the URL.
 */
class User extends Authenticatable implements HasLocalePreference
{
    use BelongsToTenant, HasFactory, HasRoles, HasTwoFactor, Notifiable;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['password', 'remember_token', 'manager_pin', 'temporary_password'];

    /** Emails go out in the language the user picked in the dashboard (Arabic by default). */
    public function preferredLocale(): string
    {
        return $this->locale === 'en' ? 'en' : 'ar';
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordLink(route('dash.password.reset', ['token' => $token, 'email' => $this->email])));
    }

    /** Same as the column defaults, so a freshly created model already knows them. */
    protected $attributes = ['is_active' => true, 'locale' => 'ar', 'must_change_password' => false];

    /**
     * A new password, whoever sets it (the security page, "forgot password", an EgWeb admin), retires the temporary
     * one of the welcome kit; only a save that sets a new temporary password itself (App\Services\WelcomeKit) keeps it.
     */
    protected static function booted(): void
    {
        static::saving(function (self $user) {
            if ($user->isDirty('password') && ! $user->isDirty('temporary_password')) {
                $user->must_change_password = false;
                $user->temporary_password = null;
            }
        });
    }

    protected string $guard_name = 'web';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'notifications_seen_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'temporary_password' => 'encrypted',
            'push_preferences' => 'array',
        ];
    }

    /** The push tokens of this user's phones in the EgHub apps (App\Services\Push\StaffPush). */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone !== null && $this->phone_verified_at !== null;
    }

    /** Stamps the phone as confirmed and retires the one-time code (by the WhatsApp webhook or an EgWeb admin). */
    public function markPhoneVerified(): void
    {
        $this->forceFill(['phone_verified_at' => now(), 'phone_verification_code' => null])->save();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isOwner(): bool
    {
        return $this->hasRole('owner');
    }

    public function roleKey(): string
    {
        return (string) $this->getRoleNames()->first();
    }

    public function roleName(): string
    {
        return Permissions::roleLabel($this->roleKey());
    }
}
