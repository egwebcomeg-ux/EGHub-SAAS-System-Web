{{--
    "Subscribe yearly in installments": shown wherever the plans are priced (site home and pricing, dashboard billing)
    only while an installment provider is switched on in the super admin (App\Billing\OnlinePayments::installmentsBanner).
    $banner = ['yearly' => '12,000' | null, 'providers' => 'ValU، أمان، سهولة …'], $cta = optional [url, label].
--}}
@if ($banner)
    <div class="flex flex-col gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-950 sm:flex-row sm:items-center sm:gap-4 sm:p-5" data-installments-banner>
        <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-white text-emerald-700 shadow-sm ring-1 ring-emerald-100" aria-hidden="true">
            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 7h20v12H2zM2 11h20M6 15h4"/></svg>
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-base font-black sm:text-lg">{{ __('Subscribe yearly and pay in installments') }}</p>
            <p class="mt-0.5 text-sm font-semibold">
                @if ($banner['yearly'])
                    {{ __('The Complete plan is :total EGP a year: split it into monthly installments with :providers.', ['total' => $banner['yearly'], 'providers' => $banner['providers']]) }}
                @else
                    {{ __('Split the yearly plan into monthly installments with :providers.', ['providers' => $banner['providers']]) }}
                @endif
            </p>
            <p class="mt-1 text-xs text-emerald-800/80">{{ __('Installment periods and fees follow each provider’s own terms.') }}</p>
        </div>
        @if (! empty($cta))
            <a href="{{ $cta[0] }}" class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-black text-white hover:bg-emerald-800" data-installments-cta>{{ $cta[1] }}</a>
        @endif
    </div>
@endif
