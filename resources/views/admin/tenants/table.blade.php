@php
    // the owner of each listed restaurant (email, mobile + WhatsApp confirmation), one query for the page
    $owners = \App\Models\User::query()->withoutGlobalScopes()->whereIn('tenant_id', $tenants->pluck('id'))->role('owner')
        ->orderBy('id')->get()->keyBy('tenant_id');
    $compact = $compact ?? false;
    // the business type of each place (the full list passes them, read in one query by App\Services\AdminTenantSearch)
    $types = $types ?? [];
    $canOpen = ! $compact && auth('admin')->user()?->allows('tenants.impersonate');
    $statusBadge = function ($t): array {
        if ($t->isSuspended()) {
            return ['bg-navy-200 text-navy-700', __('Suspended')];
        }
        if (! $t->subscription) {
            return ['', ''];
        }

        return [['trialing' => 'bg-brand-100 text-brand-700', 'active' => 'bg-emerald-100 text-emerald-800', 'past_due' => 'bg-amber-100 text-amber-800'][$t->subscription->status] ?? 'bg-navy-100 text-navy-600', $t->subscription->statusLabel()];
    };
@endphp
{{-- wide screens: a table --}}
<div @class(['hidden overflow-x-auto', 'md:block' => ! $compact, '2xl:block' => $compact])>
    <table @class(['table-base', 'table-roomy' => ! $compact])>
        <thead><tr>
            <th>{{ __('Restaurant') }}</th>
            @unless ($compact)<th>{{ __('Owner') }}</th>@else<th>{{ __('Mobile') }}</th>@endunless
            @unless ($compact)
                <th>{{ __('Subscription') }}</th>
            @else
                <th>{{ __('Plan') }}</th><th>{{ __('Status') }}</th>
            @endunless
            @unless ($compact)<th>{{ __('Dates') }}</th>@else<th>{{ __('Created') }}</th>@endunless
            @if ($canOpen)<th><span class="sr-only">{{ __('Open the restaurant dashboard') }}</span></th>@endif
        </tr></thead>
        <tbody>
        @forelse ($tenants as $t)
            @php
                $owner = $owners->get($t->id);
                [$badgeClass, $badgeLabel] = $statusBadge($t);
                $type = $types[$t->id] ?? null;
            @endphp
            <tr data-tenant-row="{{ $t->id }}">
                <td>
                    <a href="{{ route('admin.tenants.show', $t) }}" class="font-bold text-brand-700 hover:underline">{{ $t->name }}</a>
                    @unless ($compact)
                        @if ($type)<div class="mt-1 text-sm text-navy-700" data-tenant-type="{{ $type }}"><span aria-hidden="true">{{ \App\Support\BusinessType::emoji($type) }}</span> {{ \App\Support\BusinessType::label($type) }}</div>@endif
                        <div class="mt-1 text-xs text-navy-500"><bdi dir="ltr">/{{ $t->id }}</bdi></div>
                        @if ($t->hidden_from_directory)<div class="mt-1.5"><span class="badge bg-navy-100 text-navy-600">{{ __('Hidden from the directory') }}</span></div>@endif
                    @endunless
                </td>
                <td>
                    @unless ($compact)
                        @if ($owner?->email)<div class="break-all text-sm text-navy-800"><bdi dir="ltr">{{ $owner->email }}</bdi></div>@endif
                    @endunless
                    @if ($owner?->phone)
                        <div @class(['mt-1' => ! $compact, 'text-sm text-navy-700 tabular-nums'])><bdi dir="ltr">{{ $owner->phone }}</bdi></div>
                        <div class="mt-1.5">
                            @if ($owner->hasVerifiedPhone())
                                <span class="badge bg-emerald-100 text-emerald-800">✓ {{ __('Phone confirmed') }}</span>
                            @else
                                <span class="badge bg-amber-100 text-amber-800">{{ __('Not confirmed') }}</span>
                            @endif
                        </div>
                    @elseif ($compact || ! $owner?->email)
                        —
                    @endif
                </td>
                @unless ($compact)
                    <td>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-navy-800">{{ $t->subscription?->plan->displayName() ?? '—' }}</span>
                            @if ($badgeLabel)<span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span>@endif
                        </div>
                        @if ($t->subscription?->endsAt())<div class="mt-1.5 text-xs text-navy-500">{{ __('Ends') }}: <span class="tabular-nums">{{ local_time($t->subscription->endsAt(), 'Y/m/d') }}</span></div>@endif
                    </td>
                    <td class="whitespace-nowrap text-xs text-navy-500">
                        <div>{{ __('Created') }}: <span class="tabular-nums text-navy-700">{{ local_time($t->created_at, 'Y/m/d') }}</span></div>
                        <div class="mt-1.5">{{ __('Last order') }}: <span class="tabular-nums text-navy-700">{{ $t->last_order_at ? local_time($t->last_order_at, 'Y/m/d') : '—' }}</span></div>
                    </td>
                @else
                    <td>{{ $t->subscription?->plan->displayName() ?? '—' }}</td>
                    <td>@if ($badgeLabel)<span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span>@endif</td>
                    <td>{{ local_time($t->created_at, 'Y/m/d') }}</td>
                @endunless
                @if ($canOpen)
                    <td class="text-end">
                        <form method="post" action="{{ route('admin.tenants.support-access', $t) }}" target="_blank">
                            @csrf
                            <button class="btn-ghost min-h-9 whitespace-nowrap px-3 text-xs" data-support-access="{{ $t->id }}">{{ __('Open the restaurant dashboard') }}</button>
                        </form>
                    </td>
                @endif
            </tr>
        @empty
            <tr><td colspan="9" class="py-8 text-center text-navy-500">{{ ($filtered ?? false) ? __('No restaurant matches this search or these filters.') : __('No restaurants yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
{{-- phones (and the compact overview list): one stacked card per restaurant --}}
<ul @class(['divide-y divide-navy-50', 'md:hidden' => ! $compact, '2xl:hidden' => $compact]) data-tenant-cards>
    @forelse ($tenants as $t)
        @php
            $owner = $owners->get($t->id);
            [$badgeClass, $badgeLabel] = $statusBadge($t);
            $type = $types[$t->id] ?? null;
        @endphp
        <li class="py-3" data-tenant-card="{{ $t->id }}">
            <a href="{{ route('admin.tenants.show', $t) }}" class="flex items-center gap-3 hover:bg-navy-50/60">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-navy-50 text-sm font-black text-navy-700" aria-hidden="true">{{ $type ? \App\Support\BusinessType::emoji($type) : mb_substr($t->name, 0, 1) }}</span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-bold text-navy-900">{{ $t->name }}</span>
                    <span class="block truncate text-xs text-navy-500"><span dir="ltr">/{{ $t->id }}</span> · {{ $t->subscription?->plan->displayName() ?? '—' }}@if ($type) · {{ \App\Support\BusinessType::label($type) }}@endif</span>
                </span>
                @if ($badgeLabel)<span class="badge shrink-0 {{ $badgeClass }}">{{ $badgeLabel }}</span>@endif
            </a>
            @unless ($compact)
                <div class="mt-2 flex flex-wrap items-center justify-between gap-2 ps-13 text-xs text-navy-500">
                    <span class="min-w-0 space-x-1 break-all">
                        @if ($owner?->email)<span dir="ltr">{{ $owner->email }}</span>@endif
                        @if ($owner?->phone)<span dir="ltr">{{ $owner->phone }}</span>@endif
                        <span>· {{ local_time($t->created_at, 'Y/m/d') }}</span>
                        @if ($t->hidden_from_directory)<span class="badge bg-navy-100 text-navy-600">{{ __('Hidden from the directory') }}</span>@endif
                    </span>
                    @if ($canOpen)
                        <form method="post" action="{{ route('admin.tenants.support-access', $t) }}" target="_blank" class="shrink-0">
                            @csrf
                            <button class="btn-ghost min-h-9 px-3 text-xs">{{ __('Open the restaurant dashboard') }}</button>
                        </form>
                    @endif
                </div>
            @endunless
        </li>
    @empty
        <li class="py-8 text-center text-sm text-navy-500">{{ ($filtered ?? false) ? __('No restaurant matches this search or these filters.') : __('No restaurants yet.') }}</li>
    @endforelse
</ul>
