@extends('layouts.admin')
@section('title', __('Assistant questions') . ' · EgMenu Admin')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-extrabold">{{ __('Assistant questions') }}</h1>
        <p class="text-sm text-navy-500">{{ __('What visitors asked the website assistant and what it answered. Hand-offs are the chats it sent to the sales WhatsApp.') }}</p>
    </div>
    <span class="badge {{ $enabled ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800' }}">{{ $enabled ? __('On · :model', ['model' => $model]) : __('Off: set ANTHROPIC_API_KEY on the server') }}</span>
</div>

@if (! $enabled)
    <div class="mb-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">{{ __('Without a key the “Ask us” button opens the sales WhatsApp directly. Add ANTHROPIC_API_KEY (and optionally ASSISTANT_MODEL) to .env, then run php artisan optimize.') }}</div>
@endif

<div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    @foreach ([[__('Questions today'), $stats['today']], [__('Questions in 30 days'), $stats['month']], [__('Chats in 30 days'), $stats['chats']], [__('Hand-offs to WhatsApp'), $stats['handoff']], [__('Tokens in 30 days'), number_format($stats['tokens'])]] as [$label, $value])
        <div class="card"><div class="text-2xl font-black">{{ $value }}</div><div class="text-xs text-navy-500">{{ $label }}</div></div>
    @endforeach
</div>

<form method="get" class="mb-4 flex flex-wrap items-center gap-2">
    <input type="search" name="q" value="{{ $q }}" class="field w-72" placeholder="{{ __('A word in the question or the answer') }}">
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="handoff" value="1" @checked($only) class="size-4 accent-brand-600"> {{ __('Only chats handed to the team') }}</label>
    <button class="btn-ghost">{{ __('Search') }}</button>
</form>

<div class="card overflow-x-auto">
    <table class="table-base">
        <thead><tr><th>{{ __('Time') }}</th><th>{{ __('Question') }}</th><th>{{ __('Answer') }}</th><th>{{ __('WhatsApp') }}</th></tr></thead>
        <tbody>
        @forelse ($rows as $r)
            <tr>
                <td class="whitespace-nowrap">{{ local_time($r->created_at, 'm/d h:i A') }}<div class="text-xs text-navy-400" dir="ltr">{{ substr($r->conversation, 0, 8) }} · {{ $r->lang }}</div></td>
                <td class="min-w-[200px]">{{ $r->question }}</td>
                <td class="min-w-[280px] whitespace-pre-line text-sm">{{ $r->answer }}</td>
                <td>{{ $r->handoff ? '✓' : '' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="py-8 text-center text-navy-500">{{ __('No questions yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $rows->links() }}</div>
@endsection
