{{--
    «بتعرض دلوقتي»: a screen's status and the slide each of its areas shows now, as the player reported it (no screenshot:
    a small drawing of the slide). Refreshed every 15 s by dashboard.partials.tv-live-refresh. $nowSlides = slides by id
    (loaded here when null).
--}}
@php
    $shown = array_diff_key((array) ($screen->now_showing ?? []), ['at' => 0]);
    $nowSlides ??= \App\Models\TvSlide::query()->whereIn('id', array_filter(array_values($shown)))->with(['media', 'item', 'offer'])->get()->keyBy('id');
    $fresh = $screen->isOnline() && $shown !== [];
    $info = $screen->device_info ?? [];
@endphp
<div class="space-y-2" data-tv-now="{{ $screen->id }}">
    @include('dashboard.partials.tv-status', ['screen' => $screen])
    @if ($fresh)
        <div class="flex flex-wrap gap-2">
            @foreach (['main', 'side'] as $zone)
                @continue(! array_key_exists($zone, $shown))
                @php
                    $s = $nowSlides->get($shown[$zone]);
                @endphp
                <div class="flex min-w-0 flex-1 items-center gap-2 rounded-lg bg-navy-50 p-1.5" data-now-zone="{{ $zone }}" data-now-slide="{{ $s?->id ?? 0 }}">
                    <span class="relative grid aspect-video w-20 shrink-0 place-items-center overflow-hidden rounded-md bg-navy-900 text-[10px] font-bold text-white">
                        @if ($s && in_array($s->type, ['image', 'video'], true) && $s->media)
                            @if ($s->media->isVideo())<video src="{{ $s->media->url() }}#t=1" muted preload="metadata" class="absolute inset-0 h-full w-full object-cover"></video>@else<img src="{{ $s->media->url() }}" alt="" class="absolute inset-0 h-full w-full object-cover">@endif
                        @elseif ($s && $s->type === 'promo')
                            @if ($s->item?->image)<img src="/storage/{{ $s->item->image }}" alt="" class="absolute inset-0 h-full w-full object-cover opacity-60">@endif
                            <span class="relative px-1 text-center leading-tight">{{ \App\Support\ScreenDesigns::label($s->template) }}</span>
                        @elseif ($s && $s->type === 'menu')
                            <span class="absolute inset-1 space-y-0.5" aria-hidden="true">@for ($l = 0; $l < 5; $l++)<span class="flex justify-between"><span class="h-1 w-8 rounded bg-white/50"></span><span class="h-1 w-3 rounded bg-brand-400"></span></span>@endfor</span>
                        @elseif ($s && $s->type === 'ready')
                            <span class="text-base">42</span>
                        @elseif ($s && $s->type === 'offer')
                            <span class="text-base text-brand-300">%</span>
                        @else
                            <span>EgHub</span>
                        @endif
                    </span>
                    <span class="min-w-0 text-xs">
                        <span class="block font-bold text-navy-800">{{ __('On screen now') }}{{ array_key_exists('side', $shown) ? ' · ' . ($zone === 'side' ? __('Side part') : __('Main part')) : '' }}</span>
                        <span class="block truncate text-navy-600">{{ $s ? \App\Models\TvSlide::typeLabel($s->type) . match ($s->type) {
                            'image', 'video' => ' · ' . ($s->media?->name ?: ''),
                            'promo' => ' · ' . ($s->option('title') ?: ($s->item?->name ?: '')),
                            'offer' => $s->offer ? ' · ' . $s->offer->title : '',
                            default => '',
                        } : __('The logo between slides') }}</span>
                    </span>
                </div>
            @endforeach
        </div>
    @endif
    @if ($screen->isPaired() && ! empty($info['app']))
        <p class="text-xs text-navy-500" dir="auto">{{ __('Player version') }} <span dir="ltr">{{ $info['app'] }}</span>{{ ! empty($info['device']) ? ' · ' . $info['device'] : '' }}</p>
    @endif
</div>
