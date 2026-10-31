@extends('layouts.admin')
@section('title', __('Activity log') . ' · EgHub Admin')

@section('content')
@php($canTenants = auth('admin')->user()->allows('tenants.view'))
<div class="mb-4">
    <h1 class="text-2xl font-extrabold">{{ __('Activity log') }}</h1>
    <p class="text-sm text-navy-500">{{ __('What the admin team changed on the platform: prices, plans, restaurants, discounts and support access. Newest first.') }}</p>
</div>

<form method="get" class="card mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" data-activity-filters>
    <div>
        <label class="field-label" for="f-admin">{{ __('Team member') }}</label>
        <select id="f-admin" name="admin" class="field">
            <option value="">{{ __('Everyone') }}</option>
            @foreach ($admins as $a)
                <option value="{{ $a->id }}" @selected($filters['admin'] === (string) $a->id)>{{ $a->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="field-label" for="f-action">{{ __('Action') }}</label>
        <select id="f-action" name="action" class="field">
            <option value="">{{ __('All actions') }}</option>
            @foreach ($actions as $action)
                <option value="{{ $action }}" @selected($filters['action'] === $action)>{{ \App\Support\AdminActivityLabels::label($action) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="field-label" for="f-q">{{ __('Restaurant or subject') }}</label>
        <input id="f-q" type="search" name="q" value="{{ $filters['q'] }}" class="field" placeholder="{{ __('e.g. the restaurant link name') }}">
    </div>
    <div class="grid grid-cols-2 gap-2">
        <div><label class="field-label" for="f-from">{{ __('From') }}</label><input id="f-from" type="date" name="from" value="{{ $filters['from'] }}" class="field"></div>
        <div><label class="field-label" for="f-to">{{ __('To') }}</label><input id="f-to" type="date" name="to" value="{{ $filters['to'] }}" class="field"></div>
    </div>
    <div class="flex items-end gap-2">
        <button class="btn-navy flex-1">{{ __('Filter') }}</button>
        @if (collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty())
            <a href="{{ route('admin.activity') }}" class="btn-ghost">{{ __('Reset') }}</a>
        @endif
    </div>
</form>

<div class="card overflow-x-auto">
    <table class="table-base" data-activity>
        <thead><tr><th>{{ __('Time') }}</th><th>{{ __('Team member') }}</th><th>{{ __('Action') }}</th><th>{{ __('Subject') }}</th><th>{{ __('Details') }}</th></tr></thead>
        <tbody>
        @forelse ($logs as $log)
            <tr data-activity-row="{{ $log->id }}">
                <td class="whitespace-nowrap">{{ local_time($log->created_at, 'Y/m/d h:i A') }}<div class="text-xs text-navy-400" dir="ltr">{{ $log->ip }}</div></td>
                <td class="whitespace-nowrap">{{ $log->admin?->name ?? __('System') }}</td>
                <td>
                    @php($actionLabel = \App\Support\AdminActivityLabels::label($log->action))
                    <span class="badge bg-navy-100 text-navy-700" title="{{ $log->action }}" data-action="{{ $log->action }}" @if ($actionLabel === $log->action) dir="ltr" @endif>{{ $actionLabel }}</span>
                </td>
                <td dir="ltr" class="text-start">
                    @if ($canTenants && $log->subject !== '' && $log->action !== 'tenant.deleted' && (str_starts_with($log->action, 'tenant.') || str_starts_with($log->action, 'crm.discount')))
                        <a href="{{ route('admin.tenants.show', $log->subject) }}" class="font-semibold text-navy-800 hover:text-brand-700">{{ $log->subject }}</a>
                    @else
                        {{ $log->subject }}
                    @endif
                </td>
                <td class="min-w-[240px] max-w-md">
                    @if ($log->meta)
                        <code class="block truncate text-xs text-navy-500" dir="ltr" title="{{ json_encode($log->meta, JSON_UNESCAPED_UNICODE) }}">{{ \Illuminate\Support\Str::limit(json_encode($log->meta, JSON_UNESCAPED_UNICODE), 160) }}</code>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-8 text-center text-navy-500">{{ __('Nothing recorded for these filters.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $logs->links() }}</div>
@endsection
