<?php

namespace App\Models;

use App\Services\Entitlements;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * A restaurant or cafe. Its id is the slug of its public menu: egmenu.app/{slug} (canonical), {slug}.egmenu.app (alias).
 * Data is never hard-deleted by the app: soft delete keeps everything restorable, suspension only takes the menu offline.
 */
class Tenant extends BaseTenant
{
    use HasDomains, SoftDeletes;

    /**
     * Never usable as a slug (the slug is the first path segment of the menu on the main domain and its subdomain):
     * platform hosts (www, app, admin), paths the main domain uses (directory, sitemap, ...) and names kept for later.
     */
    public const RESERVED = [
        'www', 'admin', 'app', 'api', 'mail', 'email', 'ftp', 'smtp', 'cpanel', 'webmail', 'ns1', 'ns2', 'mx',
        'demo', 'static', 'cdn', 'assets', 'build', 'media', 'storage', 'files', 'img', 'images', 'css', 'js', 'fonts',
        'help', 'support', 'docs', 'status', 'billing', 'pay', 'payments', 'pricing', 'plans', 'blog', 'dev', 'staging',
        'test', 'beta', 'dashboard', 'login', 'logout', 'register', 'signup', 'account', 'tenants', 'egmenu', 'egweb',
        'root', 'administrator', 'system', 'internal', 'terms', 'privacy', 'about', 'contact', 'features', 'en', 'ar',
        'up', 'health', 'sitemap', 'robots', 'favicon', 'manifest', 'webhooks', 'lang', 'menu', 'card', 'qr', 'localhost',
        'restaurants', 'restaurant', 'cafes', 'directory', 'search', 'explore', 'cities', 'cuisines', 'feed', 'rss', 'llms',
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
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{1,28}[a-z0-9])$/', $slug) || str_contains($slug, '--')) {
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

    /**
     * Active owners with an email: who receives subscription and payment emails.
     *
     * @return Collection<int, User>
     */
    public function owners()
    {
        return User::query()->withoutGlobalScopes()->where('tenant_id', $this->id)->where('is_active', true)
            ->whereNotNull('email')->where('email', '!=', '')->role('owner')->get();
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

    /** The active custom domain, if the plan (or an add-on) still includes custom domains. */
    public function customDomain(): ?Domain
    {
        $domain = $this->domains->first(fn (Domain $d) => $d->isActive());

        return $domain && Entitlements::for($this)->allows('custom_domain') ? $domain : null;
    }

    /**
     * Canonical public URL (QR codes, links, <link rel="canonical">): the active custom domain when there is one,
     * else the path on the main domain, egmenu.app/{slug}. url('card') → egmenu.app/{slug}/card.
     */
    public function url(string $path = ''): string
    {
        $custom = $this->customDomain();
        $path = ltrim($path, '/');
        if ($custom) {
            return scheme().'://'.$custom->domain.port_suffix().'/'.$path;
        }

        return self::pathUrl($this->id, $path);
    }

    /** The menu on the main domain: pathUrl('rosto', 't/abc') → https://egmenu.app/rosto/t/abc (no trailing slash). */
    public static function pathUrl(string $slug, string $path = ''): string
    {
        $path = ltrim($path, '/');

        return platform_url(null, $slug.($path === '' || str_starts_with($path, '?') ? $path : '/'.$path));
    }

    /** The short alias {slug}.egmenu.app (same pages, canonical to url()). */
    public function subdomainUrl(string $path = ''): string
    {
        return platform_url($this->id, ltrim($path, '/'));
    }
}
