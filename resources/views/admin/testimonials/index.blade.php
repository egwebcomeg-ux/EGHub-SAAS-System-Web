@extends('layouts.admin')
@section('title', __('Testimonials') . ' · EgHub Admin')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-extrabold">{{ __('Testimonials') }}</h1>
        <p class="text-sm text-navy-500">{{ __('Real words from restaurant owners, shown on the home page. Only quotes the owner agreed to can be published, and the section stays hidden while none are published.') }}</p>
    </div>
    <a href="{{ route('admin.testimonials.create') }}" class="btn-primary">+ {{ __('New testimonial') }}</a>
</div>

@if ($errors->any())<div class="mb-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

<div class="card overflow-x-auto">
    <table class="table-base">
        <thead><tr><th>{{ __('Owner') }}</th><th>{{ __('Quote') }}</th><th>{{ __('Status') }}</th><th>{{ __('Order') }}</th><th></th></tr></thead>
        <tbody>
        @forelse ($testimonials as $t)
            <tr data-testimonial="{{ $t->id }}">
                <td class="whitespace-nowrap">
                    <a href="{{ route('admin.testimonials.edit', $t) }}" class="font-semibold text-brand-700 hover:underline">{{ $t->name }}</a>
                    @if ($t->role)<div class="text-xs text-navy-500">{{ $t->role }}</div>@endif
                    @if ($t->tenant_id)<div class="text-xs text-navy-400" dir="ltr">{{ $t->tenant_id }}</div>@endif
                </td>
                <td class="max-w-md text-sm text-navy-600">{{ \Illuminate\Support\Str::limit($t->quote, 120) }}</td>
                <td>
                    <span class="badge {{ $t->isLive() ? 'bg-emerald-50 text-emerald-800' : 'bg-navy-100 text-navy-600' }}">{{ $t->isLive() ? __('Published') : __('Hidden') }}</span>
                    @unless ($t->consent_at)<div class="mt-1 text-xs text-amber-700">{{ __('No consent yet') }}</div>@endunless
                </td>
                <td>{{ $t->sort }}</td>
                <td>
                    <div class="flex justify-end gap-2">
                        <form method="post" action="{{ route('admin.testimonials.publish', $t) }}">@csrf<button class="btn-ghost min-h-9 px-3">{{ $t->is_published ? __('Hide') : __('Publish') }}</button></form>
                        <form method="post" action="{{ route('admin.testimonials.delete', $t) }}" onsubmit="return confirm(@js(__('Delete this testimonial?')))">@csrf<button class="btn-ghost min-h-9 px-3 text-red-600">{{ __('Delete') }}</button></form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-8 text-center text-navy-500">{{ __('No testimonials yet. Ask a happy owner for two lines and add them here.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
