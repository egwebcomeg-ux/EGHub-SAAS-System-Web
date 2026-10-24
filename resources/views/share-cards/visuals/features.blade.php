{{-- features index: every module's icon, one tile each, the group colours of the dashboard apps --}}
@php
    $groupColors = ['menu' => '#005BA6', 'orders' => '#F26522', 'customers' => '#0F8F7E', 'operations' => '#6D4BD6', 'management' => '#3E5F80'];
@endphp
<div class="abs" style="left: 0; top: 70px; width: 424px; display: flex; flex-wrap: wrap; justify-content: center; gap: 10px">
    @foreach (\App\Support\SiteModules::all() as $module)
        <div style="width: 62px; height: 62px; border-radius: 18px; background: #fff; display: grid; place-items: center; color: {{ $groupColors[$module['group']] ?? '#0A2540' }}; box-shadow: 0 10px 24px rgba(0,0,0,.3)">
            <x-landing.icon :name="$module['icon']" class="i" style="width: 30px; height: 30px" />
        </div>
    @endforeach
</div>
