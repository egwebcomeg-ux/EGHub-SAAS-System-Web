{{-- One menu for Sofra's two branches → a branch price → sold out in one branch → orders per branch. --}}
<div class="absolute top-4 start-1/2 z-10 -translate-x-1/2 rounded-full bg-navy-900 px-4 py-1.5 text-[12px] font-bold text-white rtl:translate-x-1/2">{{ __('Sofra') }} · {{ __('One menu') }}</div>
@foreach ([[__('Maadi'), 'start-6'], [__('Sheikh Zayed'), 'end-6']] as $b => [$branch, $pos])
    <x-landing.sc-window :title="__('Sofra') . ' · ' . $branch" class="top-14 {{ $pos }} h-[320px] w-[285px]">
        <div class="space-y-1.5 p-3 text-[11px]">
            @foreach ([[__('Mixed grill'), 320], [__('Shish tawook'), 185], [__('Fattoush'), 70], [__('Kunafa'), 95]] as $i => [$item, $price])
                @php
                    $priced = $b === 1 && $i === 0;
                    $soldOut = $b === 0 && $i === 3;
                @endphp
                <div @if ($priced) data-on="2" @elseif ($soldOut) data-on="3-" @endif class="sc-flash flex items-center gap-2 rounded-xl border border-navy-100 p-2 {{ $soldOut ? '[&.is-on]:opacity-50' : '' }}">
                    <span class="size-8 shrink-0 rounded-lg bg-[#f1dccb]"></span>
                    <b class="flex-1">{{ $item }}</b>
                    @if ($soldOut)<span data-show="3-" class="sc-fade rounded bg-red-50 px-1.5 font-bold text-red-700">{{ __('Sold out') }}</span>@endif
                    <b class="text-brand-700">@if ($priced)<x-landing.swap :values="['1' => '320', '2-' => '350']" />@else{{ $price }}@endif</b>
                </div>
            @endforeach
            <div data-show="4" style="transition-delay: {{ $b * 250 }}ms" class="mt-2 flex items-center justify-between rounded-xl bg-navy-900 px-3 py-2 text-white">
                <span>{{ __('Today') }}</span><b>{{ __(':n orders', ['n' => $b ? 51 : 38]) }} · {{ $b ? '9,870' : '7,240' }} {{ __('EGP') }}</b>
            </div>
        </div>
    </x-landing.sc-window>
@endforeach
