{{--
    Home page: from a paper menu to guests ordering, in five steps. The steps sit on the start side; a dark device on
    the end side shows each one (real screens, and a small drawing of the AI reading a photo of a menu). landing.js
    plays it (data-walk): every ~4 s while it is on screen, paused under the pointer or keyboard focus and by the
    pause button. Without JS, or with reduced motion, it is a plain list and the buttons switch the screen.
--}}
@php
    $walkSteps = [
        [__('Sign up free'), __('With your email or your Google account, in a minute. No card.'), 'shot', 'dash-home', 'app.egmenu.app'],
        [__('Upload your menu'), __('A photo or a PDF of the paper menu, or an Excel sheet.'), 'shot', 'import', 'app.egmenu.app/menu/import'],
        [__('AI reads it for you'), __('Items, prices and sections, and your brand colours and font too. You check everything before it is saved.'), 'ai', null, 'app.egmenu.app/menu/import'],
        [__('Print the QR codes'), __('A code for every table, ready to print and stick.'), 'shot', 'tables', 'app.egmenu.app/tables'],
        [__('Guests order'), __('They scan, order from their phone, and the order lands on your board.'), 'order', 'orders', 'app.egmenu.app/orders'],
    ];
    $walkCount = count($walkSteps);
    // the drawing in step 3: what the AI pulled out of the photo (an example)
    $aiRows = [[__('Latte'), 65], [__('Cappuccino'), 65], [__('Iced Spanish latte'), 85], [__('Cheesecake'), 90]];
@endphp
<section id="start" class="py-16 lg:py-28" aria-labelledby="start-title">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <div class="max-w-3xl" data-reveal>
            <p class="hm-kicker">{{ __('Coming from a paper menu or a PDF?') }}</p>
            <h2 id="start-title" class="hm-h2 mt-4">{{ __('Your menu online in minutes.') }}</h2>
            <p class="lp-lead mt-4 max-w-2xl">{{ __('Take a photo of the paper menu or upload the PDF, and AI reads the items and prices. Nothing is saved before you check it.') }}</p>
        </div>

        <div data-walk data-ms="4200" class="hm-walk mt-10 grid gap-8 lg:mt-14 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:items-start lg:gap-12">
            <ol class="hm-walk-steps" aria-label="{{ __('Steps') }}">
                @foreach ($walkSteps as $i => [$stepTitle, $stepText])
                    <li>
                        <button type="button" data-walk-step="{{ $i }}" aria-controls="walk-screen" aria-current="{{ $i === 0 ? 'step' : 'false' }}" @class(['hm-walk-step', 'is-on' => $i === 0])>
                            <span class="hm-num text-sm text-brand-700">{{ sprintf('%02d', $i + 1) }}</span>
                            <span class="min-w-0">
                                <b class="block text-[1.05rem] font-extrabold text-navy-900">{{ $stepTitle }}</b>
                                <span class="mt-1 block leading-relaxed text-navy-500">{{ $stepText }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
                <li class="hm-walk-note">{{ __('AI import comes with the Complete plan and its :days-day trial; the free plan imports from Excel. Ordering from the table is in the Complete plan.', ['days' => \App\Services\TenantProvisioner::TRIAL_DAYS]) }}</li>
            </ol>

            <div class="max-lg:order-first lg:sticky lg:top-28">
                <div class="hm-device" id="walk-screen">
                    <span class="hm-device-bar" aria-hidden="true"><i data-walk-bar></i></span>
                    <div class="hm-device-screens">
                        @foreach ($walkSteps as $i => [$stepTitle, , $kind, $shot, $url])
                            <div data-walk-screen="{{ $i }}" @class(['hm-device-screen', 'is-on' => $i === 0]) @if ($i !== 0) aria-hidden="true" @endif>
                                @if ($kind === 'ai')
                                    {{-- drawn, not a screenshot: a photo of a paper menu on one side, what the AI read on the other --}}
                                    <div class="lp-browser" role="img" aria-label="{{ __('AI reading a photo of a paper menu (illustration)') }}">
                                        <div class="lp-browser-bar" aria-hidden="true"><i></i><i></i><i></i><span class="lp-browser-url">{{ $url }}</span></div>
                                        <div class="hm-ai" aria-hidden="true">
                                            <div class="hm-ai-paper">
                                                <span class="hm-ai-scan"></span>
                                                <b>{{ __('Rosto') }}</b>
                                                @foreach ($aiRows as [$aiName, $aiPrice])
                                                    <span class="hm-ai-line"><i style="width: {{ 42 + ($loop->index * 9) % 30 }}%"></i><i></i></span>
                                                @endforeach
                                                <span class="hm-ai-line"><i style="width: 38%"></i><i></i></span>
                                            </div>
                                            <div class="hm-ai-out">
                                                <span class="text-[11px] font-extrabold text-navy-400">{{ __('Hot coffee') }}</span>
                                                @foreach ($aiRows as [$aiName, $aiPrice])
                                                    <span class="hm-ai-row" style="--i: {{ $loop->index }}"><x-landing.icon name="check" class="size-3.5 shrink-0 text-emerald-600" /><span class="flex-1 truncate">{{ $aiName }}</span><b dir="ltr">{{ $aiPrice }}</b></span>
                                                @endforeach
                                                <span class="hm-ai-row hm-ai-brand" style="--i: 4"><span class="flex-1">{{ __('Brand colours') }}</span><i style="background:#4a3426"></i><i style="background:#c8a27a"></i><i style="background:#fbf7f1"></i></span>
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <x-landing.shot :name="$shot" :url="$url" :alt="$stepTitle . ' — ' . __('real screen')" sizes="(min-width: 1024px) 640px, 92vw" />
                                    @if ($kind === 'order')
                                        <x-landing.shot name="m-cart" frame="phone" :alt="__('The cart on a guest’s phone (real screen)')" sizes="150px" class="hm-device-phone" />
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="hm-device-foot">
                        <b data-walk-caption>{{ $walkSteps[0][0] }}</b>
                        <span class="hm-num text-white/50" dir="ltr"><span data-walk-count>01</span> / {{ sprintf('%02d', $walkCount) }}</span>
                    </div>
                </div>
                <div class="mt-5 flex items-center justify-center gap-1.5" data-walk-controls>
                    <button type="button" data-walk-toggle class="hm-walk-dot hm-walk-toggle" aria-pressed="false" aria-label="{{ __('Pause') }}" data-pause="{{ __('Pause') }}" data-play="{{ __('Play') }}">
                        <svg viewBox="0 0 16 16" class="size-3.5" aria-hidden="true" data-icon="pause"><path fill="currentColor" d="M4 3h3v10H4zM9 3h3v10H9z"/></svg>
                        <svg viewBox="0 0 16 16" class="size-3.5 rtl:-scale-x-100" aria-hidden="true" data-icon="play" hidden><path fill="currentColor" d="M4 2.5v11l9-5.5z"/></svg>
                    </button>
                    @foreach ($walkSteps as $i => [$stepTitle])
                        <button type="button" data-walk-step="{{ $i }}" aria-controls="walk-screen" aria-label="{{ __('Step :n', ['n' => $i + 1]) }}: {{ $stepTitle }}" @class(['hm-walk-dot hm-num', 'is-on' => $i === 0])>{{ sprintf('%02d', $i + 1) }}</button>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>
