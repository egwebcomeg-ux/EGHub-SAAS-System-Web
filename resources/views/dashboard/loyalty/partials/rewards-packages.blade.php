{{-- Prepaid packages (feature prepaid_packages): on sale, paused, recently sold (CardsController::storePackage / updatePackage). --}}
<section class="card scroll-mt-4" id="packages" data-rewards-section="packages">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="section-title">{{ __('Prepaid packages') }}</h2>
            <p class="muted">{{ __('“10 coffees for 150 EGP, valid 30 days”: paid once at the counter, the cashier takes one use per visit.') }}</p>
        </div>
    </div>
    <div class="mt-4 grid gap-4 lg:grid-cols-5">
        <form method="post" action="{{ route('dash.cards.packages.store') }}" class="space-y-3 rounded-2xl bg-navy-50 p-4 lg:col-span-2">
            @csrf
            <h3 class="font-bold">{{ __('New package') }}</h3>
            <div class="grid gap-2 sm:grid-cols-2">
                <input class="field" name="name" value="{{ old('name') }}" maxlength="80" required placeholder="{{ __('10 coffees') }}">
                <input class="field" name="name_en" value="{{ old('name_en') }}" maxlength="80" dir="ltr" placeholder="10 coffees">
            </div>
            <input class="field" name="description" value="{{ old('description') }}" maxlength="200" placeholder="{{ __('Short description shown to the guest (optional)') }}">
            <div class="grid grid-cols-3 gap-2">
                <label class="block"><span class="field-label">{{ __('Price') }}</span><input class="field" name="price" type="number" min="1" step="1" value="{{ old('price') }}" dir="ltr" required></label>
                <label class="block"><span class="field-label">{{ __('Uses') }}</span><input class="field" name="uses" type="number" min="1" max="500" value="{{ old('uses', 10) }}" dir="ltr" required></label>
                <label class="block"><span class="field-label">{{ __('Valid (days)') }}</span><input class="field" name="valid_days" type="number" min="1" max="730" value="{{ old('valid_days', 30) }}" dir="ltr" required></label>
            </div>
            <details class="text-sm">
                <summary class="cursor-pointer font-semibold">{{ __('Good for (optional)') }}</summary>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <label class="block"><span class="field-label">{{ __('Categories') }}</span>
                        <select class="field" name="categories[]" multiple size="4">@foreach ($packageCategories as $c)<option value="{{ $c->id }}">{{ tr($c->name, $c->name_en) }}</option>@endforeach</select></label>
                    <label class="block"><span class="field-label">{{ __('Items') }}</span>
                        <select class="field" name="items[]" multiple size="4">@foreach ($packageItems as $it)<option value="{{ $it->id }}">{{ tr($it->name, $it->name_en) }}</option>@endforeach</select></label>
                </div>
            </details>
            <button class="btn-primary w-full">{{ __('Add package') }}</button>
        </form>

        <div class="space-y-3 lg:col-span-3">
            @forelse ($packages as $p)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-navy-100 p-4 {{ $p->is_active ? '' : 'opacity-60' }}">
                    <div>
                        <div class="font-extrabold">{{ $p->displayName() }} <span class="badge {{ $p->is_active ? 'bg-emerald-50 text-emerald-800' : 'bg-navy-100 text-navy-600' }}">{{ $p->is_active ? __('On sale') : __('Paused') }}</span></div>
                        <div class="text-sm text-navy-600">{{ money($p->price) }} {{ __('EGP') }} · {{ __(':n uses', ['n' => $p->uses]) }} · {{ __(':d days', ['d' => $p->valid_days]) }}</div>
                        <div class="text-xs text-navy-500">{{ __('Sold :n, :a still active', ['n' => $p->sold_count, 'a' => $p->active_count]) }}</div>
                    </div>
                    <div class="flex gap-2">
                        <form method="post" action="{{ route('dash.cards.packages.update', $p) }}">@csrf<input type="hidden" name="action" value="toggle"><button class="btn-ghost min-h-9 px-3">{{ $p->is_active ? __('Pause') : __('Resume') }}</button></form>
                        <form method="post" action="{{ route('dash.cards.packages.update', $p) }}" onsubmit="return confirm(@js(__('Remove this package? Guests who bought it keep what is left.')))">@csrf<input type="hidden" name="action" value="delete"><button class="btn-ghost min-h-9 px-3 text-red-600">{{ __('Remove') }}</button></form>
                    </div>
                </div>
            @empty
                <p class="muted">{{ __('No packages yet. Add the first one on the left — the cashier sells it from the counter page.') }}</p>
            @endforelse

            @if ($recent->isNotEmpty())
                <h3 class="mt-4 font-bold">{{ __('Recently sold') }}</h3>
                <div class="overflow-x-auto">
                    <table class="table-base text-sm">
                        <thead><tr><th>{{ __('Guest') }}</th><th>{{ __('Package') }}</th><th>{{ __('Left') }}</th><th>{{ __('Expires') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($recent as $cp)
                                <tr>
                                    <td><a href="{{ route('dash.loyalty.desk', ['q' => $cp->customer?->phone]) }}" class="text-brand-700 hover:underline" dir="ltr">{{ $cp->customer?->phone }}</a></td>
                                    <td>{{ $cp->name }}</td>
                                    <td dir="ltr">{{ $cp->uses_left }}/{{ $cp->uses_total }}</td>
                                    <td>{{ local_time($cp->expires_at, 'Y/m/d') }}</td>
                                    <td>{{ $cp->statusLabel() }}</td>
                                    <td>@if ($cp->status === 'active' && auth()->user()->can('pos.discount'))
                                        <form method="post" action="{{ route('dash.cards.packages.void', $cp) }}" onsubmit="return confirm(@js(__('Void this package?')))">@csrf<button class="text-xs text-red-600 hover:underline">{{ __('Void') }}</button></form>
                                    @endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</section>
