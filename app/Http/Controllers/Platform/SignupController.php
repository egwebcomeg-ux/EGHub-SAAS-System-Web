<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewSignup;
use App\Services\Auth\SocialProviders;
use App\Services\Crm\ProspectLinker;
use App\Services\MenuSetupRequest;
use App\Services\ModuleChoice;
use App\Services\TenantProvisioner;
use App\Support\BusinessType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Self-service signup on the main domain: creates the restaurant with a 14-day Complete trial, then hands the owner
 * to the dashboard host through a one-time signed link (sessions are per host, so it can't just log in here).
 * After "Continue with Google / Facebook" found no account, the verified identity arrives as ?social=<token>: the
 * email is then fixed to the provider's verified one and no password is asked (a random one is set).
 */
class SignupController extends Controller
{
    private const SOCIAL_SESSION = 'social_signup';

    public function show(Request $request): View|RedirectResponse
    {
        if ($request->query('social') === 'cancel') {
            $request->session()->forget(self::SOCIAL_SESSION);

            return redirect()->route('register');
        }
        if (is_string($token = $request->query('social')) && $token !== '') {
            $identity = strlen($token) <= 64 ? Cache::pull(SocialProviders::signupKey($token)) : null;
            if (! is_array($identity)) {
                return redirect()->route('register')->withErrors(['email' => __('This sign-up link has expired. Press the Google or Facebook button again.')]);
            }
            $request->session()->put(self::SOCIAL_SESSION, $identity + ['at' => now()->timestamp]);

            return redirect()->route('register');
        }

        return view('landing.register', ['social' => $this->socialIdentity($request)]);
    }

    public function check(Request $request): JsonResponse
    {
        $slug = strtolower(trim((string) $request->query('slug', '')));
        $error = Tenant::slugError($slug);

        return response()->json(['ok' => $error === null, 'message' => $error, 'url' => Tenant::pathUrl($slug)]);
    }

    public function store(Request $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $social = $this->socialIdentity($request);
        $request->merge([
            'slug' => strtolower(trim((string) $request->input('slug'))),
            // the provider's verified email, never one typed over it
            'email' => $social['email'] ?? strtolower(trim((string) $request->input('email'))),
            'phone' => normalize_phone((string) $request->input('phone')) ?? $request->input('phone'),
        ]);
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'slug' => 'required|string|max:30',
            'owner_name' => 'required|string|max:80',
            'email' => 'required|email|max:120|unique:users,email',
            'phone' => ['required', 'regex:/^01[0125]\d{8}$/', 'unique:users,phone'],
            'password' => $social ? 'nullable' : 'required|string|min:8|max:200|confirmed',
            'terms' => 'accepted',
            'business_type' => ['nullable', Rule::in(array_keys(BusinessType::TYPES))],
            'modules' => 'nullable|string|max:600',
        ], [
            'phone.regex' => __('Enter a valid Egyptian mobile number.'),
        ]);
        if ($error = Tenant::slugError($data['slug'])) {
            return back()->withInput($request->except('password', 'password_confirmation'))->withErrors(['slug' => $error]);
        }

        $tenant = $provisioner->create([
            'slug' => $data['slug'], 'name' => $data['name'], 'owner_name' => $data['owner_name'],
            'email' => $data['email'], 'phone' => $data['phone'],
            // signs in with the provider from now on; "Forgot your password?" sets a real one any time
            'password' => $social ? Str::password(40) : $data['password'],
            'business_type' => $data['business_type'] ?? BusinessType::DEFAULT,
        ]);
        $owner = User::query()->where('tenant_id', $tenant->id)->firstOrFail();
        if ($social) {
            $owner->forceFill(['email_verified_at' => now()])->save();
            SocialAccount::query()->create([
                'user_id' => $owner->id, 'provider' => $social['provider'], 'provider_user_id' => $social['id'], 'email' => $social['email'],
            ]);
            $request->session()->forget(self::SOCIAL_SESSION);
        }
        app(MenuSetupRequest::class)->fromSignup($request, $tenant, $owner);
        // «صمم باقتك» picked on the pricing page: the dashboard focuses on those modules and the billing page ticks them
        $modules = array_values(array_filter(array_map('trim', explode(',', (string) ($data['modules'] ?? '')))));
        if ($modules !== []) {
            $tenant->run(fn () => ModuleChoice::save($modules));
        }
        // a place the sales team already worked (same mobile) is now this restaurant
        app(ProspectLinker::class)->link($tenant, $owner->phone);
        // the lead is already in the admin's customer follow-up (Tenant::created): tell whoever calls new sign-ups
        Notification::send(NewSignup::recipients(), new NewSignup($tenant, $owner, $data['business_type'] ?? BusinessType::DEFAULT));

        return redirect()->away(URL::temporarySignedRoute('dash.welcome', now()->addMinutes(5), ['user' => $owner->id]));
    }

    /**
     * The verified identity waiting in this session, while it is fresh and its provider is still switched on.
     *
     * @return array{provider: string, id: string, email: string, name: string, at: int}|null
     */
    private function socialIdentity(Request $request): ?array
    {
        $identity = $request->session()->get(self::SOCIAL_SESSION);
        if (! is_array($identity) || ! SocialProviders::isEnabled((string) ($identity['provider'] ?? ''))
            || ($identity['at'] ?? 0) < now()->subMinutes(SocialProviders::SIGNUP_MINUTES)->timestamp) {
            return null;
        }

        return $identity;
    }
}
