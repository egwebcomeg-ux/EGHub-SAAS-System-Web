{{--
    "حسابك في <المطعم>" after an online order (tracking page, pager page). The link itself only on the phone that
    already holds this guest's key (App\Services\Customers\GuestAccount::orderCard); any other phone is told how to
    open the account with the mobile and this order's number once it is delivered.
--}}
@php
    $accountCard = \App\Services\Customers\GuestAccount::orderCard($order, request());
@endphp
@if ($accountCard)
    <section class="card mt-4" data-account-card>
        <h2 class="text-lg font-extrabold">👤 {{ __('Your account at :place', ['place' => $accountCard['brand']]) }}</h2>
        @if ($accountCard['url'])
            <p class="mt-1 text-sm m-muted">{{ __('Save this link or open it from any phone: your orders, order again, your addresses and points.') }}</p>
            <div class="mt-3 flex gap-2">
                <input class="field min-w-0 flex-1 text-sm" value="{{ $accountCard['url'] }}" readonly dir="ltr" aria-label="{{ __('Account link') }}" data-account-card-field>
                <button type="button" class="btn-ghost min-h-11 shrink-0 border px-3" data-account-copy="{{ $accountCard['url'] }}" data-copied="{{ __('Copied ✓') }}">{{ __('Copy') }}</button>
            </div>
            <div class="mt-2 grid grid-cols-2 gap-2">
                <a href="{{ $accountCard['whatsapp'] }}" target="_blank" rel="noopener noreferrer" class="flex min-h-12 items-center justify-center rounded-xl bg-[#25D366] px-2 text-center text-sm font-bold text-white" data-account-card-whatsapp>{{ __('Send it to myself on WhatsApp') }}</a>
                <a href="{{ $accountCard['url'] }}" class="btn-primary min-h-12 text-sm" data-account-card-open>{{ __('Open my account') }}</a>
            </div>
            <script>
                document.querySelectorAll('[data-account-copy]').forEach((button) => button.addEventListener('click', async () => {
                    const label = button.textContent;
                    try { await navigator.clipboard.writeText(button.dataset.accountCopy); } catch {
                        document.querySelector('[data-account-card-field]')?.select(); try { document.execCommand('copy'); } catch {}
                    }
                    button.textContent = button.dataset.copied;
                    setTimeout(() => { button.textContent = label; }, 1800);
                }));
            </script>
        @else
            <p class="mt-1 text-sm m-muted" data-account-card-hint>
                {{ $accountCard['served']
                    ? __('Open «My account» from any phone with your mobile (:phone) and this order number #:n.', ['phone' => $accountCard['phone'], 'n' => $accountCard['number']])
                    : __('Once this order is delivered, open «My account» from any phone with your mobile (:phone) and this order number #:n.', ['phone' => $accountCard['phone'], 'n' => $accountCard['number']]) }}
            </p>
            <a href="{{ route('menu.account.sign-in') }}" class="btn-ghost mt-3 w-full min-h-12 border" data-account-card-sign-in>{{ __('My account') }}</a>
        @endif
    </section>
@endif
