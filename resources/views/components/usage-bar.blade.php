@props(['label', 'used', 'limit'])
@php($pct = $limit === null ? 0 : ($limit === 0 ? 100 : min(100, (int) floor($used * 100 / $limit))))
<div>
    <div class="mb-1 flex items-baseline justify-between gap-2 text-sm">
        <span class="font-semibold text-navy-800">{{ $label }}</span>
        @if ($limit === null)
            <span class="text-xs text-navy-500">{{ $used }} · {{ __('Unlimited') }}</span>
        @else
            <span class="text-xs text-navy-500" dir="ltr">{{ $used }} / {{ $limit }}</span>
        @endif
    </div>
    @if ($limit !== null)
        <div class="h-1.5 overflow-hidden rounded-full bg-navy-100">
            <div class="h-full rounded-full {{ $pct >= 100 ? 'bg-amber-500' : 'bg-brand-500' }}" style="width: {{ max(2, $pct) }}%"></div>
        </div>
    @endif
</div>
