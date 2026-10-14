{{-- The latte recipe → each sale deducts → chocolate sauce runs low → stock received. --}}
<x-landing.sc-window :title="__('Recipe') . ' · ' . __('Latte')" class="top-8 start-6 h-[220px] w-[220px]">
    <div class="space-y-1.5 p-3 text-[11px]">
        @foreach ([[__('Coffee beans'), __(':n g', ['n' => 18])], [__('Milk'), __(':n ml', ['n' => 200])], [__('Paper cup'), '1']] as $i => [$ing, $qty])
            <div data-show="1-" style="transition-delay: {{ $i * 300 }}ms" class="flex justify-between rounded-lg bg-navy-50 px-2 py-1.5"><span>{{ $ing }}</span><b>{{ $qty }}</b></div>
        @endforeach
        <div data-show="1-" style="transition-delay: 900ms" class="flex justify-between border-t border-navy-100 pt-1.5 font-extrabold"><span>{{ __('Cost') }}</span><span>18.3 {{ __('EGP') }}</span></div>
    </div>
</x-landing.sc-window>
<div data-show="2" class="sc-pop absolute top-[250px] start-12 flex items-center gap-2 rounded-full bg-navy-900 px-3 py-1.5 text-[11px] font-bold text-white"><x-landing.icon name="bag" class="size-4 text-brand-500" />{{ __('Sold: 1× Latte') }}</div>

@php
    $rows = [
        [__('Coffee beans'), ['1' => __(':n g', ['n' => '1,910']), '2-' => __(':n g', ['n' => '1,892'])], __(':n g', ['n' => 500]), false],
        [__('Milk'), ['1' => __(':n ml', ['n' => '9,160']), '2-' => __(':n ml', ['n' => '8,960'])], __(':n ml', ['n' => '3,000']), false],
        [__('Paper cup'), ['1' => '497', '2-' => '496'], '100', false],
        [__('Chocolate sauce'), ['1-3' => __(':n ml', ['n' => 120]), '4-' => __(':n ml', ['n' => '2,120'])], __(':n ml', ['n' => 200]), true],
    ];
@endphp
<x-landing.sc-window :title="__('Inventory')" class="top-8 end-6 h-[300px] w-[350px]">
    <div class="p-3 text-[11px]">
        <div class="grid grid-cols-[1fr_auto_auto] gap-x-3 border-b border-navy-100 pb-1 text-navy-500"><span>{{ __('Ingredient') }}</span><span>{{ __('In stock') }}</span><span>{{ __('Min.') }}</span></div>
        @foreach ($rows as [$ing, $vals, $min, $low])
            <div @if ($low) data-on="3" @endif class="grid grid-cols-[1fr_auto_auto] items-center gap-x-3 border-b border-navy-50 py-2 [&.is-on]:bg-red-50">
                <b>{{ $ing }}</b>
                <span data-on="{{ $low ? '4' : '2' }}" class="sc-flash rounded px-1 font-bold"><x-landing.swap :values="$vals" /></span>
                <span class="text-navy-500">{{ $min }}</span>
            </div>
        @endforeach
    </div>
    <div data-show="3" class="absolute inset-x-3 bottom-3 flex items-center gap-1.5 rounded-lg bg-red-50 px-2 py-1.5 text-[11px] font-bold text-red-700 ring-1 ring-red-200"><x-landing.icon name="bell" class="size-3.5" />{{ __('1 ingredient is running low') }}</div>
    <div data-show="4" class="sc-pop absolute inset-x-3 bottom-3 rounded-lg bg-emerald-50 px-2 py-1.5 text-[11px] font-bold text-emerald-700 ring-1 ring-emerald-200">{{ __('Received 2,000 ml · average cost updated') }}</div>
</x-landing.sc-window>
