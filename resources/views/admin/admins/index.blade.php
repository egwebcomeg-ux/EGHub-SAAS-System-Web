@extends('layouts.admin')
@section('title', __('Admin team') . ' · EgMenu Admin')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">{{ __('Admin team') }}</h1>
        <p class="muted">{{ __('People who can sign in to this panel, and what each one may do.') }}</p>
    </div>
    <a href="{{ route('admin.admins.create') }}" class="btn-primary">{{ __('Add a team member') }}</a>
</div>

<div class="grid gap-4 xl:grid-cols-3">
    <section class="card xl:col-span-2">
        <ul class="divide-y divide-navy-50" data-admins>
            @foreach ($admins as $member)
                <li class="flex flex-wrap items-center gap-3 py-3" data-admin="{{ $member->id }}">
                    <span @class(['grid size-11 shrink-0 place-items-center rounded-full text-sm font-bold', 'bg-navy-800 text-white' => $member->is_active, 'bg-navy-100 text-navy-400' => ! $member->is_active])>{{ mb_substr($member->name, 0, 1) }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-bold">{{ $member->name }}</span>
                            @if ($member->is($me))<span class="badge bg-navy-100 text-navy-600">{{ __('You') }}</span>@endif
                            <span @class(['badge', 'bg-brand-100 text-brand-700' => $member->isOwner(), 'bg-navy-100 text-navy-700' => ! $member->isOwner()])>{{ $member->roleName() }}</span>
                            @if (! empty($member->permissions))<span class="badge bg-navy-50 text-navy-600">+{{ count($member->permissions) }} {{ __('extra') }}</span>@endif
                            @unless ($member->is_active)<span class="badge bg-navy-200 text-navy-700">{{ __('Turned off') }}</span>@endunless
                            @if ($member->hasTwoFactorEnabled())<span class="badge bg-emerald-100 text-emerald-800">✓ {{ __('Two-factor') }}</span>@endif
                        </div>
                        <div class="truncate text-sm text-navy-500"><span dir="ltr">{{ $member->email }}</span> · {{ __('Last login') }}: {{ local_time($member->last_login_at) }}</div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if (! $member->isOwner() || $me->isOwner())
                            <a href="{{ route('admin.admins.edit', $member) }}" class="btn-ghost min-h-9 px-3 text-xs">{{ __('Edit') }}</a>
                        @endif
                        @if (! $member->is($me) && (! $member->isOwner() || $me->isOwner()))
                            <form method="post" action="{{ route('admin.admins.reset-link', $member) }}">@csrf<button class="btn-ghost min-h-9 px-3 text-xs">{{ __('Email a reset link') }}</button></form>
                            <form method="post" action="{{ route('admin.admins.delete', $member) }}" onsubmit="return confirm(@js(__('Remove :name from the team? They will not be able to sign in again.', ['name' => $member->name])))">@csrf<button class="btn min-h-9 px-3 text-xs text-red-700 hover:bg-red-50">{{ __('Remove') }}</button></form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    <aside class="card">
        <h2 class="section-title mb-2">{{ __('Roles') }}</h2>
        <ul class="space-y-3 text-sm">
            @foreach (\App\Support\AdminPermissions::ROLES as $key => $role)
                <li>
                    <div class="font-bold">{{ \App\Support\AdminPermissions::roleLabel($key) }}</div>
                    <div class="text-navy-500">{{ \App\Support\AdminPermissions::roleHint($key) }}</div>
                </li>
            @endforeach
        </ul>
        <p class="mt-3 text-xs text-navy-500">{{ __('Each member can also get extra permissions on top of the role. Two-factor stays personal: every member turns it on from Security.') }}</p>
    </aside>
</div>
@endsection
