{{--
    «مقاس الشاشة واتجاهها»: landscape or portrait (and which way a portrait TV is turned), the size and the distance from
    the guests, which set the text size (App\Support\ScreenShape), with «حجم الكلام» by hand and a live mini preview.
    $screen: the screen being set (null on a new pairing with no screen picked yet)
--}}
@php
    use App\Support\ScreenShape;
    $shape = old('shape', $screen ? ScreenShape::shapeOf($screen) : 'landscape');
    $turn = old('turn', $screen ? ScreenShape::turnOf($screen) : 'right');
    $size = (string) old('screen_size', $screen?->screen_size);
    $distance = (string) old('viewing_distance', $screen?->viewing_distance ?? 'mid');
    $manual = (bool) old('text_scale_manual', (bool) $screen?->text_scale);
    $percent = (int) old('text_scale', $screen ? ScreenShape::percent($screen) : 100);
    $chip = 'flex min-h-11 cursor-pointer items-center justify-center rounded-xl border border-navy-100 bg-white px-3 text-sm font-bold text-navy-800 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 has-[:checked]:text-brand-800 has-[:focus-visible]:ring-2';
@endphp
<div class="@container"><div class="grid gap-5 @2xl:grid-cols-[minmax(0,1fr)_260px]" data-shape-fields
     data-sizes='@json(ScreenShape::SIZES)' data-distances='@json(ScreenShape::DISTANCES)' data-min="{{ ScreenShape::MIN_SCALE }}" data-max="{{ ScreenShape::MAX_SCALE }}">
    <div class="space-y-5">
        <fieldset>
            <legend class="field-label">{{ __('How does the screen hang?') }}</legend>
            <div class="grid grid-cols-2 gap-3">
                @foreach (['landscape' => __('Landscape'), 'portrait' => __('Portrait')] as $value => $label)
                    <label class="flex cursor-pointer flex-col items-center gap-2 rounded-2xl border-2 border-navy-100 bg-white p-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 has-[:focus-visible]:ring-2" data-shape-tile="{{ $value }}">
                        <input type="radio" name="shape" value="{{ $value }}" class="sr-only" @checked($shape === $value)>
                        <span class="grid h-20 place-items-center" aria-hidden="true">
                            <span @class(['block rounded-md border-4 border-navy-800 bg-navy-100', 'h-12 w-20' => $value === 'landscape', 'h-20 w-12' => $value === 'portrait'])></span>
                        </span>
                        <span class="text-base font-extrabold text-navy-900">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            <div class="mt-3 rounded-xl bg-navy-50 p-3" data-turn @if ($shape !== 'portrait') hidden @endif>
                <p class="text-sm font-semibold text-navy-700">{{ __('Which way is the TV turned?') }}</p>
                <div class="mt-2 grid grid-cols-2 gap-2">
                    @foreach (['right' => __('Turned right'), 'left' => __('Turned left')] as $value => $label)
                        <label class="{{ $chip }}"><input type="radio" name="turn" value="{{ $value }}" class="sr-only" @checked($turn === $value)>{{ $label }}</label>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-navy-500">{{ __('If the menu shows upside down on the TV, choose the other one.') }}</p>
            </div>
        </fieldset>

        <fieldset>
            <legend class="field-label">{{ __('Screen size') }}</legend>
            <div class="grid grid-cols-4 gap-2 @lg:grid-cols-7">
                @foreach (ScreenShape::sizeLabels() as $value => $label)
                    <label @class([$chip, 'col-span-2 @lg:col-span-1' => $value === 'small' || $value === '75'])><input type="radio" name="screen_size" value="{{ $value }}" class="sr-only" @checked($size === (string) $value)><span >{{ $label }}</span></label>
                @endforeach
            </div>
        </fieldset>

        <fieldset>
            <legend class="field-label">{{ __('How far are the guests from it?') }}</legend>
            <div class="grid gap-2 @lg:grid-cols-3">
                @foreach (ScreenShape::distanceLabels() as $value => $label)
                    <label class="{{ $chip }}"><input type="radio" name="viewing_distance" value="{{ $value }}" class="sr-only" @checked($distance === $value)>{{ $label }}</label>
                @endforeach
            </div>
        </fieldset>

        <div>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="field-label mb-0">{{ __('Text size') }}: <b dir="ltr" data-scale-out>{{ $percent }}%</b></span>
                <label class="flex min-h-10 items-center gap-2 text-sm"><input type="checkbox" name="text_scale_manual" value="1" class="size-4 accent-brand-600" @checked($manual) data-scale-manual> {{ __('Set it myself') }}</label>
            </div>
            <input type="range" name="text_scale" min="{{ ScreenShape::MIN_SCALE }}" max="{{ ScreenShape::MAX_SCALE }}" step="5" value="{{ $percent }}" class="w-full accent-brand-600" dir="ltr" data-scale-range @disabled(! $manual) aria-label="{{ __('Text size') }}">
            <p class="text-xs text-navy-500">{{ __('Automatic from the size and the distance: a small or far screen gets bigger text and fewer items on each page, a big close one more items.') }}</p>
        </div>
    </div>

    <figure class="space-y-2">
        <figcaption class="text-xs font-bold text-navy-500">{{ __('Preview') }}</figcaption>
        <div class="grid h-60 place-items-center rounded-2xl bg-navy-50 p-3">
            <div @class(['overflow-hidden rounded-lg border-[6px] border-navy-900 bg-navy-800 text-white shadow-lg transition-all', 'h-[118px] w-[210px]' => $shape !== 'portrait', 'h-[210px] w-[118px]' => $shape === 'portrait']) data-shape-preview>
                <div class="h-full p-2" style="font-size: {{ round(9 * $percent / 100, 2) }}px" data-scale-preview>
                    <div class="font-extrabold text-brand-500" style="font-size: 1.3em">{{ __('Hot drinks') }}</div>
                    @foreach ([__('Latte'), __('Cappuccino'), __('Espresso'), __('Mocha'), __('Tea'), __('Hot chocolate'), __('Turkish coffee'), __('Flat white'), __('Americano'), __('Sahlab')] as $name)
                        <div class="mt-[.35em] flex items-center justify-between gap-2 leading-tight"><span class="truncate">{{ $name }}</span><span class="font-bold text-brand-200" dir="ltr">{{ 45 + $loop->index * 5 }}</span></div>
                    @endforeach
                </div>
            </div>
        </div>
    </figure>
