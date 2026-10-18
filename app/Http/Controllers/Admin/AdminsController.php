<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Support\AdminPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * The platform admin team (admins.manage): add people under you with a role and extra permissions, switch them off,
 * remove them. Guards: nobody removes or switches off themselves, the last active owner always stays, only an owner
 * can give or edit the owner role, and nobody grants a permission they do not have.
 */
class AdminsController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.admins.index', [
            'admins' => PlatformAdmin::query()->orderByDesc('is_active')->orderBy('id')->get(),
            'me' => $request->user('admin'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.admins.form', ['member' => new PlatformAdmin(['role' => 'support']), 'me' => $request->user('admin')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $me = $request->user('admin');
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate($this->rules($me) + [
            'email' => 'required|email|max:120|unique:platform_admins,email',
            'access' => ['required', Rule::in(['link', 'password'])],
            'password' => ['nullable', 'required_if:access,password', 'string', PasswordRule::min(10)],
        ]);

        $member = PlatformAdmin::query()->create([
            'name' => trim($data['name']), 'email' => $data['email'], 'role' => $data['role'],
            'permissions' => $this->extraPermissions($data), 'is_active' => true,
            'password' => $data['access'] === 'password' ? $data['password'] : Str::random(40),
        ]);

        if ($data['access'] === 'link') {
            Password::broker('platform_admins')->sendResetLink(['email' => $member->email]);

            return redirect()->route('admin.admins')->with('ok', __(':name was added. A link to set a password was emailed to :email.', ['name' => $member->name, 'email' => $member->email]));
        }

        return redirect()->route('admin.admins')->with('ok', __(':name was added. Share the temporary password privately; they can change it from "Forgot password".', ['name' => $member->name]));
    }

    public function edit(Request $request, PlatformAdmin $member): View|RedirectResponse
    {
        $me = $request->user('admin');
        if ($member->isOwner() && ! $me->isOwner()) {
            return redirect()->route('admin.admins')->withErrors(['role' => __('Only an owner can change another owner.')]);
        }

        return view('admin.admins.form', ['member' => $member, 'me' => $me]);
    }

    public function update(Request $request, PlatformAdmin $member): RedirectResponse
    {
        $me = $request->user('admin');
        if ($member->isOwner() && ! $me->isOwner()) {
            return back()->withErrors(['role' => __('Only an owner can change another owner.')]);
        }
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate($this->rules($me) + [
            'email' => ['required', 'email', 'max:120', Rule::unique('platform_admins', 'email')->ignore($member->id)],
            'is_active' => 'nullable|boolean',
        ]);
        $active = $request->boolean('is_active');

        if ($member->is($me) && (! $active || $data['role'] !== $member->role)) {
            return back()->withInput()->withErrors(['role' => __('You cannot switch off yourself or change your own role.')]);
        }
        if ($member->isOwner() && $member->is_active && ($data['role'] !== 'owner' || ! $active) && $this->activeOwners() <= 1) {
            return back()->withInput()->withErrors(['role' => __('This is the last active owner. Make someone else an owner first.')]);
        }

        $member->update([
            'name' => trim($data['name']), 'email' => $data['email'], 'role' => $data['role'],
            'permissions' => $this->extraPermissions($data), 'is_active' => $active,
        ]);
        if (! $active) {
            $member->forceFill(['remember_token' => Str::random(60)])->save();
        }

        return redirect()->route('admin.admins')->with('ok', __('Saved.'));
    }

    public function sendResetLink(PlatformAdmin $member): RedirectResponse
    {
        $status = Password::broker('platform_admins')->sendResetLink(['email' => $member->email]);
        if ($status === Password::RESET_THROTTLED) {
            return back()->withErrors(['email' => __('A reset link was sent a moment ago. Wait a minute before sending another.')]);
        }

        return back()->with('ok', __('A password reset link was emailed to :email.', ['email' => $member->email]));
    }

    public function destroy(Request $request, PlatformAdmin $member): RedirectResponse
    {
        $me = $request->user('admin');
        if ($member->is($me)) {
            return back()->withErrors(['role' => __('You cannot remove yourself.')]);
        }
        if ($member->isOwner() && ! $me->isOwner()) {
            return back()->withErrors(['role' => __('Only an owner can change another owner.')]);
        }
        if ($member->isOwner() && $member->is_active && $this->activeOwners() <= 1) {
            return back()->withErrors(['role' => __('This is the last active owner. Make someone else an owner first.')]);
        }
        $member->delete();

        return redirect()->route('admin.admins')->with('ok', __(':name was removed from the team.', ['name' => $member->name]));
    }

    /**
     * Name, role and extra permissions; a non-owner may only hand out roles and permissions they hold themselves.
     *
     * @return array<string, mixed>
     */
    private function rules(PlatformAdmin $me): array
    {
        $mine = $me->grantedPermissions();
        $roles = array_keys(array_filter(AdminPermissions::ROLES, fn (array $role, string $key) => $me->isOwner()
            || ($key !== 'owner' && array_diff(AdminPermissions::of($key), $mine) === []), ARRAY_FILTER_USE_BOTH));

        return [
            'name' => 'required|string|max:100',
            'role' => ['required', Rule::in($roles)],
            'permissions' => 'array',
            'permissions.*' => ['string', Rule::in($me->isOwner() ? array_keys(AdminPermissions::ALL) : $mine)],
        ];
    }

    /**
     * The ticked permissions the role does not already give.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>|null
     */
    private function extraPermissions(array $data): ?array
    {
        $extra = array_values(array_diff((array) ($data['permissions'] ?? []), AdminPermissions::of($data['role'])));

        return $extra === [] ? null : $extra;
    }

    private function activeOwners(): int
    {
        return PlatformAdmin::query()->active()->where('role', 'owner')->count();
    }
}
