<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MenuSetupRequest;
use App\Services\TenantProvisioner;
use App\Support\BusinessType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Self-service signup on the main domain: creates the restaurant with a 14-day Complete trial, then hands the owner
 * to the dashboard host through a one-time signed link (sessions are per host, so it can't just log in here).
 */
class SignupController extends Controller
{
    public function show(): View
    {
        return view('landing.register');
    }

    public function check(Request $request): JsonResponse
    {
        $slug = strtolower(trim((string) $request->query('slug', '')));
        $error = Tenant::slugError($slug);

        return response()->json(['ok' => $error === null, 'message' => $error, 'url' => Tenant::pathUrl($slug)]);
    }

    public function store(Request $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $request->merge([
            'slug' => strtolower(trim((string) $request->input('slug'))),
            'email' => strtolower(trim((string) $request->input('email'))),
            'phone' => normalize_phone((string) $request->input('phone')) ?? $request->input('phone'),
        ]);
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'slug' => 'required|string|max:30',
            'owner_name' => 'required|string|max:80',
            'email' => 'required|email|max:120|unique:users,email',
            'phone' => ['required', 'regex:/^01[0125]\d{8}$/', 'unique:users,phone'],
            'password' => 'required|string|min:8|max:200|confirmed',
            'terms' => 'accepted',
            'business_type' => ['nullable', Rule::in(array_keys(BusinessType::TYPES))],
        ], [
            'phone.regex' => __('Enter a valid Egyptian mobile number.'),
        ]);
        if ($error = Tenant::slugError($data['slug'])) {
            return back()->withInput($request->except('password', 'password_confirmation'))->withErrors(['slug' => $error]);
        }

        $tenant = $provisioner->create([
            'slug' => $data['slug'], 'name' => $data['name'], 'owner_name' => $data['owner_name'],
            'email' => $data['email'], 'phone' => $data['phone'], 'password' => $data['password'],
            'business_type' => $data['business_type'] ?? BusinessType::DEFAULT,
        ]);
        $owner = User::query()->where('tenant_id', $tenant->id)->firstOrFail();
        app(MenuSetupRequest::class)->fromSignup($request, $tenant, $owner);

        return redirect()->away(URL::temporarySignedRoute('dash.welcome', now()->addMinutes(5), ['user' => $owner->id]));
    }
}
