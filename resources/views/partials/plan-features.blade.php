{{-- Short feature list of $plan: its limits, then "Everything in <previous public plan>, plus" only what it adds. --}}
@php
    $ladder = $plans->filter(fn ($p) => $p->id === $plan->id || ($p->is_public && ! $p->isContactSales()))->values();
    $index = $ladder->search(fn ($p) => $p->id === $plan->id);
    $previous = $index > 0 ? $ladder[$index - 1] : null;
    $features = array_keys(array_filter($matrix[$plan->id]['features']));
    $added = $previous ? array_values(array_diff($features, array_keys(array_filter($matrix[$previous->id]['features'])))) : $features;
@endphp
<ul class="space-y-2 text-sm">
    @foreach (\App\Models\Plan::LIMITS as $key => $_)
        @php($v = $matrix[$plan->id]['limits'][$key])
        @continue($v === 0)
        <li class="flex items-center justify-between gap-2 border-b border-dashed border-navy-100 pb-1.5">
            <span class="text-navy-600">{{ \App\Models\Plan::label($key) }}</span>
            <b class="text-navy-900">{{ $v === null ? __('Unlimited') : $v }}</b>
        </li>
    @endforeach
</ul>
@if ($previous)
    <p class="mt-4 mb-2 text-sm font-bold text-navy-900">{{ __('Everything in :plan, plus:', ['plan' => $previous->displayName()]) }}</p>
@else
    <p class="mt-4 mb-2 text-sm font-bold text-navy-900">{{ __('Includes:') }}</p>
@endif
<ul class="space-y-1.5 text-sm">
    @foreach ($added as $key)
        <li class="flex items-start gap-2">
            <svg class="mt-1 size-4 shrink-0 text-emerald-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0z"/></svg>
            <span>{{ \App\Models\Plan::label($key) }}</span>
        </li>
    @endforeach
</ul>
