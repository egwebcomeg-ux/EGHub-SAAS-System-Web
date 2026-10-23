{{-- The owner writes "Do you deliver to Maadi?" → marks it public → Mona finds it on a call and copies it → guests read it on the menu. --}}
<x-landing.sc-window :title="__('Knowledge base')" class="top-6 start-6 h-[350px] w-[330px]">
    <div class="space-y-2 p-3 text-[11px]">
        {{-- 1-2: the answer being written --}}
        <div data-show="1-2" class="sc-fade space-y-2 rounded-xl border border-navy-100 p-2.5">
            <div class="font-bold text-navy-500">{{ __('The question') }}</div>
            <div class="rounded-lg border border-navy-200 px-2 py-1.5 font-bold">{{ __('Do you deliver to Maadi?') }}</div>
            <div class="font-bold text-navy-500">{{ __('The answer') }}</div>
            <div class="rounded-lg border border-navy-200 px-2 py-1.5 leading-relaxed">{{ __('Yes: the delivery fee is 25 EGP and it takes about 40 minutes.') }}</div>
            <div class="grid grid-cols-2 gap-1.5">
                <div data-on="1" class="rounded-lg border border-navy-200 p-1.5 text-center font-bold [&.is-on]:border-brand-500 [&.is-on]:bg-brand-50">🔒 {{ __('Staff only') }}</div>
                <div data-on="2" class="rounded-lg border border-navy-200 p-1.5 text-center font-bold [&.is-on]:border-brand-500 [&.is-on]:bg-brand-50">🌐 {{ __('Staff and guests') }}</div>
            </div>
            <span data-show="2" class="sc-tap" style="top: 168px; inset-inline-end: 60px"></span>
        </div>
        {{-- 3-: the list it joined --}}
        <div data-show="3-" class="sc-fade space-y-1.5">
            @foreach ([[__('Do you deliver to Maadi?'), true], [__('What are your opening hours?'), true], [__('A guest complains about an order: what do I do?'), false]] as [$question, $public])
                <div class="flex items-center justify-between gap-2 rounded-lg border border-navy-100 px-2 py-1.5">
                    <b class="truncate">{{ $question }}</b>
                    <span @class(['shrink-0 rounded-full px-2 py-0.5 font-bold', 'bg-emerald-100 text-emerald-800' => $public, 'bg-navy-100 text-navy-600' => ! $public])>{{ $public ? '🌐' : '🔒' }}</span>
                </div>
            @endforeach
        </div>
        {{-- 3: the call-center drawer --}}
        <div data-show="3" class="sc-pop rounded-xl bg-navy-900 p-2.5 text-white">
            <div class="flex items-center gap-1.5 font-bold"><x-landing.icon name="phone" class="size-4 text-brand-500" />{{ __('Call center') }} · {{ __('Mona') }}</div>
            <div class="mt-1.5 rounded-lg bg-white/10 px-2 py-1">🔎 {{ __('Maadi') }}</div>
            <div class="mt-1.5 leading-relaxed text-white/80">{{ __('Yes: the delivery fee is 25 EGP and it takes about 40 minutes.') }}</div>
            <div class="mt-1.5 inline-block rounded-lg bg-brand-600 px-2 py-1 font-bold">📋 {{ __('Copied ✓') }}</div>
        </div>
    </div>
</x-landing.sc-window>

{{-- 4: the guest's FAQ on the menu --}}
<x-landing.sc-phone class="top-6 end-6">
    <div class="absolute inset-0 bg-[#fbf7f1] p-2 text-[10px]">
        <div class="mt-3 px-1 text-xs font-extrabold">{{ __('Frequently asked questions') }}</div>
        <div class="mt-2 space-y-1.5">
            <div data-on="4" class="rounded-xl border border-navy-100 bg-white p-2 [&.is-on]:border-brand-300">
                <div class="flex justify-between font-bold"><span>{{ __('Do you deliver to Maadi?') }}</span><span>＋</span></div>
                <div data-show="4" class="sc-fade mt-1 leading-relaxed text-navy-600">{{ __('Yes: the delivery fee is 25 EGP and it takes about 40 minutes.') }}</div>
            </div>
            <div class="flex justify-between rounded-xl border border-navy-100 bg-white p-2 font-bold"><span>{{ __('What are your opening hours?') }}</span><span>＋</span></div>
            <div class="flex justify-between rounded-xl border border-navy-100 bg-white p-2 font-bold"><span>{{ __('Is there parking?') }}</span><span>＋</span></div>
        </div>
        <span data-show="4" class="sc-tap" style="top: 62px; inset-inline-start: 70px"></span>
    </div>
</x-landing.sc-phone>
