@extends('layouts.dashboard')
@section('title', __('Kiosk'))

@section('content')
<h1 class="page-title mb-1">{{ __('Kiosk') }}</h1>
<p class="muted mb-4">{{ __('Put a tablet at the counter and open the kiosk link on it once. Guests order by themselves, get their order number and pay at the cashier; the order shows on the order board and the kitchen screen right away.') }}</p>

<div class="grid gap-4 xl:grid-cols-3">
    <div class="space-y-4 xl:col-span-2">
        @forelse ($kiosks as $k)
            @php
                $o = $k->options();
            @endphp
            <section @class(['card space-y-3', 'opacity-60' => !$k->is_active])>
                <div class="flex flex-wrap items-start gap-4">
                    <div class="size-28 shrink-0 rounded-xl bg-white p-1 ring-1 ring-navy-100 [&_svg]:h-full [&_svg]:w-full">{!! $qrs[$k->id] !!}</div>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="section-title">{{ $k->name }}</h2>
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ $k->url() }}" target="_blank" rel="noopener" class="btn-primary min-h-9 px-3">{{ __('Open kiosk') }}</a>
                                <button type="button" class="btn-ghost min-h-9 px-3" data-copy="{{ $k->url() }}">{{ __('Copy link') }}</button>
                            </div>
                        </div>
                        <div class="text-sm text-navy-600">
                            {{ tr($k->branch?->name, $k->branch?->name_en) }} ·
                            {{ collect($o['order_types'])->map(fn ($t) => $t === 'takeaway' ? __('Takeaway') : __('Dine-in'))->implode(' / ') }} ·
                            {{ __('Back to start after :n seconds', ['n' => $o['idle_seconds']]) }}
                            @unless ($k->is_active) · <span class="font-bold text-red-600">{{ __('Stopped') }}</span>@endunless
                        </div>
                        <div class="rounded-lg bg-navy-50 px-3 py-2 text-xs break-all text-navy-600" dir="ltr">{{ $k->url() }}</div>
                    </div>
                </div>
                <details>
                    <summary class="cursor-pointer text-sm font-semibold text-brand-700">{{ __('Edit') }}</summary>
                    <form method="post" action="{{ route('dash.kiosks.update', $k) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                        @csrf
                        @include('dashboard.partials.kiosk-fields', ['k' => $k])
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($k->is_active) class="size-4 accent-brand-600"> {{ __('Active') }}</label>
                        <div class="sm:col-span-2"><button class="btn-primary">{{ __('Save') }}</button></div>
                    </form>
                    <div class="mt-3 flex flex-wrap gap-3 border-t border-navy-100 pt-3 text-sm">
                        <form method="post" action="{{ route('dash.kiosks.regenerate', $k) }}" onsubmit="return confirm(@js(__('The current link will stop working. Continue?')))">@csrf<button class="text-navy-600 underline">{{ __('New link') }}</button></form>
                        <form method="post" action="{{ route('dash.kiosks.delete', $k) }}" onsubmit="return confirm(@js(__('Delete this kiosk?')))">@csrf<button class="text-red-600">{{ __('Delete') }}</button></form>
                    </div>
                </details>
            </section>
        @empty
            <div class="card py-10 text-center text-navy-500">{{ __('No kiosks yet. Add your first kiosk.') }}</div>
        @endforelse
    </div>
    <form method="post" action="{{ route('dash.kiosks.store') }}" class="card grid h-fit gap-3">
        @csrf
        <h2 class="section-title">{{ __('Add kiosk') }}</h2>
        @include('dashboard.partials.kiosk-fields', ['k' => null])
        <button class="btn-primary">{{ __('Add kiosk') }}</button>
    </form>
</div>

<script>
    document.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(b.dataset.copy); b.textContent = @js(__('Copied')); } catch (e) { prompt('', b.dataset.copy); }
    }));
</script>
@endsection
