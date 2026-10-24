{{-- QR menu: the table's QR code (a real one, it opens this page) and the menu it opens on the guest's phone --}}
@include('share-cards.visuals._menu-phone', ['phoneLeft' => 184, 'phoneTop' => 8])
<div class="card" style="left: 0; top: 168px; width: 236px; padding: 18px; text-align: center; transform: rotate(-4deg)">
    @include('share-cards.qr', ['data' => $pageUrl, 'size' => 200])
    <div class="t" style="margin-top: 10px; font-size: 24px; color: #F26522">{{ __('Scan me') }}</div>
</div>
