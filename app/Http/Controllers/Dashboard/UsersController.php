<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Services\Audit;
use App\Services\Entitlements;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Restaurant staff accounts: owner, manager, cashier, call center, kitchen, waiter, driver. With the `staff_roles`
 * plan feature a staff member may also get permissions on top of their role (spatie direct permissions; the
 * owner-only ones never).
 */
class UsersController extends Controller
{
    /** A driver account only makes sense with delivery, so the role needs one of these plan features. */
    public const DRIVER_FEATURE = 'delivery_zones|online_ordering';

    public function index(Request $request): View
    {
        $roles = array_keys(Permissions::ROLES);

        return view('dashboard.users', [
            'users' => User::query()->with('roles', 'branch', 'permissions')->orderBy('id')->get(),
            'branches' => Branch::query()->orderBy('sort_order')->get(),
            'roles' => $roles,
            'canAdd' => Entitlements::current()->canAdd('staff'),
            'canCustomize' => $this->canCustomize(),
            'grantable' => $this->grantableBy($request->user()),
            'grouped' => Permissions::grouped(),
            'roleInfo' => collect($roles)->mapWithKeys(fn (string $role) => [$role => [
                'hint' => Permissions::roleHint($role),
                'permissions' => Permissions::of($role),
                'labels' => array_map(fn (string $p) => Permissions::label($p), Permissions::of($role)),
            ]])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rights = Entitlements::current();
        if (! $rights->canAdd('staff')) {
            return back()->withErrors(['limit' => $rights->upgradeMessage('staff')]);
        }
        $data = $this->validated($request);

        $user = User::query()->create([
            'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'],
            'branch_id' => $data['branch_id'] ?? null, 'password' => $data['password'], 'is_active' => true,
        ]);
        $user->assignRole($data['role']);
        Audit::record('user.created', $user->name, ['id' => $user->id, 'role' => $data['role']]);
        $this->syncExtraPermissions($request, $user, $data);

        return back()->with('ok', __('User added.'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        // Route binding runs inside the tenant scope: another restaurant's user id is a 404 here.
        $data = $this->validated($request, $user);

        if ($user->isOwner() && ($data['role'] !== 'owner' || ! $request->boolean('is_active'))
            && User::role('owner')->where('is_active', true)->count() <= 1) {
            return back()->withErrors(['role' => __('The restaurant must keep at least one active owner.')]);
        }

        $user->fill([
            'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'],
            'branch_id' => $data['branch_id'] ?? null, 'is_active' => $request->boolean('is_active'),
        ]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();
        $user->syncRoles([$data['role']]);
        Audit::record('user.updated', $user->name, ['id' => $user->id, 'role' => $data['role'], 'active' => (bool) $user->is_active]);
        $this->syncExtraPermissions($request, $user, $data);

        return back()->with('ok', __('User updated.'));
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, ?User $user = null): array
    {
        $request->merge([
            'email' => $request->filled('email') ? strtolower(trim((string) $request->input('email'))) : null,
            'phone' => $request->filled('phone') ? (normalize_phone((string) $request->input('phone')) ?? 'invalid') : null,
        ]);
        $rights = Entitlements::current();

        $rules = [
            'name' => 'required|string|max:100',
            'email' => ['nullable', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user?->id), 'required_without:phone'],
            'phone' => ['nullable', 'regex:/^01[0125]\d{8}$/', Rule::unique('users', 'phone')->ignore($user?->id), 'required_without:email'],
            'role' => ['required', Rule::in(array_keys(Permissions::ROLES)),
                // a driver needs delivery in the plan; an existing driver may still be edited as one
                function (string $attribute, mixed $value, \Closure $fail) use ($rights, $user) {
                    if ($value === 'driver' && ! $rights->allows(self::DRIVER_FEATURE) && $user?->roleKey() !== 'driver') {
                        $fail(__('The driver role needs delivery (delivery zones or online ordering) in your plan. Upgrade to add drivers.'));
                    }
                }],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', tenant()->getTenantKey())],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:200'],
        ];
        // extra permissions exist only with the feature; without it the field is ignored, whatever was posted
        if ($this->canCustomize()) {
            $rules['permissions'] = ['nullable', 'array', 'max:'.count(Permissions::ALL)];
            $rules['permissions.*'] = ['string', Rule::in($this->grantableBy($request->user()))];
            $rules['permissions_role'] = ['nullable', 'string', Rule::in(array_keys(Permissions::ROLES))];
        }

        return $request->validate($rules, [
            'permissions.*.in' => __('One of the permissions cannot be granted to staff (it belongs to the owner).'),
        ]);
    }

    /**
     * Stores the permissions ticked beyond the role as direct permissions, so unticking them all puts the member
     * back on the role's defaults. The owner role holds everything already and keeps none. What the new role holds
     * is never an extra, and neither is what the form showed ticked and locked for another role (permissions_role:
     * the role the boxes were drawn for), so switching the role in the form never turns its boxes into extras.
     *
     * @param  array<string,mixed>  $data
     */
    private function syncExtraPermissions(Request $request, User $user, array $data): void
    {
        if (! $this->canCustomize()) {
            return;
        }
        $before = $user->permissions->pluck('name')->sort()->values()->all();
        $shownRole = $data['permissions_role'] ?? null;
        $lockedInForm = is_string($shownRole) && $shownRole !== $data['role'] ? Permissions::of($shownRole) : [];
        $wanted = $data['role'] === 'owner' ? []
            : array_values(array_diff(array_unique((array) ($data['permissions'] ?? [])), Permissions::of($data['role']), $lockedInForm, Permissions::OWNER_ONLY));
        sort($wanted);
        if ($wanted === $before) {
            return;
        }
        $user->syncPermissions($wanted);
        Audit::record('user.permissions', $user->name, [
            'id' => $user->id, 'role' => $data['role'],
            'added' => array_values(array_diff($wanted, $before)), 'removed' => array_values(array_diff($before, $wanted)),
        ]);
    }

    private function canCustomize(): bool
    {
        return Entitlements::current()->allows('staff_roles');
    }

    /**
     * What this user may hand out: the grantable permissions, and (for a non-owner who manages users) only the
     * ones they hold themselves.
     *
     * @return list<string>
     */
    private function grantableBy(User $actor): array
    {
        $grantable = Permissions::grantable();
        if ($actor->isOwner()) {
            return $grantable;
        }
        $mine = $actor->getAllPermissions()->pluck('name')->all();

        return array_values(array_intersect($grantable, $mine));
    }
}
