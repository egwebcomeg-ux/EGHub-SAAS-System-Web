{{-- The announcement fields: new (index page) or edit. The dates are typed in restaurant time. --}}
@php($localValue = fn ($at) => $at ? local_at($at)->format('Y-m-d\TH:i') : '')
<form method="post" action="{{ $announcement->exists ? route('admin.announcements.update', $announcement) : route('admin.announcements.store') }}" class="card grid gap-3" data-announcement-form>
    @csrf
    @if ($errors->any())<div class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
    <label class="block"><span class="field-label">{{ __('Title') }}</span>
        <input class="field" name="title" value="{{ old('title', $announcement->title) }}" maxlength="120" required></label>
    <label class="block"><span class="field-label">{{ __('Message') }}</span>
        <textarea class="field" name="body" rows="3" maxlength="500" required>{{ old('body', $announcement->body) }}</textarea></label>
    <label class="block"><span class="field-label">{{ __('Link (optional)') }}</span>
        <input class="field" type="url" name="link" value="{{ old('link', $announcement->link) }}" maxlength="255" dir="ltr" placeholder="https://"></label>
    <div class="grid gap-3 sm:grid-cols-2">
        <label class="block"><span class="field-label">{{ __('Kind') }}</span>
            <select class="field" name="level">
                <option value="info" @selected(old('level', $announcement->level) === 'info')>{{ __('Information') }}</option>
                <option value="warning" @selected(old('level', $announcement->level) === 'warning')>{{ __('Warning') }}</option>
            </select></label>
        <label class="block"><span class="field-label">{{ __('Shown to') }}</span>
            <select class="field" name="plan_id">
                <option value="">{{ __('All restaurants') }}</option>
                @foreach ($plans as $p)
                    <option value="{{ $p->id }}" @selected((string) old('plan_id', $announcement->plan_id) === (string) $p->id)>{{ __('Restaurants on the :plan plan', ['plan' => $p->displayName()]) }}</option>
                @endforeach
            </select></label>
        <label class="block"><span class="field-label">{{ __('Starts') }}</span>
            <input class="field" type="datetime-local" name="starts_at" value="{{ old('starts_at', $localValue($announcement->starts_at)) }}" dir="ltr"></label>
        <label class="block"><span class="field-label">{{ __('Ends (optional)') }}</span>
            <input class="field" type="datetime-local" name="ends_at" value="{{ old('ends_at', $localValue($announcement->ends_at)) }}" dir="ltr"></label>
    </div>
    <p class="text-xs text-navy-500">{{ __('Shown as a banner at the top of the restaurant dashboard until it ends or each user hides it. Without a start it starts now.') }}</p>
    <div><button class="btn-primary">{{ __('Save') }}</button></div>
</form>
