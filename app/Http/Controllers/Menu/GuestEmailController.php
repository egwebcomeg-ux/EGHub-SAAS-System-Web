<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Customers\ClaimLinks;
use App\Services\Customers\GuestAccount;
use App\Services\Customers\SignIn\EmailConfirmation;
use App\Services\Customers\SignIn\EmailSignIn;
use App\Services\Customers\SignIn\GuestIdentity;
use App\Services\Customers\SignIn\GuestOAuth;
use App\Services\Customers\SignIn\PasswordSignIn;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The email ways into «حسابي» on the restaurant's own host: «ادخل بالإيميل» (a one-time link + a 6-digit code),
 * «اعمل حساب» / «ادخل» with an email and a password, «نسيت الباسورد؟», the personal claim link of «ادعي عملاءك», and
 * the proof of a mobile another guest already has (a link to that guest's email). Every page and answer is private
 * (AccountController::HEADERS), nothing is cached, and the answers never tell whether an email has an account.
 */
class GuestEmailController extends Controller
{
    private const SENT = 'If this email can sign in here, a link and a code are on their way. Check your inbox (and the spam folder).';

    /** «ابعتلي لينك»: always the same answer for a well-formed email. */
    public function start(Request $request, EmailSignIn $emails): JsonResponse
    {
        $data = $request->validate(['email' => 'required|string|email:rfc|max:190']);
        $email = GuestIdentity::normalizeEmail($data['email']);
        if ($email === null) {
            return response()->json(['message' => __('Enter a valid email.')], 422, AccountController::HEADERS);
        }
        if ($emails->start($email, (string) $request->ip()) === 'blocked') {
            return response()->json(['message' => __('Too many tries. Please try again in a few minutes.')], 429, AccountController::HEADERS);
        }

        return response()->json(['ok' => true, 'message' => __(self::SENT), 'minutes' => EmailSignIn::MINUTES], 200, AccountController::HEADERS);
    }

    /** The 6-digit code typed on the page that asked for it. */
    public function code(Request $request, EmailSignIn $emails): JsonResponse
    {
        $data = $request->validate(['email' => 'required|string|max:190', 'code' => 'required|string|max:12']);
        $email = GuestIdentity::normalizeEmail($data['email']);
        $customer = $email ? $emails->redeemCode($email, $data['code']) : null;
        if (! $customer) {
            return response()->json(['message' => __('This code is wrong or expired. Check the latest email, or ask for a new code.')], 422, AccountController::HEADERS);
        }
        [$target, $cookie] = $this->signedIn($request, $customer);

        return response()->json(['ok' => true, 'redirect' => $target], 200, AccountController::HEADERS)->withCookie($cookie);
    }

    /** The emailed link opens a page with one button, so a mail scanner that opens links does not use it up. */
    public function link(Request $request, string $token, EmailSignIn $emails): Response
    {
        $request->attributes->set('noindex', true);
        $row = $emails->pending($token);

        return response()->view('menu.account-email-link', [
            'settings' => Settings::all(), 'token' => $token, 'valid' => $row !== null,
        ], $row ? 200 : 410, AccountController::HEADERS);
    }

    public function redeem(Request $request, string $token, EmailSignIn $emails): RedirectResponse
    {
        $customer = $emails->redeemLink($token);
        if (! $customer) {
            return redirect()->route('menu.account.sign-in')->withHeaders(AccountController::HEADERS)
                ->withErrors(['email' => __('This link was used or expired. Ask for a new one.')], 'email');
        }
        [$target, $cookie] = $this->signedIn($request, $customer);

        return redirect()->to($target)->withHeaders(AccountController::HEADERS)->withCookie($cookie);
    }

    /** «أكّد إيميلك» (sent after an order with an email): a page with one button, like the sign-in link. */
    public function confirmPage(Request $request, string $token, EmailConfirmation $confirmations): Response
    {
        $request->attributes->set('noindex', true);
        $valid = $confirmations->pending($token) !== null;

        return response()->view('menu.account-email-link', [
            'settings' => Settings::all(), 'token' => $token, 'valid' => $valid, 'confirm' => true,
        ], $valid ? 200 : 410, AccountController::HEADERS);
    }

    public function confirm(Request $request, string $token, EmailConfirmation $confirmations): RedirectResponse
    {
        [$outcome, $customer] = $confirmations->confirm($token, GuestAccount::fromRequest($request));
        if ($outcome !== 'ok' || ! $customer) {
            $message = match ($outcome) {
                'elsewhere' => __('Open this link on the phone you ordered from, or first confirm your mobile here (a code or a previous order number), then open it again.'),
                'taken' => __('This email is already on another account here.'),
                default => __('This link was used or expired. Ask for a new one.'),
            };

            return redirect()->route('menu.account.sign-in')->withHeaders(AccountController::HEADERS)->withErrors(['email' => $message], 'email');
        }

        return redirect()->to(GuestAccount::url($customer))->withHeaders(AccountController::HEADERS)
            ->with('ok', __('Your email is confirmed: next time sign in with it.'))
            ->withCookie(GuestAccount::rememberCookie(GuestAccount::token($customer)));
    }

    /** The account page's «ابعت لينك التأكيد تاني» for the pending email. */
    public function confirmAgain(Request $request, EmailConfirmation $confirmations): RedirectResponse
    {
        $me = GuestAccount::fromRequest($request);
        if (! $me || ! $me->pending_email) {
            return redirect()->route('menu.show')->withHeaders(AccountController::HEADERS);
        }
        $confirmations->request($me, (string) $me->pending_email, (string) $request->ip());

        return redirect()->to(GuestAccount::url($me))->withHeaders(AccountController::HEADERS)
            ->with('ok', __('We sent the link to :email. Open it on this phone.', ['email' => self::maskEmail((string) $me->pending_email)]));
    }

    /** «اعمل حساب»: name, mobile, email, password. A mobile another guest here has is kept aside until proven. */
    public function register(Request $request, PasswordSignIn $passwords): RedirectResponse
    {
        $data = $request->validateWithBag('register', [
            'name' => 'required|string|max:100',
            'phone' => 'required|string|max:20',
            'email' => 'required|string|email:rfc|max:190',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $phone = normalize_phone($data['phone']);
        $email = GuestIdentity::normalizeEmail($data['email']);
        $back = fn (string $field, string $message) => redirect()->route('menu.account.sign-in', ['tab' => 'register'])->withHeaders(AccountController::HEADERS)
            ->withInput($request->only('name', 'phone', 'email'))->withErrors([$field => $message], 'register');
        if ($phone === null) {
            return $back('phone', __('Enter an Egyptian mobile number, e.g. 01012345678.'));
        }
        if ($email === null) {
            return $back('email', __('Enter a valid email.'));
        }
        [$outcome, $customer] = $passwords->register($data['name'], $phone, $email, $data['password'], (string) $request->ip());
        if ($outcome === 'blocked') {
            return $back('email', __('Too many tries. Please try again in an hour.'));
        }
        if ($outcome === 'email_taken' || ! $customer) {
            return $back('email', __('This email already has an account here. Sign in, or use «Forgot your password?».'));
        }

        return redirect()->to(GuestAccount::url($customer))->withHeaders(AccountController::HEADERS)
            ->withCookie(GuestAccount::rememberCookie(GuestAccount::token($customer)));
    }

    /** «ادخل»: email + password. One answer for every failure. */
    public function login(Request $request, PasswordSignIn $passwords): RedirectResponse
    {
        $data = $request->validateWithBag('login', ['email' => 'required|string|max:190', 'password' => 'required|string|max:200']);
        $email = GuestIdentity::normalizeEmail($data['email']);
        [$outcome, $customer] = $email ? $passwords->login($email, $data['password'], (string) $request->ip()) : ['failed', null];
        if ($outcome !== 'ok' || ! $customer) {
            return redirect()->route('menu.account.sign-in', ['tab' => 'login'])->withHeaders(AccountController::HEADERS)
                ->withInput($request->only('email'))->withErrors(['email' => $outcome === 'blocked'
                    ? __('Too many tries. Please try again in 15 minutes.')
                    : __('The email or the password is not right.')], 'login');
        }
        [$target, $cookie] = $this->signedIn($request, $customer);

        return redirect()->to($target)->withHeaders(AccountController::HEADERS)->withCookie($cookie);
    }

    /** «نسيت الباسورد؟»: the same answer whether the email has an account here or not. */
    public function forgot(Request $request, PasswordSignIn $passwords): RedirectResponse
    {
        $data = $request->validateWithBag('login', ['email' => 'required|string|email:rfc|max:190']);
        $email = GuestIdentity::normalizeEmail($data['email']);
        $outcome = $email ? $passwords->forgot($email, (string) $request->ip()) : 'sent';
        $back = redirect()->route('menu.account.sign-in', ['tab' => 'login'])->withHeaders(AccountController::HEADERS)->withInput($request->only('email'));

        return $outcome === 'blocked'
            ? $back->withErrors(['email' => __('Too many tries. Please try again in a few minutes.')], 'login')
            : $back->with('ok', __('If this email has an account here, a link to choose a new password is on its way.'));
    }

    public function resetForm(Request $request, string $token, PasswordSignIn $passwords): Response
    {
        $request->attributes->set('noindex', true);
        $valid = $passwords->pendingReset($token) !== null;

        return response()->view('menu.account-reset', ['settings' => Settings::all(), 'token' => $token, 'valid' => $valid],
            $valid ? 200 : 410, AccountController::HEADERS);
    }

    public function reset(Request $request, string $token, PasswordSignIn $passwords): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(8)]]);
        $customer = $passwords->reset($token, $data['password']);
        if (! $customer) {
            return redirect()->route('menu.account.reset', $token)->withHeaders(AccountController::HEADERS);
        }
        [$target, $cookie] = $this->signedIn($request, $customer);

        return redirect()->to($target)->withHeaders(AccountController::HEADERS)->withCookie($cookie);
    }

    /** «ادعي عملاءك»: «أهلاً {name}، عنوانك محفوظ …» with the choices to finish the account. */
    public function claim(Request $request, string $token, ClaimLinks $claims): Response
    {
        $request->attributes->set('noindex', true);
        $customer = $claims->pending($token);

        return response()->view('menu.account-claim', [
            'settings' => Settings::all(), 'token' => $token, 'customer' => $customer,
            'address' => $customer ? (GuestAccount::addresses($customer)[0] ?? null) : null,
            'google' => GuestOAuth::enabled('google'), 'ref' => $this->ref($request),
        ], $customer ? 200 : 410, AccountController::HEADERS);
    }

    /** The claim with a password: the email typed becomes the guest's sign-in (proven later by any emailed link). */
    public function claimPassword(Request $request, string $token, ClaimLinks $claims): RedirectResponse
    {
        $data = $request->validate([
            'email' => 'required|string|email:rfc|max:190',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $guest = $claims->pending($token);
        $email = GuestIdentity::normalizeEmail($data['email']);
        $back = redirect()->route('menu.account.claim', $token)->withHeaders(AccountController::HEADERS)->withInput($request->only('email'));
        if (! $guest) {
            return $back;
        }
        if ($email === null) {
            return $back->withErrors(['email' => __('Enter a valid email.')]);
        }
        if (Customer::query()->where('email', $email)->whereKeyNot($guest->id)->exists()) {
            return $back->withErrors(['email' => __('This email is on another account here. Use another one, or continue with Google.')]);
        }
        $customer = $claims->redeem($token);
        if (! $customer) {
            return $back;
        }
        $customer->forceFill(['email' => $email, 'password' => $data['password'],
            'email_verified_at' => $customer->email === $email ? $customer->email_verified_at : null])->save();

        return redirect()->to($this->claimTarget($customer, $request))->withHeaders(AccountController::HEADERS)
            ->withCookie(GuestAccount::rememberCookie(GuestAccount::token($customer)));
    }

    /** The claim with Google: this phone opens the guest now, and the Google account is linked when it comes back. */
    public function claimGoogle(Request $request, string $token, ClaimLinks $claims): RedirectResponse
    {
        abort_unless(GuestOAuth::enabled('google'), 404);
        $customer = $claims->redeem($token);
        if (! $customer) {
            return redirect()->route('menu.account.claim', $token)->withHeaders(AccountController::HEADERS);
        }
        $request->session()->put(GuestSignInController::LINK_GOOGLE_KEY.tenant()->getTenantKey(), $customer->id);

        return redirect()->route('menu.account.google')->withHeaders(AccountController::HEADERS)
            ->withCookie(GuestAccount::rememberCookie(GuestAccount::token($customer)));
    }

    /**
     * A new account whose mobile is another guest's here (pending_phone): a sign-in link goes to the email that guest
     * already has; opening it in this browser folds the new account into that guest.
     */
    public function proveByOldEmail(Request $request, EmailSignIn $emails): RedirectResponse
    {
        $me = GuestAccount::fromRequest($request);
        if (! $me || $me->pending_phone === null) {
            return redirect()->route('menu.show')->withHeaders(AccountController::HEADERS);
        }
        $owner = Customer::query()->where('phone', $me->pending_phone)->first();
        $email = GuestIdentity::normalizeEmail($owner?->email);
        $back = redirect()->to(GuestAccount::url($me).'#phone')->withHeaders(AccountController::HEADERS);
        if (! $email) {
            return $back->with('ok', __('That account has no email with us. Confirm the mobile with WhatsApp or a previous order number.'));
        }
        if ($emails->start($email, (string) $request->ip()) === 'blocked') {
            return $back->with('ok', __('Too many tries. Please try again in a few minutes.'));
        }

        return $back->with('ok', __('We sent a sign-in link to :email. Open it on this phone to join the two accounts.', ['email' => self::maskEmail($email)]));
    }

    /** mona.adel@gmail.com → mo•••@gmail.com */
    public static function maskEmail(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($user, 0, 2).'•••@'.$domain;
    }

    /**
     * The phone now remembers $customer; a bare account this browser had (Google or email only) folds into them.
     *
     * @return array{0:string, 1:Cookie}
     */
    private function signedIn(Request $request, Customer $customer): array
    {
        GuestIdentity::adopt(GuestAccount::fromRequest($request), $customer);
        $customer->refresh();
        $target = $customer->phone === '' ? route('menu.account.phone') : GuestAccount::url($customer);

        return [$target, GuestAccount::rememberCookie(GuestAccount::token($customer))];
    }

    private function claimTarget(Customer $customer, Request $request): string
    {
        $ref = $this->ref($request);

        return $ref ? route('menu.show', ['ref' => $ref]) : GuestAccount::url($customer);
    }

    private function ref(Request $request): ?string
    {
        $ref = $request->query('ref', $request->input('ref'));

        return is_string($ref) && preg_match('/^[a-f0-9]{10}$/', $ref) === 1 ? $ref : null;
    }
}
