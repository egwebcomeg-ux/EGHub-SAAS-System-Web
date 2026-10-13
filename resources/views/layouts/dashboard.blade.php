@extends('layouts.base')

@php
    $user = auth()->user();
    $sub = tenant()->subscription()->with('plan')->first();
    $nav = [
        ['dash.home', __('Home'), 'M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z', null],
        ['dash.users', __('Users & roles'), 'M16 11a4 4 0 1 0-8 0M4 21a8 8 0 0 1 16 0', 'users.manage'],
    ];
    // Built in the next phases; listed so staff see where the product is going.
    $soon = [__('Menu'), __('Orders'), __('Tables & QR'), __('Kitchen display'), __('POS'), __('Loyalty card'), __('Reports')];
@endphp

@section('body')
<div class="flex min-h-screen">
    <aside class="hidden w-64 shrink-0 flex-col bg-navy-800 text-white lg:flex">
        <div class="px-5 py-5"><x-logo :light="true" /></div>
        <nav class="flex-1 space-y-1 px-3">
            @foreach ($nav as [$route, $label, $icon, $perm])
                @if (!$perm || $user->can($perm))
                    <a href="{{ route($route) }}" @class(['flex min-h-11 items-center gap-3 rounded-xl px-3 text-sm font-semibold transition',
                        'bg-white/10 text-white' => request()->routeIs($route), 'text-white/70 hover:bg-white/5 hover:text-white' => !request()->routeIs($route)])>
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icon }}"/></svg>
                        {{ $label }}
                    </a>
                @endif
            @endforeach
            <div class="px-3 pt-6 pb-2 text-xs font-semibold text-white/40">{{ __('Coming soon') }}</div>
            @foreach ($soon as $label)
                <div class="flex min-h-10 items-center px-3 text-sm text-white/35">{{ $label }}</div>
            @endforeach
        </nav>
        <div class="border-t border-white/10 p-4 text-xs text-white/50">EgMenu · by EgWeb</div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="flex items-center justify-between gap-3 border-b border-navy-100 bg-white px-4 py-3 sm:px-6">
            <div class="min-w-0">
                <div class="truncate font-bold">{{ tenant()->name }}</div>
                <div class="text-xs text-navy-500">{{ $user->name }} · {{ $user->roleName() }}</div>
            </div>
            <div class="flex items-center gap-2">
                @if ($sub)
                    <span class="badge {{ $sub->status === 'trialing' ? 'bg-brand-100 text-brand-700' : 'bg-navy-100 text-navy-700' }}">
                        {{ $sub->plan->displayName() }}@if ($sub->status === 'trialing') · {{ __(':days days left', ['days' => $sub->daysLeft()]) }}@endif
                    </span>
                @endif
                <form method="post" action="{{ route('dash.locale') }}">
                    @csrf
                    <input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
                    <button class="btn-ghost min-h-9 px-3">{{ app()->getLocale() === 'ar' ? 'English' : 'عربي' }}</button>
                </form>
                <form method="post" action="{{ route('dash.logout') }}">
                    @csrf
                    <button class="btn-ghost min-h-9 px-3">{{ __('Log out') }}</button>
                </form>
            </div>
        </header>
        <main class="flex-1 p-4 sm:p-6">
            <x-flash />
            @yield('content')
        </main>
    </div>
</div>
@endsection
