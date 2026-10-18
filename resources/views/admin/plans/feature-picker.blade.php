{{--
    Feature checkboxes grouped by Plan::GROUPS, with a filter box, a selected counter and per-group "all / none".
    $name: the input name (e.g. "features[]"); $selected: the ticked feature keys.
--}}
@php
    $pickerId = 'fp-' . substr(md5($name), 0, 8);
@endphp
<div class="space-y-3" data-feature-picker id="{{ $pickerId }}">
    <div class="flex flex-wrap items-center gap-2">
        <input type="search" class="field min-w-0 flex-1 sm:max-w-xs" placeholder="{{ __('Filter features') }}" aria-label="{{ __('Filter features') }}" data-feature-filter>
        <span class="badge bg-navy-100 text-navy-700" data-feature-count-wrap>{{ __('Selected') }}: <span class="ms-1" data-feature-count>{{ count($selected) }}</span> / {{ count(\App\Models\Plan::FEATURES) }}</span>
    </div>
    <div class="grid gap-3 md:grid-cols-2">
        @foreach (\App\Models\Plan::GROUPS as $group => $gl)
            @php($keys = array_keys(array_filter(\App\Models\Plan::FEATURES, fn ($f) => $f[2] === $group)))
            <fieldset class="rounded-xl border border-navy-100 p-3" data-feature-group>
                <legend class="sr-only">{{ app()->getLocale() === 'en' ? $gl[1] : $gl[0] }}</legend>
                <div class="mb-1 flex items-center justify-between gap-2">
                    <span class="text-sm font-bold text-navy-700">{{ app()->getLocale() === 'en' ? $gl[1] : $gl[0] }} <span class="font-normal text-navy-500">(<span data-group-count>{{ count(array_intersect($keys, $selected)) }}</span>/{{ count($keys) }})</span></span>
                    <span class="flex gap-1 text-xs">
                        <button type="button" class="rounded-lg px-2 py-1 font-semibold text-navy-600 hover:bg-navy-50" data-group-all>{{ __('All') }}</button>
                        <button type="button" class="rounded-lg px-2 py-1 font-semibold text-navy-500 hover:bg-navy-50" data-group-none>{{ __('None') }}</button>
                    </span>
                </div>
                @foreach ($keys as $key)
                    <label class="flex min-h-9 items-center gap-2 rounded-lg px-1 text-sm hover:bg-navy-50/60" data-feature-row data-search="{{ mb_strtolower(\App\Models\Plan::FEATURES[$key][0] . ' ' . \App\Models\Plan::FEATURES[$key][1] . ' ' . $key) }}">
                        <input type="checkbox" name="{{ $name }}" value="{{ $key }}" @checked(in_array($key, $selected, true)) class="size-4 shrink-0 accent-brand-600" data-feature>
                        <span>{{ \App\Models\Plan::label($key) }}</span>
                    </label>
                @endforeach
            </fieldset>
        @endforeach
    </div>
</div>
@once
    @push('scripts')
    <script>
        document.querySelectorAll('[data-feature-picker]').forEach((picker) => {
            const recount = () => {
                picker.querySelector('[data-feature-count]').textContent = picker.querySelectorAll('[data-feature]:checked').length;
                picker.querySelectorAll('[data-feature-group]').forEach((g) => { g.querySelector('[data-group-count]').textContent = g.querySelectorAll('[data-feature]:checked').length; });
            };
            picker.addEventListener('change', recount);
            picker.querySelectorAll('[data-feature-group]').forEach((g) => {
                const set = (on) => { g.querySelectorAll('[data-feature-row]:not(.hidden) [data-feature]').forEach((b) => { b.checked = on; }); recount(); };
                g.querySelector('[data-group-all]').addEventListener('click', () => set(true));
                g.querySelector('[data-group-none]').addEventListener('click', () => set(false));
            });
            picker.querySelector('[data-feature-filter]').addEventListener('input', (e) => {
                const q = e.target.value.trim().toLowerCase();
                picker.querySelectorAll('[data-feature-group]').forEach((g) => {
                    let shown = 0;
                    g.querySelectorAll('[data-feature-row]').forEach((row) => {
                        const match = q === '' || row.dataset.search.includes(q);
                        row.classList.toggle('hidden', !match);
                        shown += match ? 1 : 0;
                    });
                    g.classList.toggle('hidden', shown === 0);
                });
            });
        });
    </script>
    @endpush
@endonce
