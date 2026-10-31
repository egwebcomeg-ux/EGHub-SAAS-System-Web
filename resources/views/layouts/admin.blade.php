@extends('layouts.base')

@php
    /** @var \App\Models\PlatformAdmin $admin */
    $admin = auth('admin')->user();
    // the sidebar by area of work, only what this admin may open, and the counters of what waits (App\Support\AdminMenu)
    $groups = \App\Support\AdminMenu::groups($admin);
    $badges = \App\Support\AdminMenu::badges($admin);
    $canSearch = $admin->allows('tenants.view') || $admin->allows('billing.manage') || $admin->allows('crm.view');
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
            {{-- on a phone the search takes the title's place (each page repeats its title as its heading) --}}
            <div @class(['min-w-0 flex-1 truncate text-base font-extrabold text-navy-900', 'max-sm:hidden' => $canSearch]) data-admin-title>{{ $pageTitle }}</div>
            @if ($canSearch)
                {{-- one search for restaurants, invoices and sales places (each by the admin's permissions); "/" focuses it --}}
                <div class="relative min-w-0 flex-1 sm:w-72 sm:flex-none" data-admin-search data-url="{{ route('admin.search') }}">
                    <label class="sr-only" for="admin-search">{{ __('Search') }}</label>
                    <svg class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-navy-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.3-4.3"/></svg>
                    <input id="admin-search" type="search" autocomplete="off" maxlength="100" class="field min-h-11 ps-9 pe-9 text-sm"
                        placeholder="{{ __('Search restaurants, invoices, places') }}" role="combobox" aria-expanded="false" aria-controls="admin-search-results" aria-autocomplete="list">
                    <kbd class="pointer-events-none absolute end-3 top-1/2 hidden -translate-y-1/2 rounded border border-navy-200 px-1.5 text-[11px] text-navy-400 sm:block">/</kbd>
                    <div id="admin-search-results" role="listbox" class="absolute end-0 z-40 mt-2 hidden max-h-[70vh] w-[min(26rem,calc(100vw-2rem))] overflow-y-auto rounded-2xl border border-navy-100 bg-white p-2 shadow-lg" data-admin-search-results></div>
                </div>
            @endif
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

        // the header search: results as you type (App\Http\Controllers\Admin\SearchController), arrows + Enter, "/" to focus
        const search = document.querySelector('[data-admin-search]');
        if (search) {
            const input = search.querySelector('input');
            const box = search.querySelector('[data-admin-search-results]');
            const texts = @json(['empty' => __('Nothing found.'), 'searching' => __('Searching…')]);
            let timer = null, active = -1, seq = 0;
            const links = () => [...box.querySelectorAll('a')];
            const show = (open) => { box.classList.toggle('hidden', !open); input.setAttribute('aria-expanded', open ? 'true' : 'false'); };
            const mark = (i) => {
                const all = links();
                active = all.length ? (i + all.length) % all.length : -1;
                all.forEach((a, n) => { a.classList.toggle('bg-navy-50', n === active); a.setAttribute('aria-selected', n === active ? 'true' : 'false'); });
                all[active]?.scrollIntoView({ block: 'nearest' });
            };
            const row = (text, cls) => { const d = document.createElement('div'); d.className = cls; d.textContent = text; return d; };
            const render = (groups) => {
                box.replaceChildren();
                active = -1;
                if (!groups.length) { box.append(row(texts.empty, 'px-3 py-4 text-center text-sm text-navy-500')); return; }
                for (const g of groups) {
                    box.append(row(g.label, 'px-3 pt-2 pb-1 text-[11px] font-bold text-navy-400'));
                    for (const it of g.items) {
                        const a = document.createElement('a');
                        a.href = it.url;
                        a.setAttribute('role', 'option');
                        a.className = 'flex min-h-11 flex-col justify-center rounded-xl px-3 py-1.5 hover:bg-navy-50';
                        a.append(row(it.title, 'truncate text-sm font-bold text-navy-900'));
                        if (it.sub) a.append(row(it.sub, 'truncate text-xs text-navy-500'));
                        box.append(a);
                    }
                }
            };
            const run = async () => {
                const q = input.value.trim();
                if (q.length < 2) { show(false); return; }
                const mine = ++seq;
                box.replaceChildren(row(texts.searching, 'px-3 py-4 text-center text-sm text-navy-500'));
                show(true);
                try {
                    const res = await fetch(search.dataset.url + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    const data = await res.json();
                    if (mine === seq) render(data.groups || []);
                } catch { if (mine === seq) render([]); }
            };
            input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 250); });
            input.addEventListener('focus', () => { if (input.value.trim().length >= 2 && box.childElementCount) show(true); });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowDown') { e.preventDefault(); mark(active + 1); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); mark(active - 1); }
                else if (e.key === 'Enter') { const a = links()[active < 0 ? 0 : active]; if (a) { e.preventDefault(); location.href = a.href; } }
                else if (e.key === 'Escape') { show(false); input.blur(); }
            });
            document.addEventListener('click', (e) => { if (!search.contains(e.target)) show(false); });
            document.addEventListener('keydown', (e) => {
                const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName || '') || document.activeElement?.isContentEditable;
                if (e.key === '/' && !typing && !e.ctrlKey && !e.metaKey) { e.preventDefault(); input.focus(); input.select(); }
            });
        }
    })();
</script>
@endpush
