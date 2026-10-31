<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Stancl\Tenancy\Database\Models\Domain as BaseDomain;

/**
 * A restaurant's own domain ("menu.cafe.com"). Registered through egweb.com.eg or brought by the restaurant;
 * EgWeb activates it (server alias + SSL) and only then the menu is served on it and QR codes use it.
 */
class Domain extends BaseDomain
{
    public const STATUSES = [
        'requested' => ['مستني الحجز من EgWeb', 'Waiting for registration by EgWeb'],
        'pending' => ['مستني الربط', 'Waiting to be connected'],
        'active' => ['شغال', 'Active'],
        'rejected' => ['مرفوض', 'Rejected'],
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    /** Requests EgWeb still has to connect or reject (of a restaurant that still exists). */
    public function scopeWaiting(Builder $query): void
    {
        $query->whereHas('tenant')->whereIn('status', ['requested', 'pending']);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->verified_at !== null;
    }

    public function statusLabel(): string
    {
        $l = self::STATUSES[$this->status] ?? [$this->status, $this->status];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }

    /** "www.Cafe.com/" → "cafe.com"; null when it is not a usable domain name. */
    public static function normalize(string $input): ?string
    {
        $host = strtolower(trim($input));
        $host = preg_replace('#^https?://#', '', $host);
        $host = rtrim(explode('/', $host)[0], '.');
        $host = preg_replace('/^www\./', '', $host);
        if (! preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $host)) {
            return null;
        }
        foreach (config('tenancy.central_domains') as $central) {
            if ($host === $central || str_ends_with($host, '.'.$central)) {
                return null;
            }
        }

        return $host;
    }
}
