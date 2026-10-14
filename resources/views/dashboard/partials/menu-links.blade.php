{{-- The canonical menu address (QR codes, Google) and the short subdomain alias. --}}
<dl class="grid gap-2 text-sm">
    <div class="flex flex-wrap items-center gap-2">
        <dt class="w-32 shrink-0 font-semibold">{{ __('Main link') }}</dt>
        <dd><code dir="ltr" class="rounded-lg bg-navy-50 px-3 py-2" data-main-link>{{ tenant()->url() }}</code></dd>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <dt class="w-32 shrink-0 font-semibold">{{ __('Short alias') }}</dt>
        <dd><code dir="ltr" class="rounded-lg bg-navy-50 px-3 py-2 text-navy-600">{{ tenant()->subdomainUrl() }}</code></dd>
    </div>
    <p class="text-xs text-navy-500">{{ __('Both open the same menu. QR codes and Google use the main link.') }}</p>
</dl>
