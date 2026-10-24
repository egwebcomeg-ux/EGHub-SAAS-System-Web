{{-- free QR tool: a styled QR in a "Scan me" frame (a real code: it opens the tool), and the download formats --}}
<div class="card" style="left: 42px; top: 0; width: 340px; padding: 26px; text-align: center; border: 6px solid #F26522">
    @include('share-cards.qr', ['data' => $pageUrl, 'size' => 276, 'color' => '#0A2540'])
</div>
<div class="abs t" style="left: 112px; top: 340px; width: 200px; border-radius: 0 0 20px 20px; background: #F26522; color: #fff; text-align: center; padding: 8px 0 12px; font-size: 26px">{{ __('Scan me') }}</div>
<div class="row" style="position: absolute; left: 0; right: 0; bottom: 30px; justify-content: center; gap: 10px; direction: ltr">
    @foreach (['PNG', 'SVG', 'PDF'] as $format)
        <span class="toast" style="position: static; padding: 10px 20px; font-size: 22px">{{ $format }}</span>
    @endforeach
</div>
