{{-- Membership tiers (feature membership): Silver, Gold… by visits or spend, each with its discount (CardsController::tiers). --}}
<section class="card scroll-mt-4" id="tiers" data-rewards-section="tiers">
    <h2 class="section-title">{{ __('Membership tiers') }}</h2>
    <p class="muted mb-3">{{ __('Silver, Gold, VIP… reached by visits or spend. The tier’s discount comes off every order placed with the guest’s number.') }}</p>
    <form method="post" action="{{ route('dash.cards.tiers') }}" class="space-y-2" data-tiers>
        @csrf
        <div class="hidden grid-cols-[1fr_1fr_80px_90px_70px_44px_28px] gap-2 text-xs font-semibold text-navy-500 sm:grid">
            <span>{{ __('Name') }}</span><span>{{ __('English name') }}</span><span>{{ __('Visits') }}</span><span>{{ __('Spent (EGP)') }}</span><span>{{ __('Discount %') }}</span><span>{{ __('Color') }}</span><span></span>
        </div>
        <div class="space-y-2" data-rows>
            @foreach ($tiers as $i => $t)
                <div class="grid grid-cols-2 gap-2 rounded-xl bg-navy-50 p-2 sm:grid-cols-[1.4fr_1fr_72px_88px_64px_44px_28px] sm:bg-transparent sm:p-0" data-row>
                    <input type="hidden" name="tiers[{{ $i }}][id]" value="{{ $t->id }}">
                    <input class="field" name="tiers[{{ $i }}][name]" value="{{ $t->name }}" maxlength="40" required placeholder="{{ __('Gold') }}">
                    <input class="field" name="tiers[{{ $i }}][name_en]" value="{{ $t->name_en }}" maxlength="40" dir="ltr" placeholder="Gold">
                    <input class="field" name="tiers[{{ $i }}][min_visits]" type="number" min="0" value="{{ $t->min_visits }}" dir="ltr">
                    <input class="field" name="tiers[{{ $i }}][min_spent]" type="number" min="0" step="1" value="{{ (int) $t->min_spent }}" dir="ltr">
                    <input class="field" name="tiers[{{ $i }}][discount_percent]" type="number" min="0" max="50" step="0.5" value="{{ $t->discount_percent }}" dir="ltr">
                    <input class="field min-h-11 p-1" name="tiers[{{ $i }}][color]" type="color" value="{{ $t->color }}">
                    <button type="button" class="grid size-10 place-items-center rounded-lg text-navy-400 hover:bg-navy-50 hover:text-red-600" data-remove aria-label="{{ __('Remove') }}">✕</button>
                    @if (($members[$t->id] ?? 0) > 0)<span class="col-span-2 text-xs text-navy-500 sm:col-span-7">{{ __(':n guests on this tier today', ['n' => $members[$t->id]]) }}</span>@endif
                </div>
            @endforeach
        </div>
        <template data-template>
            <div class="grid grid-cols-2 gap-2 rounded-xl bg-navy-50 p-2 sm:grid-cols-[1.4fr_1fr_72px_88px_64px_44px_28px] sm:bg-transparent sm:p-0" data-row>
                <input class="field" name="tiers[__i__][name]" maxlength="40" required placeholder="{{ __('Gold') }}">
                <input class="field" name="tiers[__i__][name_en]" maxlength="40" dir="ltr" placeholder="Gold">
                <input class="field" name="tiers[__i__][min_visits]" type="number" min="0" value="0" dir="ltr">
                <input class="field" name="tiers[__i__][min_spent]" type="number" min="0" step="1" value="0" dir="ltr">
                <input class="field" name="tiers[__i__][discount_percent]" type="number" min="0" max="50" step="0.5" value="5" dir="ltr">
                <input class="field min-h-11 p-1" name="tiers[__i__][color]" type="color" value="#C9A227">
                <button type="button" class="grid size-10 place-items-center rounded-lg text-navy-400 hover:bg-navy-50 hover:text-red-600" data-remove aria-label="{{ __('Remove') }}">✕</button>
            </div>
        </template>
        <div class="flex flex-wrap gap-2 pt-1">
            <button type="button" class="btn-ghost" data-add>+ {{ __('Add a tier') }}</button>
            <button class="btn-primary">{{ __('Save tiers') }}</button>
        </div>
    </form>
    <p class="mt-3 text-xs text-navy-500">{{ __('A guest is on the highest tier whose visits and spend they reached. Leave a threshold at 0 to ignore it. Up to 6 tiers.') }}</p>
</section>

<script>
    // Tier rows: add / remove, renumbering the field names so the server gets a clean list.
    (() => {
        const form = document.querySelector('[data-tiers]');
        if (!form) return;
        const rows = form.querySelector('[data-rows]');
        const tpl = form.querySelector('[data-template]');
        const renumber = () => rows.querySelectorAll('[data-row]').forEach((row, i) => row.querySelectorAll('[name]').forEach((f) => { f.name = f.name.replace(/tiers\[[^\]]*\]/, `tiers[${i}]`); }));
        form.querySelector('[data-add]').addEventListener('click', () => {
            if (rows.querySelectorAll('[data-row]').length >= 6) return;
            rows.insertAdjacentHTML('beforeend', tpl.innerHTML);
            renumber();
        });
        form.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-remove]');
            if (btn) { btn.closest('[data-row]').remove(); renumber(); }
        });
    })();
</script>
