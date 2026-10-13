<?php

namespace App\Models;

use App\Notifications\ResetPasswordLink;
use App\Support\Permissions;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    use BelongsToTenant, HasFactory, HasRoles, Notifiable;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['password', 'remember_token'];

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
    protected $attributes = ['is_active' => true, 'locale' => 'ar'];

    protected string $guard_name = 'web';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
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
