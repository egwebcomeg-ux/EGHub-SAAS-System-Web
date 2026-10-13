<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * A restaurant or cafe. Its id is the slug of its public menu: egmenu.app/{slug}.
 * Data is never hard-deleted by the app: soft delete keeps everything restorable, suspension only takes the menu offline.
 */
class Tenant extends BaseTenant
{
    use HasDomains, SoftDeletes;

    /**
     * Never usable as a slug: first path segments the platform itself uses on the main domain, plus
     * subdomain-like names kept free for future hosts.
     */
    public const RESERVED = [
        'www', 'admin', 'app', 'api', 'mail', 'email', 'ftp', 'smtp', 'cpanel', 'webmail', 'ns1', 'ns2', 'mx',
        'demo', 'static', 'cdn', 'assets', 'build', 'media', 'storage', 'files', 'img', 'images', 'css', 'js', 'fonts',
        'help', 'support', 'docs', 'status', 'billing', 'pay', 'payments', 'pricing', 'plans', 'blog', 'dev', 'staging',
        'test', 'beta', 'dashboard', 'login', 'logout', 'register', 'signup', 'account', 'tenants', 'egmenu', 'egweb',
        'root', 'administrator', 'system', 'internal', 'terms', 'privacy', 'about', 'contact', 'features', 'en', 'ar',
        'up', 'health', 'sitemap', 'robots', 'favicon', 'manifest', 'webhooks', 'lang', 'menu', 'card', 'qr', 'localhost',
    ];

    protected $keyType = 'string';

    /** The id is the slug we set ourselves, never an auto-increment. */
    public function getIncrementing()
    {
        return false;
    }

    public function shouldGenerateId(): bool
    {
        return false;
    }

    public static function getCustomColumns(): array
    {
        return ['id', 'name', 'status', 'suspended_at', 'created_at', 'updated_at', 'deleted_at'];
    }

    protected function casts(): array
    {
        return ['suspended_at' => 'datetime', 'data' => 'array'];
    }

    /** Lowercase letters, digits and inner hyphens, 3-30 chars, not reserved, not taken. */
    public static function slugError(string $slug): ?string
    {
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{1,28}[a-z0-9])$/', $slug) || str_contains($slug, '--')) {
            return __('Use 3-30 lowercase English letters, numbers or hyphens.');
        }
        if (in_array($slug, self::RESERVED, true)) {
            return __('This name is reserved, please choose another.');
        }
        if (static::withTrashed()->whereKey($slug)->exists()) {
            return __('This name is already taken.');
        }
        return null;
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /** Public menu URL: the verified custom domain when there is one, else egmenu.app/{slug}. */
    public function url(string $path = ''): string
    {
        $custom = $this->domains->first(fn ($d) => $d->verified_at !== null);
        $path = ltrim($path, '/');
        if ($custom) {
            return scheme() . '://' . $custom->domain . port_suffix() . '/' . $path;
        }
        return platform_url(null, $this->id . ($path === '' ? '' : '/' . $path));
    }
}
