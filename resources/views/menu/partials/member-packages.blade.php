{{-- Membership tier and prepaid packages on the guest's card page (advanced cards); nothing when neither applies. --}}
@if ($tier || $nextTier)
    <div class="mt-5 rounded-2xl m-surface p-4 shadow-sm ring-1 m-ring" data-membership>
        @if ($tier)
            <div class="flex items-center gap-3">
                <span class="grid size-12 place-items-center rounded-2xl text-xl font-black text-white" style="background: {{ $tier->color }}">★</span>
                <div>
                    <div class="text-sm m-muted">{{ __('Your membership') }}</div>
                    <div class="text-xl font-black" style="color: {{ $tier->color }}">{{ $tier->displayName() }}</div>
                    @if ($tier->discount_percent > 0)<div class="text-sm m-muted">{{ __(':p% off every order placed with your number.', ['p' => money($tier->discount_percent)]) }}</div>@endif
                </div>
            </div>
        @endif
        @if ($nextTier)
            <p class="mt-2 text-sm m-muted">
                {{ __('Next: :tier', ['tier' => $nextTier->displayName()]) }}
                @if ($nextTier->min_visits > $customer->visits_count) · {{ __(':n more visits', ['n' => $nextTier->min_visits - $customer->visits_count]) }}@endif
                @if ($nextTier->min_spent > $customer->total_spent) · {{ __(':amount EGP more', ['amount' => money($nextTier->min_spent - $customer->total_spent)]) }}@endif
            </p>
        @endif
    </div>
@endif

@if ($packages->isNotEmpty())
    <div class="mt-5 space-y-2" data-packages>
        @foreach ($packages as $cp)
            <div class="rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200">
                <div class="flex items-center justify-between gap-3">
                    <div class="font-extrabold text-amber-900">🎟 {{ $cp->name }}</div>
                    <div class="text-2xl font-black text-amber-900" dir="ltr">{{ $cp->uses_left }}<span class="text-sm font-bold text-amber-700">/{{ $cp->uses_total }}</span></div>
                </div>
                <div class="mt-2 flex gap-1" aria-hidden="true">
                    @for ($i = 0; $i < min($cp->uses_total, 20); $i++)<span class="h-2 flex-1 rounded-full {{ $i < $cp->uses_total - $cp->uses_left ? 'bg-amber-300' : 'bg-amber-800' }}"></span>@endfor
                </div>
                <div class="mt-2 text-sm text-amber-800">{{ __('Valid until :date. Show this card at the counter to use one.', ['date' => local_time($cp->expires_at, 'Y/m/d')]) }}</div>
            </div>
        @endforeach
    </div>
@endif
