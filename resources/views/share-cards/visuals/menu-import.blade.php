{{-- menu import: an Excel sheet (or a photo) goes in, menu items come out --}}
<div class="card" style="left: 0; top: 20px; width: 240px; padding: 16px; transform: rotate(-4deg)">
    <div class="row" style="gap: 8px"><span class="ic green" style="width: 36px; height: 36px"><x-landing.icon name="file" class="i" /></span><span class="t" style="font-size: 20px; direction: ltr">menu.xlsx</span></div>
    <div style="margin-top: 12px; display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 4px">
        @for ($cell = 0; $cell < 18; $cell++)
            <div style="height: 22px; border-radius: 4px; background: {{ $cell < 3 ? '#16A34A' : ($cell % 3 === 0 ? '#E1E7F1' : '#F0F6FC') }}"></div>
        @endfor
    </div>
</div>
<div class="abs" style="left: 104px; top: 300px"><span class="ic solid big"><x-landing.icon name="arrow" class="i" /></span></div>
@include('share-cards.visuals._menu-phone', ['phoneLeft' => 214, 'phoneTop' => 40, 'phoneScale' => 0.88, 'phoneButton' => __('Publish')])
<div class="toast" style="left: 0; bottom: 20px">
    <span class="ic"><x-landing.icon name="sparkles" class="i" /></span>{{ __('From a photo with AI') }}
</div>
