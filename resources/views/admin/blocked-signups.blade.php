@extends('layouts.admin')
@section('title', __('Blocked') . ' · EgHub Admin')

@section('content')
<div class="mb-4">
    <a href="{{ route('admin.tenants') }}" class="text-sm font-semibold text-navy-500 hover:text-navy-800">← {{ __('Restaurants') }}</a>
    <h1 class="page-title mt-1">{{ __('Blocked') }}</h1>
    <p class="muted">{{ __('Emails and mobiles that can\'t open a new account. Block a restaurant from its page; remove a row here to let it sign up again.') }}</p>
</div>

<section class="card overflow-x-auto" data-blocked-signups>
    <table class="table-base">
        <thead><tr><th>{{ __('Email') }}</th><th>{{ __('Mobile') }}</th><th>{{ __('Restaurant') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Blocked by') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></tr></thead>
        <tbody>
        @forelse ($blocked as $b)
            <tr data-blocked-row="{{ $b->id }}" class="align-top">
                <td dir="ltr" class="break-all">{{ $b->email ?? '—' }}</td>
                <td dir="ltr">{{ $b->phone ?? '—' }}</td>
                <td>
                    @if ($b->tenant_id)
                        <a href="{{ route('admin.tenants.show', $b->tenant_id) }}" dir="ltr" class="font-semibold text-navy-800 hover:text-brand-700">{{ $b->tenant_id }}</a>
                    @else
                        —
                    @endif
                </td>
                <td class="min-w-40">{{ $b->reason ?? '—' }}</td>
                <td>{{ $b->admin?->name ?? '—' }}<div class="text-xs text-navy-500">{{ local_time($b->created_at, 'Y/m/d') }}</div></td>
                <td>
                    <form method="post" action="{{ route('admin.blocked-signups.destroy', $b) }}" onsubmit="return confirm(@js(__('Let this email and mobile open an account again?')))">
                        @csrf
                        <button class="btn-ghost">{{ __('Remove') }}</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="py-8 text-center text-navy-500">{{ __('Nobody is blocked.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</section>
<div class="mt-4">{{ $blocked->links() }}</div>
@endsection
