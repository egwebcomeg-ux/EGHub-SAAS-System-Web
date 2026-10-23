@extends('layouts.admin')
@section('title', $tenant->name . ' · EgMenu Admin')

@section('content')
@php
    $sub = $tenant->subscription;
    $me = auth('admin')->user();
    $canManage = $me->allows('tenants.manage');
    $canBilling = $me->allows('billing.manage');
    $temporary = session('temporary_password');
    $actionLabels = [
        'user.password_link_by_platform' => __('Password reset link emailed'),
        'user.password_set_by_platform' => __('Temporary password set'),
        'user.signed_out_by_platform' => __('Signed out everywhere'),
        'two_factor.reset_by_platform' => __('Two-factor reset'),
        'restaurant.info_by_platform' => __('Restaurant info changed'),
        'restaurant.showcase_by_platform' => __('Logo on egmenu.app changed'),
        'restaurant.directory_by_platform' => __('Directory visibility changed'),
    ];
@endphp
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div class="min-w-0">
        <a href="{{ route('admin.tenants') }}" class="text-sm font-semibold text-navy-500 hover:text-navy-800">← {{ __('Restaurants') }}</a>
        <h1 class="page-title mt-1 flex flex-wrap items-center gap-2">
            <span class="break-words">{{ $tenant->name }}</span>
            @if ($tenant->isSuspended())
                <span class="badge bg-navy-200 text-navy-700">{{ __('Suspended') }}</span>
            @elseif ($sub)
                <span class="badge {{ ['trialing' => 'bg-brand-100 text-brand-700', 'active' => 'bg-emerald-100 text-emerald-800', 'past_due' => 'bg-amber-100 text-amber-800'][$sub->status] ?? 'bg-navy-100 text-navy-600' }}">{{ $sub->statusLabel() }}</span>
            @endif
        </h1>
        <a href="{{ $tenant->url() }}" target="_blank" rel="noopener" dir="ltr" class="break-all text-sm text-navy-600 hover:underline">{{ $tenant->url() }}</a>
    </div>
    @if ($canManage)
        @if ($tenant->isSuspended())
            <form method="post" action="{{ route('admin.tenants.activate', $tenant) }}">@csrf<button class="btn-primary">{{ __('Activate') }}</button></form>
        @else
            <form method="post" action="{{ route('admin.tenants.suspend', $tenant) }}" onsubmit="return confirm(@js(__('Take this menu offline?')))">@csrf<button class="btn-danger">{{ __('Suspend') }}</button></form>
        @endif
    @endif
</div>

@if ($temporary)
    <div class="mb-4 rounded-2xl border border-brand-200 bg-brand-50 p-4" role="status" data-temporary-password>
        <div class="font-bold text-navy-900">{{ __('Temporary password for :name', ['name' => $temporary['name']]) }}</div>
        <p class="text-sm text-navy-600">{{ __('Shown only this once. Send it to them privately; they can pick their own from "Forgot password" on the sign-in page.') }}</p>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <code class="rounded-xl border border-brand-200 bg-white px-4 py-2 text-lg font-bold tracking-wider" dir="ltr" data-temp-value>{{ $temporary['password'] }}</code>
            <button type="button" class="btn-ghost" onclick="navigator.clipboard?.writeText(this.previousElementSibling.textContent); this.textContent = @js(__('Copied'))">{{ __('Copy') }}</button>
        </div>
    </div>
@endif

