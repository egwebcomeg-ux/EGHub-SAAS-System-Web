<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\WelcomeKitReady;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * The welcome kit of a restaurant EgWeb made for a prospect: a strong temporary password the owner must change at
 * the first sign-in, and a printable welcome page (signed link, 14 days) with the menu link and QR, the sign-in
 * details, the trial and the first steps. The page shows the temporary password only while the owner has not
 * changed it yet; it is kept encrypted on the user (App\Models\User clears it with any new password).
 *
 * A new kit (new password) changes the page's nonce, so links sent before stop working.
 */
final class WelcomeKit
{
    public const VALID_DAYS = 14;

    /** The sign-in email of an owner who gave none: {slug}@egmenu.app, never sent anything. */
    public const PLACEHOLDER_DOMAIN = 'egmenu.app';

    public static function placeholderEmail(string $slug): string
    {
        return $slug.'@'.self::PLACEHOLDER_DOMAIN;
    }

    public static function isPlaceholder(?string $email, Tenant $tenant): bool
    {
        return strtolower((string) $email) === self::placeholderEmail($tenant->getTenantKey());
    }

    /**
     * A new temporary password and a new welcome page for the owner; the page links sent before stop working.
     * Returns the password (the admin sees it in the kit, never in an email or a WhatsApp message).
     */
    public function issue(Tenant $tenant, User $owner): string
    {
        $password = Str::password(14, symbols: false);
        $owner->forceFill(['password' => $password, 'must_change_password' => true, 'temporary_password' => $password])->save();
        $this->inTenant($tenant, fn () => Settings::put(['welcome_kit' => json_encode([
            'user' => $owner->id, 'nonce' => Str::random(40), 'expires_at' => now()->addDays(self::VALID_DAYS)->getTimestamp(),
        ])]));

        return $password;
    }

    /** @return array{user:int, nonce:string, expires_at:int}|null the current kit of $tenant */
    public function state(Tenant $tenant): ?array
    {
        $kit = json_decode((string) $this->inTenant($tenant, fn () => Settings::get('welcome_kit')), true);

        return is_array($kit) && isset($kit['user'], $kit['nonce'], $kit['expires_at']) ? $kit : null;
    }

    public function expiresAt(Tenant $tenant): ?CarbonInterface
    {
        $kit = $this->state($tenant);

        return $kit ? Carbon::createFromTimestamp($kit['expires_at']) : null;
    }

    /** The signed welcome page (null when there is no kit or it expired). */
    public function url(Tenant $tenant): ?string
    {
        $kit = $this->state($tenant);
        if (! $kit || $kit['expires_at'] <= now()->getTimestamp()) {
            return null;
        }

        return URL::temporarySignedRoute('dash.welcome-kit', Carbon::createFromTimestamp($kit['expires_at']), [
            'place' => $tenant->getTenantKey(), 'nonce' => $kit['nonce'],
        ]);
    }

    /** The owner the kit was made for (inside $tenant only). */
    public function owner(Tenant $tenant): ?User
    {
        $kit = $this->state($tenant);

        return $kit ? User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getTenantKey())->find($kit['user']) : null;
    }

    /**
     * Emails the owner the welcome page link (queued), only to a real address: never the placeholder. The email
     * carries no password; the page shows it while it is still unchanged.
     */
    public function email(Tenant $tenant, User $owner): bool
    {
        $url = $this->url($tenant);
        if (! $url || ! $owner->email || self::isPlaceholder($owner->email, $tenant)) {
            return false;
        }
        $sub = $tenant->subscription()->with('plan')->first();
        $owner->notify(new WelcomeKitReady(
            place: $tenant->name, welcomeUrl: $url, menuUrl: $tenant->url(), dashboardUrl: platform_url('app', '/login'),
            trialEndsAt: $sub?->status === 'trialing' ? $sub->trial_ends_at : null, plan: (string) $sub?->plan?->displayName(),
        ));

        return true;
    }

    /** "Send it on WhatsApp" from the admin page: the owner's mobile, a short message and the page link, no password. */
    public function whatsappUrl(Tenant $tenant, User $owner): ?string
    {
        $url = $this->url($tenant);
        $phone = $owner->phone ? normalize_phone($owner->phone) : null;
        if (! $url || ! $phone) {
            return null;
        }
        $text = __('Hello :name! Your EgMenu dashboard for :place is ready. Open your welcome page for the sign-in details and your first 5 steps: :url', [
            'name' => $owner->name, 'place' => $tenant->name, 'url' => $url,
        ]);

        return 'https://wa.me/'.whatsapp_number($phone).'?text='.rawurlencode($text);
    }

    /** @return array{whatsapp:string, email:string} EgWeb's support contacts (platform settings) */
    public static function support(): array
    {
        return [
            'whatsapp' => trim((string) PlatformSetting::get('support_whatsapp', '')),
            'email' => trim((string) PlatformSetting::get('support_email', '')),
        ];
    }

    private function inTenant(Tenant $tenant, \Closure $fn): mixed
    {
        if (tenant()?->getTenantKey() === $tenant->getTenantKey()) {
            return $fn();
        }
        $previous = tenant();
        tenancy()->initialize($tenant);
        try {
            return $fn();
        } finally {
            $previous ? tenancy()->initialize($previous) : tenancy()->end();
            Settings::flush();
        }
    }
}
