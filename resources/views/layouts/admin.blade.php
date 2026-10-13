@extends('layouts.base')

@section('body')
<div class="min-h-screen">
    <header class="bg-navy-900 text-white">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
            <a href="{{ route('admin.home') }}" class="flex items-center gap-2"><x-logo :light="true" :size="28" /><span class="badge bg-brand-600 text-white">Admin</span></a>
            <nav class="flex flex-1 flex-wrap gap-1 text-sm font-semibold">
                @foreach ([['admin.home', __('Overview')], ['admin.tenants', __('Restaurants')], ['admin.invoices', __('Invoices')], ['admin.plans', __('Plans & features')], ['admin.settings', __('Payment settings')]] as [$route, $label])
                    <a href="{{ route($route) }}" @class(['rounded-lg px-3 py-2', 'bg-white/10' => request()->routeIs($route . '*'), 'text-white/70 hover:text-white' => !request()->routeIs($route . '*')])>{{ $label }}</a>
                @endforeach
            </nav>
            <a class="text-sm text-white/70 hover:text-white" href="?lang={{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">{{ app()->getLocale() === 'ar' ? 'English' : 'عربي' }}</a>
            <form method="post" action="{{ route('admin.logout') }}">@csrf<button class="text-sm text-white/70 hover:text-white">{{ __('Log out') }}</button></form>
        </div>
    </header>
    <main class="mx-auto max-w-7xl px-4 py-6">
        <x-flash />
        @yield('content')
    </main>
</div>
@endsection