<div class="grid gap-4 lg:grid-cols-3">
    {{-- the restaurant's users: support actions per user --}}
    <section class="card lg:col-span-2" id="users">
        <div class="mb-3 flex items-center justify-between gap-2">
            <h2 class="section-title">{{ __('Users') }}</h2>
            <span class="text-sm text-navy-500">{{ __(':count users', ['count' => $users->count()]) }}</span>
        </div>
        <ul class="divide-y divide-navy-50" data-tenant-users>
            @foreach ($users as $u)
                <li class="py-3" data-user="{{ $u->id }}">
                    <div class="flex flex-wrap items-start gap-3">
                        <span class="grid size-10 shrink-0 place-items-center rounded-full {{ $u->isOwner() ? 'bg-brand-600 text-white' : 'bg-navy-100 text-navy-700' }} text-sm font-bold">{{ mb_substr($u->name, 0, 1) }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-bold">{{ $u->name }}</span>
                                <span class="badge bg-navy-100 text-navy-700">{{ $u->roleName() }}</span>
                                @unless ($u->is_active)<span class="badge bg-navy-200 text-navy-700">{{ __('Turned off') }}</span>@endunless
                                @if ($u->hasTwoFactorEnabled())<span class="badge bg-emerald-100 text-emerald-800">✓ {{ __('Two-factor') }}</span>@endif
                            </div>
                            <div class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-sm text-navy-500">
                                @if ($u->email)<span dir="ltr" class="break-all">{{ $u->email }}</span>@endif
                                @if ($u->phone)
                                    <span class="inline-flex flex-wrap items-center gap-1">
                                        <span dir="ltr">{{ $u->phone }}</span>
                                        @if ($u->hasVerifiedPhone())
                                            <span class="badge bg-emerald-100 text-emerald-800" title="{{ local_time($u->phone_verified_at) }}">✓ {{ __('Phone confirmed') }}</span>
                                        @else
                                            <span class="badge bg-amber-100 text-amber-800">{{ __('Not confirmed') }}</span>
                                            @if ($u->phone_verification_code)
                                                <code class="rounded bg-navy-50 px-1.5 py-0.5 text-xs" dir="ltr" title="{{ __('Pending code') }}">{{ \App\Services\WhatsApp\PhoneVerification::message($u->phone_verification_code) }}</code>
                                            @endif
                                        @endif
                                    </span>
                                @endif
                                <span>{{ __('Last login') }}: {{ local_time($u->last_login_at) }}</span>
                            </div>
                        </div>
                    </div>
                    @if ($canManage)
                        <div class="mt-2 flex flex-wrap gap-2 ps-13">
                            @if ($u->email)
                                <form method="post" action="{{ route('admin.tenants.users.reset-link', [$tenant, $u->id]) }}">@csrf<button class="btn-ghost min-h-9 px-3 text-xs">{{ __('Email a reset link') }}</button></form>
                            @endif
                            <form method="post" action="{{ route('admin.tenants.users.password', [$tenant, $u->id]) }}" onsubmit="return confirm(@js(__('Set a new temporary password for :name? Their current password stops working and they are signed out.', ['name' => $u->name])))">@csrf<button class="btn-ghost min-h-9 px-3 text-xs">{{ __('Set temporary password') }}</button></form>
                            <form method="post" action="{{ route('admin.tenants.users.sign-out', [$tenant, $u->id]) }}" onsubmit="return confirm(@js(__('Sign :name out on every device?', ['name' => $u->name])))">@csrf<button class="btn-ghost min-h-9 px-3 text-xs">{{ __('Sign out everywhere') }}</button></form>
                            @if ($u->hasTwoFactorEnabled())
                                <form method="post" action="{{ route('admin.tenants.reset-2fa', [$tenant, $u->id]) }}" onsubmit="return confirm(@js(__('Reset two-factor authentication for this user? They will sign in with their password only until they set it up again.')))">@csrf<button class="btn-ghost min-h-9 px-3 text-xs">{{ __('Reset 2FA') }}</button></form>
                            @endif
                            @if ($u->phone && ! $u->hasVerifiedPhone())
                                <form method="post" action="{{ route('admin.tenants.verify-phone', [$tenant, $u->id]) }}">@csrf<button class="btn-ghost min-h-9 px-3 text-xs">{{ __('Mark as confirmed') }}</button></form>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    <div class="space-y-4">
        @if ($canManage)
            <form method="post" action="{{ route('admin.tenants.info', $tenant) }}" class="card space-y-3" data-tenant-info>
                @csrf
                <h2 class="section-title">{{ __('Restaurant info') }}</h2>
                <div><label class="field-label" for="t-name">{{ __('Name') }}</label><input class="field" id="t-name" name="name" value="{{ old('name', $tenant->name) }}" required maxlength="100"></div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                    <div><label class="field-label" for="t-phone">{{ __('Phone') }}</label><input class="field" id="t-phone" name="phone" value="{{ old('phone', $contact['phone']) }}" dir="ltr" maxlength="20"></div>
                    <div><label class="field-label" for="t-wa">{{ __('WhatsApp') }}</label><input class="field" id="t-wa" name="whatsapp" value="{{ old('whatsapp', $contact['whatsapp']) }}" dir="ltr" maxlength="20"></div>
                </div>
                <div class="text-xs text-navy-500">{{ __('Link') }}: <span dir="ltr">/{{ $tenant->id }}</span> · {{ __('Created') }} {{ local_time($tenant->created_at, 'Y/m/d') }}</div>
                <button class="btn-primary">{{ __('Save') }}</button>
            </form>

            {{-- the moving logo strip on the egmenu.app home page (App\Services\Seo\SiteShowcase) --}}
            <form method="post" action="{{ route('admin.tenants.showcase', $tenant) }}" class="card space-y-3" data-tenant-showcase>
                @csrf
                <h2 class="section-title">{{ __('Logo on egmenu.app') }}</h2>
                <div class="flex items-center gap-3">
                    @if ($logo)
                        <img src="{{ $logo }}" alt="{{ $tenant->name }}" width="96" height="48" loading="lazy" class="h-12 w-24 shrink-0 rounded-lg border border-navy-100 bg-white object-contain p-1">
                    @else
                        <span class="badge bg-amber-100 text-amber-800" data-showcase-no-logo>{{ __('No logo yet: it will not show until the restaurant uploads one.') }}</span>
                    @endif
                </div>
                <label class="flex min-h-11 items-center gap-3 text-sm font-semibold">
                    <input type="hidden" name="showcase_on_site" value="0">
                    <input type="checkbox" name="showcase_on_site" value="1" class="size-5 accent-brand-600" @checked(old('showcase_on_site', $tenant->showcase_on_site))>
                    {{ __('Show the logo on egmenu.app') }}
                </label>
                <p class="text-xs text-navy-500">{{ __('Only after the owner agreed. The logo moves in the strip on the home page and links to the menu; the strip shows once :n restaurants are in it.', ['n' => \App\Services\Seo\SiteShowcase::MIN_LOGOS]) }}</p>
                <div>
                    <label class="field-label" for="t-showcase-sort">{{ __('Order in the strip (optional)') }}</label>
                    <input class="field" id="t-showcase-sort" name="showcase_sort" type="number" min="0" max="9999" dir="ltr" value="{{ old('showcase_sort', $tenant->showcase_sort) }}" placeholder="{{ __('Smaller first') }}">
                    @error('showcase_sort')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <button class="btn-primary">{{ __('Save') }}</button>
            </form>

            {{-- demos and tests: out of the directory, the sitemap and the home page list (App\Services\Seo\Directory) --}}
            <form method="post" action="{{ route('admin.tenants.directory', $tenant) }}" class="card space-y-3" data-tenant-directory>
                @csrf
                <h2 class="section-title">{{ __('Directory and sitemap') }}</h2>
                <label class="flex min-h-11 items-center gap-3 text-sm font-semibold">
                    <input type="hidden" name="hidden_from_directory" value="0">
                    <input type="checkbox" name="hidden_from_directory" value="1" class="size-5 accent-brand-600" @checked(old('hidden_from_directory', $tenant->hidden_from_directory))>
                    {{ __('Hide from the directory and the sitemap (demo or test place)') }}
                </label>
                <p class="text-xs text-navy-500">{{ __('The menu still opens by its link; it is only left out of egmenu.app/restaurants, sitemap.xml and the list of places on the home page.') }}</p>
                <button class="btn-primary">{{ __('Save') }}</button>
            </form>
        @endif
        <div class="card space-y-3">
            <h2 class="section-title">{{ __('Usage') }}</h2>
            @foreach ($usage as $key => $u)
                <x-usage-bar :label="\App\Models\Plan::label($key)" :used="$u['used']" :limit="$u['limit']" />
            @endforeach
        </div>
    </div>
</div>

@if ($canManage)
    @foreach ($tenant->domains as $d)
        <form method="post" action="{{ route('admin.domains.update', $d) }}" class="card mt-4 flex flex-wrap items-end gap-3">
            @csrf
            <div class="me-auto min-w-0">
                <div class="text-sm text-navy-500">{{ __('Custom domain') }} · {{ $d->source === 'egweb' ? __('book on egweb.com.eg') : __('own domain') }}</div>
                <a href="http://{{ $d->domain }}" target="_blank" rel="noopener" dir="ltr" class="break-all text-lg font-bold">{{ $d->domain }}</a>
            </div>
            <select name="status" class="field min-h-10 w-auto">
                @foreach (\App\Models\Domain::STATUSES as $key => $label)<option value="{{ $key }}" @selected($d->status === $key)>{{ app()->getLocale() === 'en' ? $label[1] : $label[0] }}</option>@endforeach
            </select>
            <input name="note" value="{{ $d->note }}" class="field min-h-10 sm:w-64" maxlength="300" placeholder="{{ __('Note to the restaurant') }}">
            <button class="btn-primary">{{ __('Save') }}</button>
        </form>
    @endforeach
@endif

@if ($canBilling)
    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <form method="post" action="{{ route('admin.tenants.subscription', $tenant) }}" class="card space-y-3">
            @csrf
            <h2 class="section-title">{{ __('Subscription') }}</h2>
            <p class="muted">{{ __('Use after confirming a manual payment (InstaPay / Vodafone Cash).') }}</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="field-label">{{ __('Plan') }}</label>
                    <select class="field" name="plan_id">
                        @foreach ($plans as $p)
                            <option value="{{ $p->id }}" @selected($sub?->plan_id === $p->id)>{{ $p->displayName() }}@if ($p->isContactSales()) · {{ __('Contact sales') }}@elseif (! $p->is_public) · {{ __('Older plan') }}@endif</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="field-label">{{ __('Status') }}</label>
                    <select class="field" name="status">
                        @foreach (\App\Models\Subscription::STATUSES as $key => $l)
                            <option value="{{ $key }}" @selected($sub?->status === $key)>{{ app()->getLocale() === 'en' ? $l[1] : $l[0] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="field-label">{{ __('Billing cycle') }}</label>
                    <select class="field" name="cycle_months">
                        @foreach (\App\Models\Plan::CYCLES as $m)
                            <option value="{{ $m }}" @selected(($sub?->cycle_months ?? 1) === $m)>{{ cycle_label($m) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="field-label">{{ __('Paid until') }}</label>
                    <input class="field" type="date" name="current_period_end" value="{{ $sub?->current_period_end?->format('Y-m-d') }}">
                </div>
                <div>
                    <label class="field-label">{{ __('Trial ends') }}</label>
                    <input class="field" type="date" name="trial_ends_at" value="{{ $sub?->trial_ends_at?->format('Y-m-d') }}">
                </div>
            </div>
            <button class="btn-primary">{{ __('Save subscription') }}</button>
        </form>

        <form method="post" action="{{ route('admin.tenants.addons', $tenant) }}" class="card space-y-1">
            @csrf
            <h2 class="section-title mb-2">{{ __('Add-ons') }}</h2>
            <div class="max-h-96 divide-y divide-navy-50 overflow-y-auto pe-1">
                @foreach ($addons as $a)
                    <label class="flex min-h-11 items-center justify-between gap-3 text-sm">
                        <span class="min-w-0">{{ $a->displayName() }} <span class="text-navy-500">· {{ money($a->monthly_price) }} {{ __('EGP / month') }}</span></span>
                        @if ($a->isFeaturePack())
                            <span><input type="hidden" name="addons[{{ $a->key }}]" value="0"><input type="checkbox" name="addons[{{ $a->key }}]" value="1" @checked(($myAddons[$a->key] ?? 0) > 0) class="size-5 accent-brand-600"></span>
                        @else
                            <input class="field w-20 shrink-0" type="number" min="0" max="50" name="addons[{{ $a->key }}]" value="{{ $myAddons[$a->key] ?? 0 }}">
                        @endif
                    </label>
                @endforeach
            </div>
            <div class="pt-2"><button class="btn-primary">{{ __('Save add-ons') }}</button></div>
        </form>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <section class="card">
            <h2 class="section-title mb-3">{{ __('Invoices') }}</h2>
            <ul class="divide-y divide-navy-50">
                @forelse ($invoices as $inv)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                        <div class="min-w-0"><span dir="ltr" class="font-semibold">{{ $inv->number }}</span> <span class="text-navy-500">· {{ $inv->description() }}</span></div>
                        <div class="flex items-center gap-2"><span class="font-bold">{{ money($inv->total) }}</span>@include('partials.invoice-status', ['invoice' => $inv])</div>
                    </li>
                @empty
                    <li class="py-4 text-center text-navy-500">{{ __('No invoices here.') }}</li>
                @endforelse
            </ul>
        </section>

        <section class="card" id="credits">
            <h2 class="section-title">{{ __('WhatsApp credits') }}</h2>
            <div class="text-3xl font-extrabold">{{ number_format($credits) }} <span class="text-base font-semibold text-navy-500">{{ __('messages') }}</span></div>
            <form method="post" action="{{ route('admin.tenants.credits', $tenant) }}" class="mt-3 grid gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                @csrf
                <div>
                    <label class="field-label" for="credit-delta">{{ __('Add or remove (e.g. 100 or -20)') }}</label>
                    <input class="field" id="credit-delta" name="delta" type="number" required dir="ltr">
                </div>
                <div>
                    <label class="field-label" for="credit-note">{{ __('Note') }}</label>
                    <input class="field" id="credit-note" name="note" maxlength="60">
                </div>
                <button class="btn-primary">{{ __('Adjust credits') }}</button>
            </form>
            @error('delta')<div class="field-error">{{ $message }}</div>@enderror
            @if ($creditLog->isNotEmpty())
                <div class="mt-3 overflow-x-auto">
                    <table class="table-base">
                        <tbody>
                        @foreach ($creditLog as $t)
                            <tr>
                                <td class="whitespace-nowrap">{{ local_time($t->created_at, 'Y/m/d h:i A') }}</td>
                                <td>{{ $t->reasonLabel() }}</td>
                                <td dir="ltr" class="text-start font-semibold {{ $t->delta < 0 ? 'text-navy-500' : 'text-emerald-700' }}">{{ $t->delta > 0 ? '+' : '' }}{{ $t->delta }}</td>
                                <td dir="ltr" class="text-start text-navy-500">{{ $t->reference }}</td>
                                <td>{{ $t->balance_after }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endif

@if ($platformActions->isNotEmpty())
    <section class="card mt-4" data-platform-actions>
        <h2 class="section-title mb-1">{{ __('Support actions on this restaurant') }}</h2>
        <p class="muted mb-2">{{ __('Also shown in the restaurant\'s own audit log.') }}</p>
        <ul class="divide-y divide-navy-50 text-sm">
            @foreach ($platformActions as $log)
                <li class="flex flex-wrap justify-between gap-2 py-2">
                    <span><span class="font-semibold">{{ $actionLabels[$log->action] ?? $log->action }}</span> · {{ $log->subject }}</span>
                    <span class="text-navy-500"><span dir="ltr">{{ $log->meta['admin'] ?? '' }}</span> · {{ local_time($log->created_at) }}</span>
                </li>
            @endforeach
        </ul>
    </section>
@endif
@endsection
