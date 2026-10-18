@extends('layouts.dashboard')
@section('title', __('TV screens'))

@section('content')
<x-dash.page-header :title="__('TV screens')" :help="__('Open the screen link once in the TV browser (or a TV stick) and leave it. It updates by itself within a minute when you change a price, an item or an offer.')" />

<div class="grid gap-4 xl:grid-cols-3">
    <div class="space-y-4 xl:col-span-2">
        @forelse ($screens as $s)
            <section @class(['card space-y-3', 'opacity-60' => !$s->is_active])>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="section-title">{{ $s->name }}</h2>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ $s->url() }}" target="_blank" rel="noopener" class="btn-primary min-h-9 px-3">{{ __('Open screen') }}</a>
                        <button type="button" class="btn-ghost min-h-9 px-3" data-copy="{{ $s->url() }}">{{ __('Copy link') }}</button>
                    </div>
                </div>
                <div class="rounded-lg bg-navy-50 px-3 py-2 text-xs text-navy-600" dir="ltr">{{ $s->url() }}</div>
                <details>
                    <summary class="cursor-pointer text-sm font-semibold text-brand-700">{{ __('Edit') }}</summary>
                    <form method="post" action="{{ route('dash.tv.update', $s) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                        @csrf
                        @include('dashboard.partials.tv-fields', ['s' => $s])
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($s->is_active) class="size-4 accent-brand-600"> {{ __('Active') }}</label>
                        <div class="sm:col-span-2"><button class="btn-primary">{{ __('Save') }}</button></div>
                    </form>
                    <div class="mt-3 flex flex-wrap gap-3 border-t border-navy-100 pt-3 text-sm">
                        <form method="post" action="{{ route('dash.tv.regenerate', $s) }}" onsubmit="return confirm(@js(__('The current link will stop working. Continue?')))">@csrf<button class="min-h-10 px-2 text-navy-600 underline">{{ __('New link') }}</button></form>
                        <form method="post" action="{{ route('dash.tv.delete', $s) }}" onsubmit="return confirm(@js(__('Delete this screen?')))">@csrf<button class="min-h-10 px-2 text-red-600">{{ __('Delete') }}</button></form>
                    </div>
                </details>
            </section>
        @empty
            <div class="empty-state">{{ __('No screens yet. Add your first TV screen.') }}</div>
        @endforelse
    </div>
    <form method="post" action="{{ route('dash.tv.store') }}" class="card grid h-fit gap-3">
        @csrf
        <h2 class="section-title">{{ __('Add screen') }}</h2>
        @include('dashboard.partials.tv-fields', ['s' => null])
        <button class="btn-primary">{{ __('Add screen') }}</button>
    </form>
</div>

<script>
    document.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(b.dataset.copy); b.textContent = @js(__('Copied')); } catch (e) { prompt('', b.dataset.copy); }
    }));
</script>
@endsection
