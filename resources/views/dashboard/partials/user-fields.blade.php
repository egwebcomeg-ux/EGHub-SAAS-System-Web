<div>
    <label class="field-label">{{ __('Name') }}</label>
    <input class="field" name="name" value="{{ $u?->name }}" required maxlength="100">
</div>
<div>
    <label class="field-label">{{ __('Email') }}</label>
    <input class="field" name="email" type="email" value="{{ $u?->email }}" dir="ltr">
</div>
<div>
    <label class="field-label">{{ __('Mobile') }}</label>
    <input class="field" name="phone" value="{{ $u?->phone }}" dir="ltr" placeholder="01xxxxxxxxx">
</div>
<div>
    <label class="field-label">{{ __('Role') }}</label>
    <select class="field" name="role">
        @foreach (array_keys(\App\Support\Permissions::ROLES) as $role)
            <option value="{{ $role }}" @selected($u?->roleKey() === $role)>{{ \App\Support\Permissions::roleLabel($role) }}</option>
        @endforeach
    </select>
</div>
<div>
    <label class="field-label">{{ __('Branch') }}</label>
    <select class="field" name="branch_id">
        <option value="">{{ __('All branches') }}</option>
        @foreach ($branches as $b)
            <option value="{{ $b->id }}" @selected($u?->branch_id === $b->id)>{{ tr($b->name, $b->name_en) }}</option>
        @endforeach
    </select>
</div>
<div>
    <label class="field-label">{{ $u ? __('New password (optional)') : __('Password') }}</label>
    <input class="field" name="password" type="password" minlength="8" @required(!$u) autocomplete="new-password" dir="ltr">
</div>
