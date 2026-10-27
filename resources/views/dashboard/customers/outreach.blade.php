@extends('layouts.dashboard')
@section('title', __('Free WhatsApp campaign'))

@section('content')
<x-dash.page-header :title="__('Free WhatsApp campaign')" :help="__('Send a message to a group of your guests from your own WhatsApp: no Meta account and no credits. Each guest gets their own link and, if you like, their own coupon, so you see who came back.')">
    <x-slot:actions>
        <a href="{{ route('dash.customers') }}" class="btn-ghost">{{ __('Customers') }}</a>
    </x-slot:actions>
</x-dash.page-header>

<div class="grid gap-4 xl:grid-cols-5">
    <form method="post" action="{{ route('dash.outreach.store') }}" class="card space-y-4 xl:col-span-3" data-outreach-form>
        @csrf
        <h2 class="section-title">{{ __('New campaign') }}</h2>
        <div>
            <label class="field-label" for="o-name">{{ __('Campaign name') }}</label>
            <input class="field" id="o-name" name="name" value="{{ old('name') }}" maxlength="100" required placeholder="{{ __('e.g. We are online now') }}">
            @error('name')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        <fieldset>
            <legend class="field-label">{{ __('Who gets it') }}</legend>
            <div class="grid gap-2 sm:grid-cols-2" data-outreach-audiences>
                @foreach ($audiences as $a)
                    <label class="flex min-h-14 cursor-pointer items-center gap-3 rounded-xl border border-navy-100 p-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                        <input type="radio" name="audience" value="{{ $a['key'] }}" class="size-4" @checked(old('audience', $pick) === $a['key']) data-outreach-audience>
                        <span class="min-w-0 flex-1">
                            <span class="block font-bold">{{ $a['label'] }}</span>
                            <span class="block text-xs text-navy-500">{{ trans_choice(':count guest|:count guests', $a['count'], ['count' => number_format($a['count'])]) }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('audience')<div class="field-error">{{ $message }}</div>@enderror
        </fieldset>

        <div>
            <label class="field-label" for="o-message">{{ __('The message') }}</label>
            <textarea class="field min-h-32 py-2" id="o-message" name="message" maxlength="1000" required data-outreach-message>{{ old('message', $messages[$pick] ?? '') }}</textarea>
            <p class="mt-1 text-xs text-navy-500">{{ __('{name} = the guest\'s first name · {link} = their own link to the menu · {code} = their own coupon code') }}</p>
            @error('message')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        <div class="rounded-xl bg-navy-50 p-3">
            <label class="flex items-center gap-2 font-semibold">
                <input type="checkbox" name="with_coupon" value="1" class="size-4" @checked(old('with_coupon')) data-outreach-coupon-toggle>
                {{ __('A personal one-time coupon for each guest') }}
            </label>
            <div @class(['mt-3 grid gap-3 sm:grid-cols-4', 'hidden' => ! old('with_coupon')]) data-outreach-coupon>
                <div>
                    <label class="field-label" for="o-ctype">{{ __('Type') }}</label>
                    <select class="field" id="o-ctype" name="coupon_type">
                        <option value="percent" @selected(old('coupon_type') === 'percent')>%</option>
                        <option value="amount" @selected(old('coupon_type') === 'amount')>{{ __('EGP') }}</option>
                    </select>
                </div>
                <div>
                    <label class="field-label" for="o-cvalue">{{ __('Value') }}</label>
                    <input class="field" id="o-cvalue" type="number" name="coupon_value" min="1" step="0.5" value="{{ old('coupon_value', 15) }}" dir="ltr">
                    @error('coupon_value')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="field-label" for="o-cdays">{{ __('Good for (days)') }}</label>
                    <input class="field" id="o-cdays" type="number" name="coupon_days" min="1" max="90" value="{{ old('coupon_days', 7) }}" dir="ltr">
                </div>
                <div>
                    <label class="field-label" for="o-cmin">{{ __('Minimum order') }}</label>
                    <input class="field" id="o-cmin" type="number" name="coupon_min_order" min="0" value="{{ old('coupon_min_order', 0) }}" dir="ltr">
                </div>
            </div>
        </div>

        <label class="flex items-start gap-2 rounded-xl bg-navy-50 p-3">
            <input type="checkbox" name="with_claim" value="1" class="mt-1 size-4" @checked(old('with_claim', $pick === 'imported')) data-outreach-claim>
            <span>
                <span class="block font-semibold">{{ __('Invite them to their account («ادعي عملاءك»)') }}</span>
                <span class="block text-xs text-navy-500">{{ __('{link} becomes a personal link: the guest sees their name and saved address, chooses a password or continues with Google, and is signed in. One use, for 14 days.') }}</span>
            </span>
        </label>

        <button class="btn-primary min-h-12 w-full sm:w-auto">{{ __('Make the send list') }}</button>
    </form>

    <section class="card xl:col-span-2" data-outreach-list>
        <h2 class="section-title">{{ __('Your campaigns') }}</h2>
        @forelse ($campaigns as $c)
            @php($s = $stats[$c->id])
            <a href="{{ route('dash.outreach.show', $c) }}" class="mt-3 block rounded-xl border border-navy-100 p-3 hover:border-brand-500" data-outreach-campaign="{{ $c->id }}">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-bold">{{ $c->name }}</span>
                    <span class="text-xs text-navy-500">{{ local_time($c->created_at, 'Y/m/d') }}</span>
                </div>
                <div class="mt-1 text-xs text-navy-500">{{ \App\Services\Customers\Audiences::label($c->segment) }}</div>
                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    <span>{{ __('Sent') }}: <b>{{ number_format($s['sent']) }}/{{ number_format($s['recipients']) }}</b></span>
                    <span>{{ __('Orders') }}: <b>{{ number_format($s['orders']) }}</b></span>
                    <span>{{ __('Sales') }}: <b>{{ money($s['revenue']) }}</b></span>
                </div>
            </a>
        @empty
            <p class="mt-3 text-sm text-navy-500">{{ __('No campaigns yet. Start with the guests you imported: tell them you are online now.') }}</p>
        @endforelse
    </section>
</div>

<script>
// the suggested message follows the group, until the owner writes their own
(function () {
    var messages = @json($messages);
    var box = document.querySelector('[data-outreach-message]');
    var touched = false;
    box.addEventListener('input', function () { touched = true; });
    document.querySelectorAll('[data-outreach-audience]').forEach(function (r) {
        r.addEventListener('change', function () { if (!touched && messages[r.value]) { box.value = messages[r.value]; } });
    });
    var toggle = document.querySelector('[data-outreach-coupon-toggle]');
    toggle.addEventListener('change', function () { document.querySelector('[data-outreach-coupon]').classList.toggle('hidden', !toggle.checked); });
})();
</script>
@endsection
