{{-- home: the menu on the guest's phone, a new order and a delivery on the way --}}
@include('share-cards.visuals._menu-phone', ['phoneLeft' => 120, 'phoneTop' => 10])
<div class="toast" style="left: 0; top: 70px">
    <span class="ic"><x-landing.icon name="bell" class="i" /></span>{{ __('New order') }}
</div>
<div class="toast" style="right: 0; bottom: 40px">
    <span class="ic blue"><x-landing.icon name="scooter" class="i" /></span>{{ __('On the way') }}
</div>
