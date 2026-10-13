@extends('layouts.base')
@section('title', __('Customer display'))
@section('body_class', 'bg-navy-900 text-white')

@section('body')
{{-- Window opened from the POS and dragged onto the screen facing the guest; receives the cart via BroadcastChannel. --}}
<div class="flex min-h-dvh flex-col p-8">
    <header class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-4">
            @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="size-16 rounded-2xl bg-white object-contain p-1">@endif
            <h1 class="text-3xl font-extrabold">{{ tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name }}</h1>
        </div>
    </header>
    <ul class="flex-1 space-y-3 text-2xl" id="lines">
        <li class="pt-20 text-center text-white/50">{{ __('Welcome!') }}</li>
    </ul>
    <div class="mt-6 flex items-end justify-between border-t border-white/20 pt-6">
        <span class="text-2xl text-white/70">{{ __('Total') }}</span>
        <span class="text-6xl font-extrabold" id="total"></span>
    </div>
</div>
<script>
    (() => {
        const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
        const welcome = document.getElementById('lines').innerHTML;
        new BroadcastChannel('egmenu-pos').onmessage = ({ data }) => {
            const fmt = (n) => `${Math.round(n * 100) / 100} ${data.currency}`;
            document.getElementById('lines').innerHTML = data.lines.length
                ? data.lines.map((l) => `<li class="flex justify-between gap-4"><span><b>${l.qty}×</b> ${esc(l.name)}</span><span>${fmt(l.amount)}</span></li>`).join('')
                : welcome;
            document.getElementById('total').textContent = data.lines.length ? fmt(data.totals.total) : '';
        };
    })();
</script>
@endsection
