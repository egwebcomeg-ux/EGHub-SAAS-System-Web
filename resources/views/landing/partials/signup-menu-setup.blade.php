{{-- "Let our team enter your menu": a paid service request created with the account (App\Services\MenuSetupRequest) --}}
@php
    $menuSetupService = \App\Support\SiteServices::find('menu_setup');
@endphp
<label class="flex items-start gap-2 rounded-xl border border-dashed border-brand-200 bg-brand-50/60 px-3 py-2.5 text-sm sm:col-span-2">
    <input type="checkbox" name="menu_setup" value="1" class="mt-1 size-4 shrink-0 accent-brand-600" @checked(old('menu_setup'))>
    <span>
        {{ __('I want the EgMenu team to enter my menu for me (paid service, we will contact you on WhatsApp).') }}
        @if ($menuSetupService && $menuSetupService['price'])
            <span class="block text-xs text-navy-500">{{ __('From :price EGP :unit · :delivery', ['price' => $menuSetupService['price'], 'unit' => $menuSetupService['unit'], 'delivery' => $menuSetupService['delivery']]) }}</span>
        @endif
    </span>
</label>
