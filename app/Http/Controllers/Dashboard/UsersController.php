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

/** Restaurant staff accounts: owner, manager, cashier, kitchen, waiter. */
class UsersController extends Controller
{
    public function index(): View
    {
        return view('dashboard.users', [
            'users' => User::query()->with('roles', 'branch')->orderBy('id')->get(),
            'branches' => Branch::query()->orderBy('sort_order')->get(),
            'roles' => array_keys(Permissions::ROLES),
            'canAdd' => Entitlements::current()->canAdd('staff'),
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

        return back()->with('ok', __('User updated.'));
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, ?User $user = null): array
    {
        $request->merge([
            'email' => $request->filled('email') ? strtolower(trim((string) $request->input('email'))) : null,
            'phone' => $request->filled('phone') ? (normalize_phone((string) $request->input('phone')) ?? 'invalid') : null,
        ]);

        return $request->validate([
            'name' => 'required|string|max:100',
            'email' => ['nullable', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user?->id), 'required_without:phone'],
            'phone' => ['nullable', 'regex:/^01[0125]\d{8}$/', Rule::unique('users', 'phone')->ignore($user?->id), 'required_without:email'],
            'role' => ['required', Rule::in(array_keys(Permissions::ROLES))],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', tenant()->getTenantKey())],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:200'],
        ]);
    }
}
