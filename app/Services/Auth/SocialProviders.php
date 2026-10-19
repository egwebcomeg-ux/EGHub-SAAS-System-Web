<?php

namespace App\Services\Auth;

/**
 * "Continue with Google / Facebook" for restaurant staff. A provider is offered only once both of its keys are in
 * .env (config/services.php), so Facebook stays hidden until its app is set up. The super admin never uses these.
 */
final class SocialProviders
{
    /** provider => brand name (written the same in both languages' buttons through __()) */
    public const PROVIDERS = ['google' => 'Google', 'facebook' => 'Facebook'];

    /** Minutes a verified identity waits on the register page for the restaurant details. */
    public const SIGNUP_MINUTES = 30;

    public static function isEnabled(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider])
            && filled(config("services.$provider.client_id"))
            && filled(config("services.$provider.client_secret"));
    }

    /** @return list<string> the providers whose buttons are shown, in display order */
    public static function enabled(): array
    {
        return array_values(array_filter(array_keys(self::PROVIDERS), self::isEnabled(...)));
    }

    /** The provider's name as owners know it: "جوجل" / "Google". */
    public static function label(string $provider): string
    {
        return $provider === 'facebook' ? __('Facebook') : __('Google');
    }

    /** Cache key of a verified identity handed from the dashboard host to the register page. */
    public static function signupKey(string $token): string
    {
        return 'social-signup:'.$token;
    }
}
