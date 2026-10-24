@extends('layouts.admin')
@section('title', __('Service requests') . ' · EgHub Admin')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-extrabold">{{ __('Service requests') }}</h1>
    <nav class="flex flex-wrap gap-1 text-sm font-semibold">
        @foreach (['open' => __('To follow up'), 'new' => __('New'), 'contacted' => __('Contacted'), 'done' => __('Done'), 'all' => __('All')] as $key => $label)
            <a href="?status={{ $key }}" @class(['rounded-lg px-3 py-2', 'bg-navy-800 text-white' => $status === $key, 'bg-white text-navy-700 ring-1 ring-navy-100' => $status !== $key])>
                {{ $label }}@if (isset($counts[$key])) <span class="opacity-60">{{ $counts[$key] }}</span>@endif
            </a>
        @endforeach
    </nav>
</div>

<div class="card overflow-x-auto">
    <table class="table-base">
        <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Service') }}</th><th>{{ __('Restaurant') }}</th><th>{{ __('Contact') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Notes') }}</th><th>{{ __('Status') }}</th></tr></thead>
        <tbody>
        @forelse ($requests as $r)
            <tr>
                <td class="whitespace-nowrap">{{ local_time($r->created_at, 'Y/m/d h:i A') }}</td>
                <td class="font-semibold">{{ $r->serviceName() }}</td>
                <td>{{ $r->restaurant }}</td>
                <td>
                    {{ $r->name }}
                    <div class="text-sm" dir="ltr"><a class="text-emerald-700" href="https://wa.me/{{ whatsapp_number($r->phone) }}" target="_blank" rel="noopener">{{ $r->phone }}</a></div>
                    @if ($r->email)<div class="text-xs text-navy-500" dir="ltr">{{ $r->email }}</div>@endif
                </td>
                <td>{{ $r->quantity ?? '—' }}</td>
                <td class="max-w-xs text-sm whitespace-pre-line">{{ $r->notes ?? '—' }}</td>
                <td>
                    <form method="post" action="{{ route('admin.services.update', $r) }}" class="flex gap-1">
                        @csrf
                        <select name="status" class="field min-h-9 py-0" onchange="this.form.submit()" aria-label="{{ __('Status') }}">
                            @foreach (\App\Models\ServiceRequest::STATUSES as $key => $_)
                                <option value="{{ $key }}" @selected($r->status === $key)>{{ (new \App\Models\ServiceRequest(['status' => $key]))->statusLabel() }}</option>
                            @endforeach
                        </select>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-10 text-center text-navy-500">{{ __('No requests here.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="mt-4">{{ $requests->links() }}</div>
</div>
@endsection
