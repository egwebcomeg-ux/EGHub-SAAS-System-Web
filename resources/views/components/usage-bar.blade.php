@props(['label', 'used', 'limit'])
@php($pct = $limit === null ? 0 : ($limit === 0 ? 100 : min(100, (int) floor($used * 100 / $limit))))
<div>
    <div class="mb-1 flex justify-between text-sm">
        <span class="font-semibold">{{ $label }}</span>
        <span class="text-navy-500" dir="ltr">{{ $used }} / {{ $limit === null ? '∞' : $limit }}</span>
    </div>
    <div class="h-2 overflow-hidden rounded-full bg-navy-100">
        <div class="h-full rounded-full {{ $pct >= 90 ? 'bg-red-500' : 'bg-brand-500' }}" style="width: {{ $limit === null ? 4 : $pct }}%"></div>
    </div>
</div>
