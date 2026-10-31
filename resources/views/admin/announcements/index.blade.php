@extends('layouts.admin')
@section('title', __('Announcements') . ' · EgHub Admin')

@section('content')
<div class="mb-4">
    <h1 class="page-title">{{ __('Announcements') }}</h1>
    <p class="muted">{{ __('A short notice on the dashboards of all restaurants or of one plan, between two dates.') }}</p>
</div>

<div class="grid gap-4 lg:grid-cols-5">
    <section class="lg:col-span-2">
        <h2 class="section-title mb-2">{{ __('New announcement') }}</h2>
        @include('admin.announcements.form')
    </section>

    <section class="card overflow-x-auto lg:col-span-3" data-announcements>
        <table class="table-base">
            <thead><tr><th>{{ __('Title') }}</th><th>{{ __('Shown to') }}</th><th>{{ __('When') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></tr></thead>
            <tbody>
            @forelse ($announcements as $a)
                <tr data-announcement-row="{{ $a->id }}">
                    <td class="min-w-48">
                        <div class="flex items-center gap-2">
                            <span @class(['size-2 shrink-0 rounded-full', 'bg-amber-500' => $a->level === 'warning', 'bg-navy-500' => $a->level !== 'warning'])></span>
                            <span class="font-semibold">{{ $a->title }}</span>
                        </div>
                        <div class="mt-0.5 line-clamp-2 text-xs text-navy-500">{{ $a->body }}</div>
                    </td>
                    <td class="whitespace-nowrap">{{ $a->plan ? $a->plan->displayName() : __('All restaurants') }}</td>
                    <td class="whitespace-nowrap text-xs">
                        @if ($a->isLive())
                            <span class="badge bg-emerald-100 text-emerald-800">{{ __('Showing now') }}</span>
                        @elseif ($a->ends_at && $a->ends_at->isPast())
                            <span class="badge bg-navy-100 text-navy-600">{{ __('Ended') }}</span>
                        @else
                            <span class="badge bg-amber-100 text-amber-800">{{ __('Scheduled') }}</span>
                        @endif
                        <div class="mt-1 text-navy-500">{{ local_time($a->starts_at, 'Y/m/d h:i A') }} → {{ $a->ends_at ? local_time($a->ends_at, 'Y/m/d h:i A') : __('No end') }}</div>
                    </td>
                    <td class="whitespace-nowrap">
                        <div class="flex gap-2">
                            <a href="{{ route('admin.announcements.edit', $a) }}" class="btn-ghost min-h-9 px-3">{{ __('Edit') }}</a>
                            <form method="post" action="{{ route('admin.announcements.delete', $a) }}" onsubmit="return confirm(@js(__('Delete this announcement?')))">@csrf<button class="btn-ghost min-h-9 px-3 text-red-700">{{ __('Delete') }}</button></form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-8 text-center text-navy-500">{{ __('No announcements yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-4">{{ $announcements->links() }}</div>
    </section>
</div>
@endsection
