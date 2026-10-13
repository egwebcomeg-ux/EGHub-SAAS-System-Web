<table class="table-base">
    <thead><tr><th>{{ __('Restaurant') }}</th><th>{{ __('Link') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Status') }}</th><th>{{ __('Ends') }}</th><th>{{ __('Created') }}</th></tr></thead>
    <tbody>
    @forelse ($tenants as $t)
        <tr>
            <td><a href="{{ route('admin.tenants.show', $t) }}" class="font-semibold text-brand-700 hover:underline">{{ $t->name }}</a></td>
            <td dir="ltr" class="text-start text-navy-500">/{{ $t->id }}</td>
            <td>{{ $t->subscription?->plan->displayName() ?? '—' }}</td>
            <td>
                @if ($t->isSuspended())
                    <span class="badge bg-red-100 text-red-700">{{ __('Suspended') }}</span>
                @elseif ($t->subscription)
                    <span class="badge {{ ['trialing' => 'bg-brand-100 text-brand-700', 'active' => 'bg-emerald-100 text-emerald-800', 'past_due' => 'bg-amber-100 text-amber-800'][$t->subscription->status] ?? 'bg-navy-100 text-navy-600' }}">{{ $t->subscription->statusLabel() }}</span>
                @endif
            </td>
            <td>{{ $t->subscription?->endsAt() ? local_time($t->subscription->endsAt(), 'Y/m/d') : '—' }}</td>
            <td>{{ local_time($t->created_at, 'Y/m/d') }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="py-8 text-center text-navy-500">{{ __('No restaurants yet.') }}</td></tr>
    @endforelse
    </tbody>
</table>
