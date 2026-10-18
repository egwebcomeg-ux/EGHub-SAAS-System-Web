@extends('layouts.admin')
@section('title', ($member->exists ? __('Edit team member') : __('Add a team member')) . ' · EgMenu Admin')

@use('App\Support\AdminPermissions')

@section('content')
@php
    $mine = $me->grantedPermissions();
    // roles and permissions this admin may hand out (an owner: all; others: only what they hold themselves)
    $roles = array_filter(array_keys(AdminPermissions::ROLES), fn ($key) => $me->isOwner() || ($key !== 'owner' && array_diff(AdminPermissions::of($key), $mine) === []));
    $rolePermissions = collect(AdminPermissions::ROLES)->keys()->mapWithKeys(fn ($key) => [$key => AdminPermissions::of($key)]);
    $role = old('role', $member->role);
    $ticked = old('permissions', $member->exists ? $member->grantedPermissions() : AdminPermissions::of($role));
    $isSelf = $member->exists && $member->is($me);
@endphp
<a href="{{ route('admin.admins') }}" class="text-sm font-semibold text-navy-500 hover:text-navy-800">← {{ __('Admin team') }}</a>
<h1 class="page-title mt-1 mb-4">{{ $member->exists ? __('Edit team member') : __('Add a team member') }}</h1>

<form method="post" action="{{ $member->exists ? route('admin.admins.update', $member) : route('admin.admins.store') }}" class="space-y-4" data-admin-form data-role-permissions='@json($rolePermissions)'>
    @csrf
    <div class="card grid gap-4 sm:grid-cols-2">
        <div><label class="field-label" for="m-name">{{ __('Name') }}</label><input class="field" id="m-name" name="name" value="{{ old('name', $member->name) }}" required maxlength="100"></div>
        <div><label class="field-label" for="m-email">{{ __('Email') }}</label><input class="field" id="m-email" name="email" type="email" value="{{ old('email', $member->email) }}" required maxlength="120" dir="ltr"></div>
        @if ($member->exists)
            <label class="flex items-center gap-2 text-sm font-semibold sm:col-span-2">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $member->is_active)) @disabled($isSelf) class="size-5 accent-brand-600">
                {{ __('Can sign in (turn off to block this member without deleting them)') }}
            </label>
            @if ($isSelf)<input type="hidden" name="is_active" value="1">@endif
        @endif
    </div>

    <section class="card">
        <h2 class="section-title mb-3">{{ __('Role') }}</h2>
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (AdminPermissions::ROLES as $key => $definition)
                @php($allowedRole = in_array($key, $roles, true) && (! $isSelf || $key === $member->role))
                <label @class(['flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50', 'border-navy-100 hover:border-navy-300' => $allowedRole, 'cursor-not-allowed border-navy-50 opacity-50' => ! $allowedRole])>
                    <input type="radio" name="role" value="{{ $key }}" @checked($role === $key) @disabled(! $allowedRole) class="mt-1 size-4 accent-brand-600" data-role>
                    <span>
                        <span class="block font-bold">{{ AdminPermissions::roleLabel($key) }}</span>
                        <span class="block text-xs text-navy-500">{{ AdminPermissions::roleHint($key) }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        @if ($isSelf)<p class="mt-2 text-xs text-navy-500">{{ __('You cannot change your own role.') }}</p>@endif
    </section>

    <section class="card">
        <h2 class="section-title">{{ __('Permissions') }}</h2>
        <p class="muted mb-3">{{ __('Ticked and greyed out = included in the role. Tick more to give this member extra permissions.') }}</p>
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (AdminPermissions::ALL as $key => $_)
                @php($grantable = $me->isOwner() || in_array($key, $mine, true))
                <label class="flex min-h-11 items-center gap-2 rounded-xl border border-navy-100 px-3 text-sm has-[:disabled]:bg-navy-50/60">
                    <input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(in_array($key, $ticked, true)) @disabled(! $grantable) data-permission="{{ $key }}" data-grantable="{{ $grantable ? '1' : '0' }}" class="size-4 accent-brand-600">
                    <span>{{ AdminPermissions::label($key) }}</span>
                </label>
            @endforeach
        </div>
    </section>

    @unless ($member->exists)
        <section class="card space-y-3">
            <h2 class="section-title">{{ __('First sign-in') }}</h2>
            <label class="flex items-start gap-3 text-sm">
                <input type="radio" name="access" value="link" @checked(old('access', 'link') === 'link') class="mt-1 size-4 accent-brand-600" data-access>
                <span><span class="block font-bold">{{ __('Email a link to set their password') }}</span><span class="text-navy-500">{{ __('They get a link (valid 60 minutes) and choose their own password.') }}</span></span>
            </label>
            <label class="flex items-start gap-3 text-sm">
                <input type="radio" name="access" value="password" @checked(old('access') === 'password') class="mt-1 size-4 accent-brand-600" data-access>
                <span><span class="block font-bold">{{ __('Set a temporary password now') }}</span><span class="text-navy-500">{{ __('At least 10 characters. Send it to them privately.') }}</span></span>
            </label>
            <div data-password-field @class(['hidden' => old('access') !== 'password'])>
                <label class="field-label" for="m-password">{{ __('Temporary password') }}</label>
                <input class="field sm:max-w-sm" id="m-password" name="password" type="text" minlength="10" maxlength="200" dir="ltr" autocomplete="new-password">
            </div>
        </section>
    @endunless

    <div class="sticky bottom-0 z-10 -mx-4 flex flex-wrap gap-2 border-t border-navy-100 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border">
        <button class="btn-primary">{{ $member->exists ? __('Save') : __('Add member') }}</button>
        <a href="{{ route('admin.admins') }}" class="btn-ghost">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection

@push('scripts')
<script>
    (() => {
        const form = document.querySelector('[data-admin-form]');
        const byRole = JSON.parse(form.dataset.rolePermissions);
        // permissions of the picked role are ticked and locked; the others stay free (extra permissions)
        const sync = () => {
            const role = form.querySelector('[data-role]:checked')?.value;
            form.querySelectorAll('[data-permission]').forEach((box) => {
                const inRole = (byRole[role] || []).includes(box.dataset.permission);
                if (inRole) { box.checked = true; }
                box.disabled = inRole || box.dataset.grantable !== '1';
            });
        };
        form.querySelectorAll('[data-role]').forEach((r) => r.addEventListener('change', sync));
        sync();
        form.querySelectorAll('[data-access]').forEach((r) => r.addEventListener('change', () => {
            form.querySelector('[data-password-field]').classList.toggle('hidden', form.querySelector('[data-access]:checked').value !== 'password');
        }));
    })();
</script>
@endpush
