{{--
    One staff member's fields, inside a <form>. The role select carries its hint and permission list (filled by the
    script on the team page from $roleInfo); with the staff_roles feature the extra permissions list follows.
    $u = the user being edited (null for the add form); $canCustomize, $grantable, $grouped from UsersController.
--}}
@php
    $role = $u?->roleKey() ?: 'cashier';
    $direct = $u ? $u->permissions->pluck('name')->all() : [];
@endphp
<div>
    <label class="field-label">{{ __('Name') }}</label>
    <input class="field" name="name" value="{{ $u?->name }}" required maxlength="100">
</div>
<div>
    <label class="field-label">{{ __('Email') }}</label>
    <input class="field" name="email" type="email" value="{{ $u?->email }}" dir="ltr">
</div>
<div>
    <label class="field-label">{{ __('Mobile') }}</label>
    <input class="field" name="phone" value="{{ $u?->phone }}" dir="ltr" placeholder="01xxxxxxxxx">
</div>
<div data-role-field>
    <label class="field-label">{{ __('Role') }}</label>
    <select class="field" name="role" data-role>
        @foreach (array_keys(\App\Support\Permissions::ROLES) as $key)
            <option value="{{ $key }}" @selected($role === $key)>{{ \App\Support\Permissions::roleLabel($key) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs font-semibold text-navy-600" data-role-hint>{{ \App\Support\Permissions::roleHint($role) }}</p>
    <p class="mt-1 text-xs leading-relaxed text-navy-500" data-role-permissions>{{ implode(' · ', array_map(fn (string $p) => \App\Support\Permissions::label($p), \App\Support\Permissions::of($role))) }}</p>
</div>
<div>
    <label class="field-label">{{ __('Branch') }}</label>
    <select class="field" name="branch_id">
        <option value="">{{ __('All branches') }}</option>
        @foreach ($branches as $b)
            <option value="{{ $b->id }}" @selected($u?->branch_id === $b->id)>{{ tr($b->name, $b->name_en) }}</option>
        @endforeach
    </select>
</div>
<div>
    <label class="field-label">{{ $u ? __('New password (optional)') : __('Password') }}</label>
    <input class="field" name="password" type="password" minlength="8" @required(!$u) autocomplete="new-password" dir="ltr">
</div>
@if ($canCustomize ?? false)
    {{-- extra permissions on top of the role (hidden for the owner role, who has everything) --}}
    {{-- the role whose permissions are ticked and locked below when the form is sent (kept by the team page script);
         UsersController never saves those as extras --}}
    <input type="hidden" name="permissions_role" value="{{ $role }}" data-permissions-role>
    <details class="rounded-xl border border-navy-100 p-2" data-extra-permissions @if (count($direct) > 0) open @endif>
        <summary class="cursor-pointer text-sm font-semibold">
            {{ __('Extra permissions') }} <span class="text-navy-500" data-extra-count>{{ count($direct) ? '('.count($direct).')' : '' }}</span>
        </summary>
        <p class="mt-1 text-xs text-navy-500">{{ __('Ticked and greyed out = included in the role. Tick more to give this member extra permissions.') }}</p>
        <div class="mt-2 max-h-72 space-y-3 overflow-y-auto pe-1">
            @foreach ($grouped as $group => $g)
                <fieldset>
                    <legend class="mb-1 text-xs font-bold text-navy-700">{{ $g['label'] }}</legend>
                    <div class="space-y-1">
                        @foreach ($g['permissions'] as $key)
                            @php($isGrantable = in_array($key, $grantable, true))
                            <label class="flex min-h-9 items-center gap-2 rounded-lg px-1 text-sm has-[:disabled]:text-navy-400">
                                <input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(in_array($key, $direct, true)) @disabled(! $isGrantable)
                                       data-permission="{{ $key }}" data-grantable="{{ $isGrantable ? '1' : '0' }}" class="size-4 accent-brand-600">
                                <span>{{ \App\Support\Permissions::label($key) }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
        </div>
    </details>
@endif
