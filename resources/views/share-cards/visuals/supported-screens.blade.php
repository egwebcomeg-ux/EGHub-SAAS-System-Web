{{-- Screens that work with EgHub: a TV showing the pairing code, with the brand names (as text) around it --}}
<div class="abs" style="left: 0; top: 40px; width: 424px; height: 252px; border-radius: 18px; background: #0B1222; padding: 12px; box-shadow: 0 30px 70px rgba(0,0,0,.5)">
    <div style="height: 100%; border-radius: 8px; background: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px">
        <div class="t" style="font-size: 18px; color: #64748B; direction: ltr">eghub.app/tv</div>
        <div class="t" style="font-size: 58px; letter-spacing: 6px; color: #0A2540; direction: ltr">482 913</div>
    </div>
</div>
<div class="abs" style="left: 192px; top: 292px; width: 40px; height: 30px; background: #0B1222"></div>
<div class="abs" style="left: 132px; top: 320px; width: 160px; height: 14px; border-radius: 7px; background: #0B1222"></div>
<div style="position: absolute; left: 0; right: 0; top: 362px; display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; direction: ltr">
    @foreach (['Samsung', 'LG', 'Xiaomi', 'Sony', 'TCL', 'Fire TV', 'Google TV', 'Android', 'PC'] as $brand)
        <span class="toast" style="position: static; padding: 8px 16px; font-size: 20px">{{ $brand }}</span>
    @endforeach
</div>
