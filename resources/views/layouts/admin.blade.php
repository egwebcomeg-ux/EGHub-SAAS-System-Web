@extends('layouts.base')

@php
    /** @var \App\Models\PlatformAdmin $admin */
    $admin = auth('admin')->user();
    // groups of [route, label, svg path, permission, active route patterns, badge]; items the admin may not open are hidden
    $groups = [
        [null, [
            ['admin.home', __('Overview'), 'M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z', null, ['admin.home'], null],
        ]],
        [__('Restaurants & billing'), [
            ['admin.tenants', __('Restaurants'), 'M3 21h18M5 21V8l7-5 7 5v13M9 21v-6h6v6M9 11h.01M15 11h.01', 'tenants.view', ['admin.tenants*'], null],
            ['admin.crm', __('Customer follow-up'), 'M3 18v-6a9 9 0 0 1 18 0v6M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z', 'crm.view', ['admin.crm*'], 'crm'],
            ['admin.invoices', __('Invoices'), 'M6 2h9l5 5v15H6zM14 2v6h6M9 13h6M9 17h6', 'billing.manage', ['admin.invoices*'], 'invoices'],
            ['admin.plans', __('Plans & add-ons'), 'M21 8l-9-5-9 5 9 5 9-5zM3 13l9 5 9-5M3 18l9 5 9-5', 'plans.manage', ['admin.plans*', 'admin.addons*', 'admin.prices*'], null],
        ]],
        [__('Growth & content'), [
            ['admin.services', __('Service requests'), 'M4 4h16v12H5.2L4 17.2zM8 9h8M8 12h5', 'services.manage', ['admin.services*'], 'services'],
            ['admin.testimonials', __('Testimonials'), 'M12 2.5l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4l-5.9 3.1 1.2-6.5L2.5 9.4l6.6-.9z', 'content.manage', ['admin.testimonials*'], null],
            ['admin.blog', __('Blog'), 'M4 4h12l4 4v12H4zM8 9h6M8 13h8M8 17h5', 'content.manage', ['admin.blog*'], null],
            ['admin.assistant', __('Assistant'), 'M12 3a8 8 0 0 1 8 8c0 4.4-3.6 8-8 8H5l1.5-2.6A8 8 0 0 1 12 3zM9 11h.01M12 11h.01M15 11h.01', 'assistant.view', ['admin.assistant*'], null],
        ]],
        [__('Settings'), [
            ['admin.settings', __('Payment settings'), 'M3 6h18v12H3zM3 10h18M7 15h3', 'settings.manage', ['admin.settings*'], null],
            ['admin.branding', __('Branding & look'), 'M12 22a10 10 0 1 1 10-10c0 2.2-1.8 3-3.5 3H16a2 2 0 0 0-1.5 3.3A2 2 0 0 1 12 22zM7.5 11.5h.01M10.5 7.5h.01M15.5 8.5h.01', 'settings.manage', ['admin.branding*'], null],
            ['admin.admins', __('Admin team'), 'M17 20v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2M10 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM21 20v-2a4 4 0 0 0-3-3.9M16 2.1a4 4 0 0 1 0 7.8', 'admins.manage', ['admin.admins*'], null],
            ['admin.security', __('Security'), 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10zM9 12l2 2 4-4', null, ['admin.security*'], null],
        ]],
    ];
    $groups = array_values(array_filter(array_map(
        fn ($g) => [$g[0], array_values(array_filter($g[1], fn ($i) => $i[3] === null || $admin->allows($i[3])))], $groups), fn ($g) => $g[1] !== []));
    // what waits for the team, as small counters next to the links
    $badges = [
        'invoices' => $admin->allows('billing.manage') ? \App\Models\Invoice::query()->where('status', 'open')->count() : 0,
        'services' => $admin->allows('services.manage') ? \App\Models\ServiceRequest::query()->where('status', 'new')->count() : 0,
        // follow-up calls due today or overdue
        'crm' => $admin->allows('crm.view') ? \App\Models\Lead::query()->open()->where('crm_leads.next_follow_up_at', '<', \App\Models\Lead::todayStart()->addDay())->count() : 0,
    ];
    $pageTitle = trim(\Illuminate\Support\Str::before(trim($__env->yieldContent('title')), ' · ')) ?: __('Overview');
    $initials = collect(preg_split('/\s+/u', trim($admin->name)))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');
@endphp

@section('body_class', 'bg-navy-50 text-navy-900')

@section('body')
<div class="min-h-screen" data-admin-shell>
    {{-- sidebar: fixed at the start side (right in Arabic), an off-canvas drawer under lg --}}
    <div class="fixed inset-0 z-40 hidden bg-navy-900/50 backdrop-blur-[1px] lg:hidden" data-admin-backdrop></div>
    <aside id="admin-sidebar" data-admin-sidebar
        class="fixed inset-y-0 start-0 z-50 flex w-68 max-w-[85vw] flex-col bg-navy-900 text-white transition-transform duration-200 max-lg:translate-x-full max-lg:ltr:-translate-x-full [&.is-open]:translate-x-0 [&.is-open]:shadow-xl">
        <div class="flex items-center justify-between gap-2 px-5 pt-5 pb-4">
            <a href="{{ route('admin.home') }}" class="flex min-w-0 items-center gap-2">
                <x-logo :light="true" :size="28" />
                <span class="badge bg-brand-600 text-white">Admin</span>
            </a>
            <button type="button" class="grid size-10 place-items-center rounded-xl text-white/70 hover:bg-white/10 hover:text-white lg:hidden" data-admin-close aria-label="{{ __('Close menu') }}">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <nav class="flex-1 space-y-5 overflow-y-auto px-3 pb-4 [scrollbar-color:rgba(255,255,255,.15)_transparent] [scrollbar-width:thin]" aria-label="{{ __('Admin menu') }}">
            @foreach ($groups as [$title, $items])
                <div class="space-y-0.5">
                    @if ($title)<div class="px-3 pb-1 text-[11px] font-bold tracking-wide text-white/40">{{ $title }}</div>@endif
                    @foreach ($items as [$route, $label, $icon, $permission, $patterns, $badge])
                        @php($active = request()->routeIs(...$patterns))
                        <a href="{{ route($route) }}" data-nav="{{ $route }}" @if ($active) aria-current="page" @endif @class(['group relative flex min-h-11 items-center gap-3 rounded-xl px-3 text-sm font-semibold transition',
                            'bg-white/10 text-white' => $active, 'text-white/70 hover:bg-white/5 hover:text-white' => !$active])>
                            @if ($active)<span class="absolute inset-y-2 start-0 w-1 rounded-full bg-brand-600"></span>@endif
                            <svg @class(['size-5 shrink-0', 'text-brand-500' => $active]) viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icon }}"/></svg>
                            <span class="truncate">{{ $label }}</span>
                            @if ($badge && ($badges[$badge] ?? 0) > 0)
                                <span class="ms-auto rounded-full bg-brand-600 px-2 text-xs leading-5 text-white">{{ $badges[$badge] }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endforeach
        </nav>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-white/10 px-5 py-4 text-xs text-white/50">
            <span>{{ \App\Support\PlatformBrand::siteName() }}</span>
            <x-egweb-badge tone="dark" />
        </div>
    </aside>

    <div class="flex min-h-screen min-w-0 flex-col lg:ps-68">
        <header class="sticky top-0 z-30 flex items-center gap-3 border-b border-navy-100 bg-white/95 px-4 py-2.5 backdrop-blur sm:px-6">
            <button type="button" class="grid size-11 shrink-0 place-items-center rounded-xl border border-navy-100 text-navy-800 hover:bg-navy-50 lg:hidden" data-admin-open aria-controls="admin-sidebar" aria-expanded="false" aria-label="{{ __('Open menu') }}">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <div class="min-w-0 flex-1 truncate text-base font-extrabold text-navy-900" data-admin-title>{{ $pageTitle }}</div>
            <a class="hidden min-h-11 items-center rounded-xl px-3 text-sm font-semibold text-navy-600 hover:bg-navy-50 sm:inline-flex" href="?lang={{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">{{ app()->getLocale() === 'ar' ? 'English' : 'عربي' }}</a>
            <details class="relative" data-admin-account>
                <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-xl px-1.5 hover:bg-navy-50 [&::-webkit-details-marker]:hidden">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-navy-800 text-sm font-bold text-white">{{ $initials ?: 'A' }}</span>
                    <span class="hidden text-start leading-tight md:block">
                        <span class="block max-w-40 truncate text-sm font-bold">{{ $admin->name }}</span>
                        <span class="block text-xs text-navy-500">{{ $admin->roleName() }}</span>
                    </span>
                    <svg class="hidden size-4 text-navy-400 md:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>
                </summary>
                <div class="absolute end-0 z-40 mt-2 w-64 rounded-2xl border border-navy-100 bg-white p-2 shadow-lg">
                    <div class="border-b border-navy-50 px-3 py-2">
                        <div class="truncate text-sm font-bold">{{ $admin->name }}</div>
                        <div class="truncate text-xs text-navy-500" dir="ltr">{{ $admin->email }}</div>
                        <span class="badge mt-1 bg-navy-100 text-navy-700">{{ $admin->roleName() }}</span>
                    </div>
                    <a href="{{ route('admin.security') }}" class="flex min-h-11 items-center gap-2 rounded-xl px-3 text-sm font-semibold hover:bg-navy-50">{{ __('Security & two-factor') }}</a>
                    <a href="?lang={{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}" class="flex min-h-11 items-center gap-2 rounded-xl px-3 text-sm font-semibold hover:bg-navy-50 sm:hidden">{{ app()->getLocale() === 'ar' ? 'English' : 'عربي' }}</a>
                    <form method="post" action="{{ route('admin.logout') }}">@csrf<button class="flex min-h-11 w-full items-center gap-2 rounded-xl px-3 text-sm font-semibold text-navy-700 hover:bg-navy-50">{{ __('Log out') }}</button></form>
                </div>
            </details>
        </header>
        <main class="mx-auto w-full max-w-7xl min-w-0 flex-1 px-4 py-6 sm:px-6">
            <x-flash />
            @yield('content')
        </main>
    </div>
</div>
@endsection

@push('scripts')
<script>
    (() => {
        const sidebar = document.querySelector('[data-admin-sidebar]');
        const backdrop = document.querySelector('[data-admin-backdrop]');
        const opener = document.querySelector('[data-admin-open]');
        const toggle = (open) => {
            sidebar.classList.toggle('is-open', open);
            backdrop.classList.toggle('hidden', !open);
            opener?.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.documentElement.classList.toggle('max-lg:overflow-hidden', open);
        };
        opener?.addEventListener('click', () => toggle(true));
        backdrop.addEventListener('click', () => toggle(false));
        document.querySelector('[data-admin-close]')?.addEventListener('click', () => toggle(false));
        document.addEventListener('keydown', (e) => e.key === 'Escape' && toggle(false));
        // the account menu closes when clicking elsewhere
        const account = document.querySelector('[data-admin-account]');
        document.addEventListener('click', (e) => { if (account?.open && !account.contains(e.target)) account.open = false; });
    })();
</script>
@endpush
