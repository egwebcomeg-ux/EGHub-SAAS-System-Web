{{--
    "Your next step" on the dashboard home (dashboard.home): above the apps while the setup checklist is open (a new
    account sees what to do first), in the row under the apps once setup is done. $nextStepFirst: it sits above.
--}}
@if ($showNextStep)
    {{--
        "Your next step": one card instead of three. While the setup checklist (App\Services\Onboarding, owners and
        managers) is open it shows the next unfinished steps, the progress and the whole list folded; picking the
        modules is the first step until it is done. After setup, the suggestions for the place. Folds on a tap, and
        the browser remembers that (resources/js/dash-home.js).
    --}}
    <section @class(['card p-0', 'xl:col-span-2' => ! ($nextStepFirst ?? false)]) @if ($settingUp) data-onboarding data-percent="{{ $onboarding['percent'] }}" @endif data-next-step-card>
        <details class="group/next" open data-next-step-body>
            <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 px-5 py-3 [&::-webkit-details-marker]:hidden">
                <span class="min-w-0">
                    <span class="block text-base font-bold">{{ __('Your next step') }}</span>
                    <span class="block truncate text-xs text-navy-500">
                        @if ($settingUp)
                            {{ __(':done of :total steps done', ['done' => $onboarding['done'], 'total' => $onboarding['total']]) }}
                        @elseif ($suggestions)
                            {{ __('Recommended for your :type', ['type' => \App\Support\BusinessType::label($businessType)]) }}
                        @else
                            {{ __('Tell us what you use, and we put it first in your dashboard.') }}
                        @endif
                    </span>
                </span>
                <span class="flex shrink-0 items-center gap-2">
                    @if ($settingUp)<span class="text-sm font-extrabold text-navy-700" dir="ltr">{{ $onboarding['percent'] }}%</span>@endif
                    <svg class="size-5 text-navy-400 transition group-open/next:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </span>
            </summary>
            <div class="px-5 pb-5">
                @if ($settingUp)
                    <div class="h-2 overflow-hidden rounded-full bg-navy-100" role="progressbar" aria-valuenow="{{ $onboarding['percent'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('Setup progress') }}">
                        <div class="h-full rounded-full bg-emerald-500 transition-all" style="width: {{ $onboarding['percent'] }}%"></div>
                    </div>
                @endif

                @if ($pickModules || $nextSteps)
                    <ol class="mt-3 space-y-2">
                        @if ($pickModules)
                            <li class="flex items-center gap-3 rounded-xl border border-brand-200 bg-brand-50/60 p-3" data-next-step="modules">
                                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-600 text-sm font-bold text-white">🧩</span>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-bold">{{ __('Pick your modules') }}</div>
                                    <div class="text-xs text-navy-600">{{ __('Tell us what you use, and we put it first in your dashboard.') }}</div>
                                </div>
                                <a href="{{ route('dash.modules') }}" class="btn-primary min-h-9 shrink-0 px-3 text-xs">{{ __('Pick') }}</a>
                            </li>
                        @endif
                        @foreach ($nextSteps as $step)
                            <li @class(['flex items-center gap-3 rounded-xl border p-3', 'border-brand-200 bg-brand-50/60' => ! $pickModules && $loop->first, 'border-navy-100' => $pickModules || ! $loop->first]) data-next-step="{{ $step['key'] }}">
                                <span @class(['grid size-8 shrink-0 place-items-center rounded-full text-sm font-bold', 'bg-brand-600 text-white' => ! $pickModules && $loop->first, 'bg-navy-100 text-navy-600' => $pickModules || ! $loop->first])>{{ $loop->iteration + ($pickModules ? 1 : 0) }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-bold">{{ $step['title'] }}</div>
                                    <div class="text-xs text-navy-600">{{ $step['text'] }}</div>
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    @if ($step['url'])<a href="{{ $step['url'] }}" class="btn-primary min-h-9 px-3 text-xs">{{ __('Go') }}</a>@endif
                                    <form method="post" action="{{ route('dash.onboarding.skip') }}">
                                        @csrf<input type="hidden" name="step" value="{{ $step['key'] }}"><button class="btn-ghost min-h-9 px-2.5 text-xs">{{ __('Skip') }}</button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif

                @if ($settingUp)
                    {{-- the whole checklist, folded: done, skipped and still to do --}}
                    <details class="mt-3 group/all" data-onboarding-all>
                        <summary class="flex min-h-10 cursor-pointer list-none items-center gap-1 text-sm font-semibold text-navy-600 hover:text-navy-900 [&::-webkit-details-marker]:hidden">
                            {{ __('All setup steps (:n)', ['n' => $onboarding['total']]) }}
                            <svg class="size-4 transition group-open/all:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                        </summary>
                        <ul class="mt-1 divide-y divide-navy-50">
                            @foreach ($onboarding['steps'] as $step)
                                <li class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5 sm:flex-nowrap" data-onboarding-step="{{ $step['key'] }}" data-state="{{ $step['done'] ? 'done' : ($step['skipped'] ? 'skipped' : 'todo') }}">
                                    <span @class(['grid size-6 shrink-0 place-items-center rounded-full text-xs font-bold', 'bg-emerald-500 text-white' => $step['done'], 'bg-navy-100 text-navy-500' => ! $step['done']])>{{ $step['done'] ? '✓' : ($step['skipped'] ? '–' : $loop->iteration) }}</span>
                                    <div class="min-w-0 flex-1 basis-40">
                                        <div @class(['text-sm font-semibold', 'text-navy-400 line-through' => $step['done'] || $step['skipped']])>{{ $step['title'] }}</div>
                                    </div>
                                    @if (! $step['done'] && ! $step['skipped'])
                                        <div class="ms-9 flex shrink-0 items-center gap-2 sm:ms-0">
                                            @if ($step['url'])<a href="{{ $step['url'] }}" class="btn-ghost min-h-9 px-3 text-xs">{{ __('Go') }}</a>@endif
                                            <form method="post" action="{{ route('dash.onboarding.skip') }}">
                                                @csrf<input type="hidden" name="step" value="{{ $step['key'] }}"><button class="min-h-9 px-2 text-xs text-navy-500 underline">{{ __('Skip') }}</button>
                                            </form>
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </details>
                    <form method="post" action="{{ route('dash.onboarding.dismiss') }}" class="mt-2">
                        @csrf<button class="min-h-9 text-xs font-semibold text-navy-500 underline">{{ __('Hide this checklist') }}</button>
                    </form>
                @endif

                @if ($suggestions)
                    <div class="mt-3 grid gap-2 sm:grid-cols-2" data-home-suggestions>
                        @foreach ($suggestions as $sug)
                            <div class="rounded-xl border border-navy-100 p-3">
                                <div class="flex items-center justify-between gap-2">
                                    <b class="text-sm">{{ $sug['title'] }}</b>
                                    @if ($suggestionsHas($sug))<span class="badge bg-emerald-100 text-emerald-800">{{ __('Included') }}</span>@endif
                                </div>
                                <p class="mt-1 text-xs text-navy-500">{{ $sug['text'] }}</p>
                                @unless ($suggestionsHas($sug))<a href="{{ route('dash.billing') }}" class="mt-2 inline-block text-xs font-semibold text-brand-700">{{ __('Add') }} ←</a>@endunless
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </details>
    </section>
@endif
