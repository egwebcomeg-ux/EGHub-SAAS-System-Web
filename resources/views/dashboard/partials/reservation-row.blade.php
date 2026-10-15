@php
    $withDate ??= false;
@endphp
<article class="flex flex-wrap items-center gap-3 rounded-xl border border-navy-100 bg-navy-50/40 p-3">
    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2">
            <span class="font-extrabold">{{ $r->name }}</span>
            <span class="badge bg-white text-navy-700 ring-1 ring-navy-100">👥 {{ $r->party_size }}</span>
            <span class="badge {{ $tones[$r->status] ?? 'bg-navy-100' }}">{{ $r->statusLabel() }}</span>
            @if ($r->source === 'staff')<span class="text-xs text-navy-500">{{ __('by staff') }}</span>@endif
        </div>
        <div class="mt-0.5 text-sm text-navy-600">
            @if ($withDate)<span class="font-semibold" dir="ltr">{{ local_time($r->starts_at, 'd/m h:i A') }}</span> ·@endif
            <a href="tel:{{ $r->phone }}" dir="ltr" class="font-semibold">{{ $r->phone }}</a>
            @if ($branches->count() > 1 && $r->branch) · {{ tr($r->branch->name, $r->branch->name_en) }}@endif
        </div>
        @if ($r->notes)<p class="mt-1 text-sm text-brand-700">{{ $r->notes }}</p>@endif
    </div>
    <div class="flex flex-wrap gap-1.5">
        @foreach ($actions as $to => $label)
            @if ($r->canMoveTo($to))
                <form method="post" action="{{ route('dash.reservations.status', $r) }}" @if (in_array($to, ['cancelled', 'no_show'], true)) onsubmit="return confirm(@js(__('Are you sure?')))" @endif>
                    @csrf
                    <input type="hidden" name="status" value="{{ $to }}">
                    <button @class(['min-h-9 rounded-lg px-3 text-sm font-bold', 'btn-primary' => $to === 'confirmed' || ($to === 'seated' && $r->status === 'confirmed'),
                        'btn-ghost' => !in_array($to, ['confirmed'], true) && !($to === 'seated' && $r->status === 'confirmed'), 'text-red-600' => $to === 'cancelled'])>{{ $label }}</button>
                </form>
            @endif
        @endforeach
    </div>
</article>
