{{-- Turn on French and Russian → one click translates the items → fix one name by hand → a tourist picks Russian. --}}
<x-landing.sc-window :title="__('Menu languages')" class="top-6 start-5 h-[350px] w-[380px]">
    <div class="space-y-2.5 p-3 text-[11px]">
        <div class="grid grid-cols-2 gap-1.5">
            @foreach (['Français' => true, 'Русский' => true, 'Italiano' => false, 'Deutsch' => false] as $lang => $picked)
                <div @if ($picked) data-on="1-" @endif lang="{{ ['Français' => 'fr', 'Русский' => 'ru', 'Italiano' => 'it', 'Deutsch' => 'de'][$lang] }}" class="flex items-center gap-2 rounded-lg border border-navy-200 px-2 py-1.5 font-bold [&.is-on]:border-brand-500 [&.is-on]:bg-brand-50">
                    <span @class(['grid size-4 place-items-center rounded border', 'border-navy-300' => !$picked, 'border-brand-600 bg-brand-600 text-white' => $picked])>@if ($picked)<x-landing.icon name="check" class="size-3" />@endif</span>{{ $lang }}
                </div>
            @endforeach
        </div>
        <div class="relative rounded-xl border border-navy-100 p-2.5">
            <div class="flex items-center justify-between">
                <b>{{ __('Translate with AI') }}</b>
                <span data-on="2" class="flex items-center gap-1 rounded-lg bg-navy-100 px-2.5 py-1 font-bold text-navy-600 [&.is-on]:bg-brand-600 [&.is-on]:text-white"><x-landing.icon name="sparkles" class="size-3.5" />{{ __('Translate') }}</span>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-navy-100"><div data-on="2-" class="h-full w-[6%] rounded-full bg-brand-600 [&.is-on]:w-full"></div></div>
            <div class="mt-1 text-navy-500"><x-landing.swap :values="['1' => __('Missing: :n items', ['n' => 42]), '2-' => __('All :n items translated', ['n' => 42])]" /></div>
            <span data-show="2" class="sc-tap" style="top: 18px; inset-inline-end: 34px"></span>
        </div>
        <div data-show="3-" class="rounded-xl border border-navy-100 p-2.5">
            <div class="flex items-center justify-between"><b>{{ __('Latte') }}</b><x-landing.icon name="pencil" class="size-3.5 text-navy-400" /></div>
            <div class="mt-1.5 grid grid-cols-[auto_1fr] gap-x-2 gap-y-1" dir="ltr">
                <span class="text-navy-400">FR</span><span data-on="3" lang="fr" class="rounded px-1 [&.is-on]:bg-amber-100"><x-landing.swap :values="['3' => 'Latté', '4-' => 'Café latte']" /></span>
                <span class="text-navy-400">RU</span><span lang="ru">Латте</span>
            </div>
        </div>
    </div>
</x-landing.sc-window>

<x-landing.sc-phone class="top-6 end-6">
    <div class="flex items-center justify-between bg-[#4a3426] px-3 pt-6 pb-3 text-white">
        <div class="text-sm font-extrabold">{{ __('Rosto') }}</div>
        <span data-on="4" class="flex items-center gap-1 rounded-full bg-white/15 px-2 py-0.5 text-[10px] font-bold [&.is-on]:bg-white [&.is-on]:text-[#4a3426]"><x-landing.icon name="globe" class="size-3" /><x-landing.swap :values="['1-3' => 'ع', '4-' => 'RU']" /></span>
    </div>
    <div class="space-y-1.5 p-2">
        @foreach ([[__('Latte'), 'Латте', 65], [__('Cappuccino'), 'Капучино', 65], [__('Cheesecake'), 'Чизкейк', 90], [__('Brownies'), 'Брауни', 70]] as [$item, $ru, $price])
            <div class="flex items-center gap-2 rounded-xl bg-white p-1.5 ring-1 ring-navy-100">
                <span class="size-8 shrink-0 rounded-full bg-[#ead9c6]"></span>
                <span class="min-w-0 flex-1"><b class="block truncate text-[11px]"><x-landing.swap :values="['1-3' => $item, '4-' => $ru]" /></b><span class="text-[10px] font-bold text-brand-700">{{ $price }} <x-landing.swap :values="['1-3' => __('EGP'), '4-' => 'EGP']" /></span></span>
            </div>
        @endforeach
    </div>
    <div data-show="4" class="absolute end-2 top-14 z-10 w-[110px] rounded-xl bg-white p-1 text-[10px] font-bold shadow-xl ring-1 ring-navy-100" dir="ltr">
        @foreach (['العربية', 'English', 'Français', 'Русский'] as $l)
            <div @class(['rounded-lg px-2 py-1', 'bg-brand-600 text-white' => $l === 'Русский'])>{{ $l }}</div>
        @endforeach
    </div>
    <span data-show="4" class="sc-tap" style="top: 146px; inset-inline-end: 50px"></span>
</x-landing.sc-phone>
