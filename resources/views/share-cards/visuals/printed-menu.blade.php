{{-- printed menu: an A4 page laid out from the online menu, ready for the print shop --}}
<div class="card" style="left: 80px; top: 6px; width: 300px; height: 424px; border-radius: 10px; padding: 28px 28px; transform: rotate(3deg)">
    <div class="t" style="text-align: center; font-size: 28px; font-weight: 900">{{ __('Rosto') }}</div>
    <div class="line or" style="margin: 10px auto 0; width: 60px; height: 6px"></div>
    @foreach ([__('Drinks'), __('Desserts')] as $section)
        <div class="t" style="margin-top: 18px; font-size: 19px; color: #F26522">{{ $section }}</div>
        @foreach ([70, 52, 62] as $width)
            <div class="row" style="margin-top: 11px; gap: 8px">
                <div class="line" style="width: {{ $width }}px; height: 9px"></div>
                <div style="flex: 1; border-bottom: 2px dotted #C3D5E8"></div>
                <div class="line or" style="width: 28px; height: 9px"></div>
            </div>
        @endforeach
    @endforeach
    <div style="position: absolute; bottom: 18px; inset-inline-end: 22px">@include('share-cards.qr', ['data' => $pageUrl, 'size' => 64])</div>
</div>
<div class="toast" style="left: 0; bottom: 30px">
    <span class="ic solid"><x-landing.icon name="printer" class="i" /></span>{{ __('Print') }}
    <span class="chip soft" style="direction: ltr">A4 · PDF</span>
</div>
