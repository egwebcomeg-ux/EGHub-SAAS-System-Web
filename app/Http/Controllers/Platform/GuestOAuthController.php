<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Menu\AccountController;
use App\Models\Tenant;
use App\Services\Customers\SignIn\GuestOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Socialite\Contracts\User as ProviderUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * The main-domain half of a guest's «الدخول بجوجل» (App\Services\Customers\SignIn\GuestOAuth): start checks the
 * restaurant's signed state and sends the browser to Google; the callback (the one fixed address in Google's
 * console) finds or creates the guest in that restaurant and hands a one-time ticket back to the restaurant's host.
 * Every failure goes back to that host with a short code it turns into a message; nothing is cached.
 */
class GuestOAuthController extends Controller
{
    private const SESSION = 'guest_oauth';

    public function start(Request $request, string $provider): SymfonyRedirect|Response
    {
        abort_unless(GuestOAuth::enabled($provider), 404);
        $context = GuestOAuth::readState($provider, (string) $request->query('state', ''));
        if (! $context) {
            return $this->lost();
        }
        $request->session()->put(self::SESSION, $context);

        return Socialite::driver($provider)->redirectUrl(GuestOAuth::callbackUrl($provider))
            ->setScopes(['openid', 'email', 'profile'])->with(['prompt' => 'select_account'])
            ->redirect()->withHeaders(AccountController::HEADERS);
    }

    public function callback(Request $request, string $provider): RedirectResponse|Response
    {
        abort_unless(GuestOAuth::enabled($provider), 404);
        $context = $request->session()->pull(self::SESSION);
        if (! is_array($context) || ($context['provider'] ?? null) !== $provider
            || ! ($tenant = Tenant::query()->find($context['tenant'] ?? '')) || ! GuestOAuth::returnUrlBelongs($tenant, (string) $context['return'])) {
            return $this->lost();
        }
        // "Cancel" on Google's screen (?error=access_denied)
        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->back($context, 'cancelled');
        }
        try {
            $identity = Socialite::driver($provider)->redirectUrl(GuestOAuth::callbackUrl($provider))->user();
        } catch (InvalidStateException) {
            return $this->back($context, 'expired');
        } catch (Throwable $e) {
            report($e);

            return $this->back($context, 'failed');
        }
        $subject = trim((string) $identity->getId());
        if ($subject === '' || strlen($subject) > 64) {
            return $this->back($context, 'failed');
        }

        $customer = GuestOAuth::guestFor($tenant, $subject, $this->verifiedEmail($identity), trim((string) $identity->getName()));
        $ticket = GuestOAuth::issueTicket($tenant, $customer, (string) $context['nonce_hash']);

        return redirect()->away($context['return'].'?ticket='.$ticket)->withHeaders(AccountController::HEADERS);
    }

    /** Google's verified email only (OpenID email_verified); an unverified one is never kept nor matched. */
    private function verifiedEmail(ProviderUser $identity): ?string
    {
        $raw = (array) ($identity->user ?? []);
        $email = strtolower(trim((string) $identity->getEmail()));
        $verified = filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $verified && $email !== '' && strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** @param array{return:string} $context */
    private function back(array $context, string $error): RedirectResponse
    {
        return redirect()->away($context['return'].'?error='.$error)->withHeaders(AccountController::HEADERS);
    }

    /** No restaurant to go back to (state tampered with, expired, or another tab used it): a plain page. */
    private function lost(): Response
    {
        return response()->view('menu.guest-sign-in-lost', [], 400, AccountController::HEADERS);
    }
}
