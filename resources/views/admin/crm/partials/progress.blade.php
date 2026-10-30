{{-- one person's month: progress bars against the targets and the pay estimate (TeamStats::card) --}}
@php
    $bar = fn (int $p) => $p >= 100 ? 'bg-emerald-600' : ($p >= 60 ? 'bg-navy-800' : 'bg-brand-600');
@endphp
<section class="card mb-4" data-progress-card="{{ $card['admin']->id }}">
    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="section-title">{{ $title ?? __('My month') }}</h2>
        <span class="text-xs text-navy-500">{{ \App\Support\SalesPlan::seatLabel($card['seat']) }}</span>
    </div>
    @if ($card['progress'] === [])
        <p class="text-sm text-navy-500">{{ __('No targets for this month.') }}</p>
    @else
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($card['progress'] as $metric => $percent)
                <div data-metric="{{ $metric }}">
                    <div class="mb-1 flex justify-between text-sm">
                        <span class="font-semibold text-navy-700">{{ \App\Models\SalesTarget::label($metric) }}@if ($metric === 'calls_per_day') <span class="text-xs text-navy-400">({{ __('today') }})</span>@endif</span>
                        <span class="font-black text-navy-900">{{ $card['done'][$metric] ?? 0 }} / {{ $card['targets'][$metric] }}</span>
                    </div>
                    <div class="h-2.5 overflow-hidden rounded-full bg-navy-100" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min(100, $percent) }}" aria-label="{{ \App\Models\SalesTarget::label($metric) }}">
                        <div class="h-full rounded-full {{ $bar($percent) }}" style="width: {{ min(100, $percent) }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
    @if ($card['pay']['lines'])
        <details class="mt-3 rounded-xl bg-navy-50 p-3 text-sm" data-pay>
            <summary class="cursor-pointer font-bold text-navy-800">{{ __('Pay estimate this month: :total EGP', ['total' => money($card['pay']['total'])]) }}</summary>
            <ul class="mt-2 space-y-1">
                @foreach ($card['pay']['lines'] as [$label, $amount])
                    <li class="flex justify-between gap-3"><span>{{ $label }}</span><span class="font-bold">{{ money($amount) }}</span></li>
                @endforeach
            </ul>
            <p class="mt-2 text-xs text-navy-500">{{ __('An estimate from what is logged in the system. Commission is final after the refund period and is paid on the :d of the next month.', ['d' => (int) \App\Support\SalesPlan::get('payday')]) }}</p>
        </details>
    @endif
</section>
