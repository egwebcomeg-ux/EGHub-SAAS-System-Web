@extends('layouts.dashboard')
@section('title', __('Audit log'))

@section('content')
<x-dash.page-header :title="__('Audit log')" :help="__('The last 200 actions: who changed what, and when.')" />

<form class="mb-3 flex flex-wrap gap-2">
    <select name="user" class="field min-h-9 w-auto py-0" onchange="this.form.submit()">
        <option value="">{{ __('All users') }}</option>
        @foreach ($users as $u)<option value="{{ $u->id }}" @selected($userId === $u->id)>{{ $u->name }}</option>@endforeach
    </select>
</form>

<section class="card overflow-x-auto">
    <table class="table-base">
        <thead><tr><th>{{ __('Time') }}</th><th>{{ __('By') }}</th><th>{{ __('Action') }}</th><th>{{ __('Details') }}</th></tr></thead>
        <tbody>
        @forelse ($logs as $log)
            <tr>
                <td class="whitespace-nowrap text-navy-500">{{ local_time($log->created_at, 'd/m h:i A') }}</td>
                <td class="font-semibold">{{ $log->user?->name ?? '—' }}</td>
                <td>{{ \App\Http\Controllers\Dashboard\AuditController::label($log->action) }}</td>
                <td class="text-navy-600">
                    {{ $log->subject }}
                    @if (isset($log->meta['from'], $log->meta['to']))<span dir="ltr" class="text-xs text-navy-500">({{ $log->meta['from'] }} → {{ $log->meta['to'] }})</span>@endif
                    @if (! empty($log->meta['reason']))<span class="text-xs text-navy-500">· {{ $log->meta['reason'] }}</span>@endif
                    @if (! empty($log->meta['cashier']))<span class="text-xs text-navy-500">· {{ __('Cashier: :name', ['name' => $log->meta['cashier']]) }}</span>@endif
                    @if ($log->action === \App\Services\Customers\SignIn\SignInGuard::AUDIT_ACTION)
                        @foreach ((array) $log->meta as $method => $counts)
                            <span class="text-xs text-navy-500" data-sign-in-counts="{{ $method }}">{{ __('Signed in: :ok · wrong: :failed · blocked: :blocked', ['ok' => (int) ($counts['ok'] ?? 0), 'failed' => (int) ($counts['failed'] ?? 0), 'blocked' => (int) ($counts['blocked'] ?? 0)]) }}</span>
                        @endforeach
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="py-6 text-center text-navy-500">{{ __('No actions recorded yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</section>
@endsection
