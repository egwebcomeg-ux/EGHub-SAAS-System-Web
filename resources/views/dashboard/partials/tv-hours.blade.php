{{-- Days and hours of a TV screen or a slide (Cairo time). Nothing ticked and no hours = always. --}}
@php
    $dayNames = [__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')];
    $chosenDays = $model?->days ?? [];
@endphp
<details @class(['rounded-xl border border-navy-100 px-3 py-2', 'sm:col-span-2' => $wide ?? false]) @if ($model?->hasHours()) open @endif>
    <summary class="cursor-pointer text-sm font-semibold text-navy-700">{{ $title ?? __('Days and hours (optional)') }}</summary>
    <p class="mt-1 text-xs text-navy-500">{{ __('Leave empty to show it all the time. Cairo time; it may cross midnight (e.g. 22:00 to 02:00).') }}</p>
    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1">
        @foreach ($dayNames as $i => $d)
            <label class="flex min-h-9 items-center gap-1.5 text-sm"><input type="checkbox" name="days[]" value="{{ $i }}" @checked(in_array($i, array_map('intval', $chosenDays), true)) class="size-4 accent-brand-600"> {{ $d }}</label>
        @endforeach
    </div>
    <div class="mt-2 grid grid-cols-2 gap-2">
        <div><label class="field-label">{{ __('From') }}</label><input class="field" type="time" name="starts_at" value="{{ $model?->starts_at ? substr($model->starts_at, 0, 5) : '' }}"></div>
        <div><label class="field-label">{{ __('To') }}</label><input class="field" type="time" name="ends_at" value="{{ $model?->ends_at ? substr($model->ends_at, 0, 5) : '' }}"></div>
    </div>
</details>
