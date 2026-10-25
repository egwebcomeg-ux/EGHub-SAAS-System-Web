{{--
    Tick screens (screen_ids[]): one by one, or all at once, a whole branch or a whole group with the quick buttons.
    $screens are already limited to the screens this user manages; $checked = ids ticked at first.
--}}
@php
    $byBranch = $screens->filter(fn ($s) => $s->branch)->groupBy('branch_id');
    $byGroup = $screens->filter(fn ($s) => $s->group)->groupBy('tv_group_id');
    $pickerId = 'pick-' . \Illuminate\Support\Str::random(6);
@endphp
<div class="space-y-2" data-screen-picker id="{{ $pickerId }}">
    <div class="flex flex-wrap gap-1.5 text-xs">
        <button type="button" class="btn-ghost min-h-9 px-3" data-pick="all">{{ __('All screens') }}</button>
        <button type="button" class="btn-ghost min-h-9 px-3" data-pick="none">{{ __('None') }}</button>
        @if ($byBranch->count() > 1)
            @foreach ($byBranch as $branchId => $list)
                <button type="button" class="btn-ghost min-h-9 px-3" data-pick="branch-{{ $branchId }}">{{ __('Branch') }}: {{ tr($list->first()->branch->name, $list->first()->branch->name_en) }}</button>
            @endforeach
        @endif
        @foreach ($byGroup as $groupId => $list)
            <button type="button" class="btn-ghost min-h-9 px-3" data-pick="group-{{ $groupId }}">{{ __('Group') }}: {{ $list->first()->group->name }}</button>
        @endforeach
    </div>
    <div @class(["grid gap-1.5", "sm:grid-cols-2" => ! ($narrow ?? false)])>
        @foreach ($screens as $s)
            <label class="flex min-h-11 items-center gap-2 rounded-lg border border-navy-100 px-3 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                <input type="checkbox" name="screen_ids[]" value="{{ $s->id }}" @checked(in_array($s->id, $checked)) class="size-5 accent-brand-600"
                    data-branch="branch-{{ $s->branch_id }}" data-group="group-{{ $s->tv_group_id }}">
                <span class="min-w-0 flex-1 truncate font-semibold">{{ $s->name }}</span>
                <span class="shrink-0 text-xs text-navy-500">{{ $s->branch ? tr($s->branch->name, $s->branch->name_en) : '' }}</span>
                @include('dashboard.partials.tv-dot', ['screen' => $s])
            </label>
        @endforeach
    </div>
</div>
<script>
    (() => {
        const box = document.getElementById(@js($pickerId));
        box.querySelectorAll('[data-pick]').forEach((b) => b.addEventListener('click', () => {
            const key = b.dataset.pick;
            box.querySelectorAll('input[name="screen_ids[]"]').forEach((c) => {
                if (key === 'all') { c.checked = true; } else if (key === 'none') { c.checked = false; } else if (c.dataset.branch === key || c.dataset.group === key) { c.checked = true; }
            });
        }));
    })();
</script>
