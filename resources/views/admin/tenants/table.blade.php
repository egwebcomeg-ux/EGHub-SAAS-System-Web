@php
    // the owner of each listed restaurant (mobile + WhatsApp confirmation), one query for the page
    $owners = \App\Models\User::query()->withoutGlobalScopes()->whereIn('tenant_id', $tenants->pluck('id'))->role('owner')
        ->orderBy('id')->get()->keyBy('tenant_id');
    $compact = $compact ?? false;
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
    <table class="table-base">
        <thead><tr><th>{{ __('Restaurant') }}</th>@unless ($compact)<th>{{ __('Link') }}</th>@endunless<th>{{ __('Mobile') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Status') }}</th>@unless ($compact)<th>{{ __('Ends') }}</th>@endunless<th>{{ __('Created') }}</th></tr></thead>
        <tbody>
        @forelse ($tenants as $t)
            @php
                $owner = $owners->get($t->id);
                [$badgeClass, $badgeLabel] = $statusBadge($t);
            @endphp
            <tr>
                <td><a href="{{ route('admin.tenants.show', $t) }}" class="font-semibold text-brand-700 hover:underline">{{ $t->name }}</a></td>
                @unless ($compact)<td dir="ltr" class="text-start text-navy-500">/{{ $t->id }}</td>@endunless
                <td>
                    @if ($owner?->phone)
                        <span dir="ltr">{{ $owner->phone }}</span>
                        @if ($owner->hasVerifiedPhone())
                            <span class="badge bg-emerald-100 text-emerald-800">✓ {{ __('Phone confirmed') }}</span>
                        @else
                            <span class="badge bg-amber-100 text-amber-800">{{ __('Not confirmed') }}</span>
                        @endif
                    @else
                        —
                    @endif
                </td>
                <td>{{ $t->subscription?->plan->displayName() ?? '—' }}</td>
                <td>@if ($badgeLabel)<span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span>@endif</td>
                @unless ($compact)<td>{{ $t->subscription?->endsAt() ? local_time($t->subscription->endsAt(), 'Y/m/d') : '—' }}</td>@endunless
                <td>{{ local_time($t->created_at, 'Y/m/d') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-8 text-center text-navy-500">{{ __('No restaurants yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
{{-- phones (and the compact overview list): one row card per restaurant --}}
<ul @class(['divide-y divide-navy-50', 'md:hidden' => ! $compact, '2xl:hidden' => $compact]) data-tenant-cards>
    @forelse ($tenants as $t)
        @php
            $owner = $owners->get($t->id);
            [$badgeClass, $badgeLabel] = $statusBadge($t);
        @endphp
        <li>
            <a href="{{ route('admin.tenants.show', $t) }}" class="flex items-center gap-3 py-3 hover:bg-navy-50/60">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-navy-50 text-sm font-black text-navy-700">{{ mb_substr($t->name, 0, 1) }}</span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-bold text-navy-900">{{ $t->name }}</span>
                    <span class="block truncate text-xs text-navy-500"><span dir="ltr">/{{ $t->id }}</span> · {{ $t->subscription?->plan->displayName() ?? '—' }}@if ($owner?->phone) · <span dir="ltr">{{ $owner->phone }}</span>@endif</span>
                </span>
                @if ($badgeLabel)<span class="badge shrink-0 {{ $badgeClass }}">{{ $badgeLabel }}</span>@endif
            </a>
        </li>
    @empty
        <li class="py-8 text-center text-sm text-navy-500">{{ __('No restaurants yet.') }}</li>
    @endforelse
</ul>
