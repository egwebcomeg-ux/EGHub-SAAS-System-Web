{{-- kitchen display: the orders on a screen per station, coloured by how long they have waited --}}
<div class="abs" style="left: 0; top: 50px; width: 424px; height: 300px; border-radius: 20px; background: #0B1222; padding: 12px; box-shadow: 0 30px 70px rgba(0,0,0,.5)">
    <div style="height: 100%; border-radius: 10px; background: #1B2B44; padding: 12px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px">
        @foreach ([['#16A34A', __('Ready'), 'green'], ['#F59E0B', __('Preparing'), 'amber'], ['#E11D48', __('Preparing'), 'red']] as [$color, $state, $chip])
            <div style="border-radius: 10px; background: #fff; overflow: hidden; display: flex; flex-direction: column">
                <div class="row" style="background: {{ $color }}; color: #fff; padding: 8px 10px; justify-content: space-between; direction: ltr">
                    <span class="t" style="font-size: 18px">#{{ 41 + $loop->index }}</span><x-landing.icon name="clock" class="i" style="width: 20px; height: 20px" />
                </div>
                <div style="padding: 10px; display: flex; flex-direction: column; gap: 9px; flex: 1">
                    @for ($line = 0; $line < 4; $line++)<span class="line {{ $line % 2 ? 'soft' : '' }}" style="height: 8px; width: {{ 90 - $line * 12 }}%"></span>@endfor
                    <div style="flex: 1"></div>
                    <span class="chip {{ $chip }}" style="align-self: center; font-size: 15px">{{ $state }}</span>
                </div>
            </div>
        @endforeach
    </div>
</div>
<div class="toast" style="right: 10px; bottom: 50px">
    <span class="ic solid"><x-landing.icon name="chef" class="i" /></span>{{ __('Kitchen') }}
</div>
