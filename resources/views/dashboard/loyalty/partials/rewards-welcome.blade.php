{{--
    Welcome for new guests: the two ways side by side, so the owner picks one or both knowingly — the gift on the first
    online order (App\Services\Marketing\WelcomeReward, saved by LoyaltyController::welcome) and the WhatsApp thank-you
    after the first order (the welcome automation, saved by AutomationsController). Each half needs its own feature
    and permission.
--}}
@php($rights = \App\Services\Entitlements::current())
<section class="scroll-mt-4" id="welcome" data-rewards-section="welcome">
    <h2 class="text-xl font-extrabold">👋 {{ __('Welcome for new guests') }}</h2>
    <p class="dash-help">{{ __('Two ways to welcome a guest who is new to you. Use one or both: with both on, the guest gets the gift on their first online order and the thank-you (with its coupon for the next visit) after their first order. A discount gift and a coupon never come off the same order.') }}</p>

    <div class="mt-3 overflow-x-auto rounded-2xl border border-navy-100 bg-white">
        <table class="table-base text-sm" data-welcome-compare>
            <thead><tr><th></th><th>🎁 {{ __('Gift on the first online order') }}</th><th>👋 {{ __('Thank-you after the first order') }}</th></tr></thead>
            <tbody>
                <tr><th class="text-start">{{ __('When') }}</th><td>{{ __('On the guest’s first online order, by itself, once per mobile') }}</td><td>{{ __('After the guest’s first counted order, from any channel (within two days)') }}</td></tr>
                <tr><th class="text-start">{{ __('What the guest gets') }}</th><td>{{ __('A discount off that order, or points on the loyalty card') }}</td><td>{{ __('A WhatsApp message, with a coupon for the next visit if you choose one') }}</td></tr>
                <tr><th class="text-start">{{ __('Cost') }}</th><td>{{ __('Only the gift itself') }}</td><td>{{ __('One WhatsApp credit per message, plus the coupon') }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="mt-4 grid items-start gap-4 lg:grid-cols-2">
        <div data-welcome-option="gift">
            @if ($welcomeParts['gift'])
                <form method="post" action="{{ route('dash.loyalty.welcome') }}" class="card space-y-4" id="welcome-reward" data-welcome-form>
                    @csrf
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-extrabold">🎁 {{ __('Welcome gift for signing up') }}</h3>
                            <p class="mt-1 text-sm text-navy-500">{{ __('The menu shows «Sign up and get …» to new guests, and the gift comes off their first online order by itself, once per mobile.') }}</p>
                        </div>
                        <label class="flex min-h-11 items-center gap-2 font-bold">
                            <input type="checkbox" name="enabled" value="1" class="size-5" @checked($settings['welcome_reward_enabled'] === '1') data-welcome-on> {{ __('On') }}
                        </label>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label class="field-label" for="w-type">{{ __('The gift') }}</label>
                            <select class="field" id="w-type" name="type">
                                <option value="percent" @selected($settings['welcome_reward_type'] === 'percent')>{{ __('% off the first online order') }}</option>
                                <option value="amount" @selected($settings['welcome_reward_type'] === 'amount')>{{ __('An amount off the first online order') }}</option>
                                @if ($points)<option value="points" @selected($settings['welcome_reward_type'] === 'points')>{{ __('Points on the loyalty card') }}</option>@endif
                            </select>
                            @error('type')<div class="field-error">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label class="field-label" for="w-value">{{ __('Value') }}</label>
                            <input class="field" id="w-value" type="number" name="value" min="1" max="100000" step="1" value="{{ $settings['welcome_reward_value'] }}" dir="ltr" required>
                            @error('value')<div class="field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="flex items-end">
                            <button class="btn-primary min-h-11 w-full">{{ __('Save') }}</button>
                        </div>
                    </div>
                    <p class="text-xs text-navy-500">{{ __('Points need a points loyalty program. Guests see the gift only where online ordering is on.') }}</p>
                </form>
            @else
                <p class="card text-sm text-navy-500">🎁 {{ __('Gift on the first online order') }}: {{ $rights->allows('loyalty_stamps') ? __('Your account can’t change this one.') : __('Not in your plan.') }}</p>
            @endif
        </div>

        <div data-welcome-option="automation">
            @if ($welcomeParts['automation'])
                @if ($whatsappBalance < 1)
                    <p class="mb-3 rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-700">{{ __('Your WhatsApp credits ran out') }}. {{ __('Automations pause until you top up.') }}</p>
                @endif
                @include('dashboard.campaigns.automation-card', ['kind' => 'welcome', 'a' => $automation, 'r' => $automationResults, 'returnTo' => 'rewards'])
            @else
                <p class="card text-sm text-navy-500">👋 {{ __('Thank-you after the first order') }}: {{ $rights->allows('campaigns') ? __('Your account can’t change this one.') : __('Not in your plan.') }}</p>
            @endif
        </div>
    </div>
</section>
