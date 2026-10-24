{{-- sign-up: the free menu on the guest's phone, and its QR code --}}
@include('share-cards.visuals._menu-phone', ['phoneLeft' => 120, 'phoneTop' => 10])
<div class="toast" style="left: 0; top: 96px">
    <span class="ic green"><x-landing.icon name="check" class="i" /></span>{{ __('Free') }}
</div>
<div class="card" style="right: 0; bottom: 30px; padding: 12px; transform: rotate(4deg)">
    @include('share-cards.qr', ['data' => $pageUrl, 'size' => 120])
</div>