</div></div>

@once
<script>
    // the screen's shape: portrait asks which way, the size and distance give the text size, the preview follows
    document.querySelectorAll('[data-shape-fields]').forEach((box) => {
        const sizes = JSON.parse(box.dataset.sizes);
        const distances = JSON.parse(box.dataset.distances);
        const [min, max] = [Number(box.dataset.min), Number(box.dataset.max)];
        const range = box.querySelector('[data-scale-range]');
        const manual = box.querySelector('[data-scale-manual]');
        const value = (name) => box.querySelector(`[name="${name}"]:checked`)?.value;
        const sync = () => {
            const portrait = value('shape') === 'portrait';
            box.querySelector('[data-turn]').hidden = !portrait;
            const tv = box.querySelector('[data-shape-preview]');
            tv.classList.toggle('h-[118px]', !portrait);
            tv.classList.toggle('w-[210px]', !portrait);
            tv.classList.toggle('h-[210px]', portrait);
            tv.classList.toggle('w-[118px]', portrait);
            range.disabled = !manual.checked;
            if (!manual.checked) {
                const auto = (sizes[value('screen_size')] ?? 100) * (distances[value('viewing_distance')] ?? 1);
                range.value = Math.max(min, Math.min(max, Math.round(auto / 5) * 5));
            }
            box.querySelector('[data-scale-out]').textContent = range.value + '%';
            box.querySelector('[data-scale-preview]').style.fontSize = (9 * range.value / 100).toFixed(2) + 'px';
        };
        box.addEventListener('change', sync);
        range.addEventListener('input', sync);
        sync();
    });
</script>
@endonce
