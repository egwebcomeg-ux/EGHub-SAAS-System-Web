{{--
    «نوع الشاشة»: brand tiles (names as text, no logos) with a search and «مش عارف». Picking a tile shows the matching
    [data-guide-for="{key}"] block on the page and hides the others (each page renders the guides it needs).
    $type: the key picked now (or null) · $suggested: it was guessed from the TV itself
--}}
@php
    use App\Support\ScreenDevices;
    $devices = ScreenDevices::all();
    $picked = old('device_type', $type);
@endphp
<div class="space-y-4" data-device-picker>
    @if (! empty($suggested) && $picked && isset($devices[$picked]))
        <p class="rounded-xl bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800" data-suggested="{{ $picked }}">
            {{ __('The TV that shows this code looks like :device. We picked it for you; change it if it is wrong.', ['device' => ScreenDevices::title($picked)]) }}
        </p>
    @endif
    <label class="block">
        <span class="sr-only">{{ __('Search for your screen') }}</span>
        <input type="search" class="field" placeholder="{{ __('Search: Samsung, شاومي, Fire TV…') }}" data-device-search autocomplete="off">
    </label>
    @foreach (ScreenDevices::groups() as $group => $heading)
        <fieldset data-device-group="{{ $group }}">
            <legend class="mb-2 text-xs font-extrabold tracking-wide text-navy-500 uppercase">{{ $heading }}</legend>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($devices as $key => $device)
                    @continue($device['group'] !== $group)
                    <label class="flex min-h-16 cursor-pointer flex-col justify-center rounded-xl border border-navy-100 bg-white px-3 py-2 transition hover:border-brand-300 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 has-[:checked]:ring-2 has-[:checked]:ring-brand-200 has-[:focus-visible]:ring-2"
                           data-device-tile="{{ $key }}" data-search="{{ mb_strtolower($device['name'].' '.$device['label'].' '.$device['search']) }}">
                        <input type="radio" name="device_type" value="{{ $key }}" class="sr-only" @checked($picked === $key)>
                        <span class="truncate text-base font-extrabold text-navy-900" dir="auto">{{ $device['name'] }}</span>
                        <span class="text-xs leading-snug text-navy-500">{{ $device['label'] }}</span>
                    </label>
                @endforeach
                @if ($loop->last)
                    <label class="flex min-h-16 cursor-pointer flex-col justify-center rounded-xl border border-dashed border-navy-300 bg-white px-3 py-2 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50"
                           data-device-tile="{{ ScreenDevices::UNKNOWN }}" data-search="{{ mb_strtolower(__('I do not know').' مش عارف unknown') }}">
                        <input type="radio" name="device_type" value="" class="sr-only" @checked($picked === ScreenDevices::UNKNOWN)>
                        <span class="text-base font-extrabold text-navy-900">{{ __('I do not know') }}</span>
                        <span class="text-xs leading-snug text-navy-500">{{ __('We help you find out') }}</span>
                    </label>
                @endif
            </div>
        </fieldset>
    @endforeach
    <p class="hidden text-sm text-navy-500" data-device-none>{{ __('Nothing matches. Try the brand name, or choose «I do not know».') }}</p>

    <section class="rounded-2xl border border-sky-200 bg-sky-50 p-4" data-guide-for="{{ ScreenDevices::UNKNOWN }}" hidden>
        <h3 class="font-extrabold text-navy-900">{{ __('How to tell what your screen is') }}</h3>
        <ul class="mt-2 list-disc space-y-1.5 ps-5 text-sm leading-relaxed text-navy-700">
            @foreach (ScreenDevices::identifyTips() as $tip)
                <li>{!! ScreenDevices::format($tip) !!}</li>
            @endforeach
        </ul>
    </section>
</div>

@once
<script>
    // the device picker: search the tiles, and show only the guide of the picked tile
    document.querySelectorAll('[data-device-picker]').forEach((picker) => {
        const search = picker.querySelector('[data-device-search]');
        const tiles = [...picker.querySelectorAll('[data-device-tile]')];
        const show = () => {
            const tile = tiles.find((t) => t.querySelector('input').checked);
            const key = tile ? tile.dataset.deviceTile : null;
            document.querySelectorAll('[data-guide-for]').forEach((el) => { el.hidden = el.dataset.guideFor !== key; });
            document.querySelectorAll('[data-guide-empty]').forEach((el) => { el.hidden = key !== null; });
        };
        tiles.forEach((t) => t.querySelector('input').addEventListener('change', () => {
            show();
            const guide = document.querySelector(`[data-guide-for="${t.dataset.deviceTile}"]`);
            if (guide && window.matchMedia('(max-width: 1023px)').matches) {
                guide.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }));
        search?.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            let any = false;
            tiles.forEach((t) => { const on = !q || t.dataset.search.includes(q); t.hidden = !on; any = any || on; });
            picker.querySelectorAll('[data-device-group]').forEach((g) => { g.hidden = ![...g.querySelectorAll('[data-device-tile]')].some((t) => !t.hidden); });
            picker.querySelector('[data-device-none]').classList.toggle('hidden', any);
        });
        show();
    });
</script>
@endonce
