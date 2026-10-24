{{-- menu languages: the guest picks their language; AI fills in the translation --}}
<div class="phone" style="left: 150px; top: 8px">
    <div class="scr" style="padding: 30px 16px 0">
        <div class="row" style="gap: 10px; color: #005BA6"><x-landing.icon name="translate" class="i" style="width: 30px; height: 30px" /><span class="t" style="font-size: 22px; color: #0A2540">{{ __('Rosto') }}</span></div>
        @foreach (['ar', 'en', 'fr', 'ru', 'it', 'de', 'zh'] as $code)
            <div class="row" @style(['margin-top: 9px', 'padding: 8px 14px', 'border-radius: 14px', 'justify-content: space-between', 'font-weight: 800', 'font-size: 20px', 'background: #F26522; color: #fff' => $code === 'ru', 'background: #F0F6FC' => $code !== 'ru'])>
                <span>{{ \App\Support\MenuLocales::native($code) }}</span>
                @if ($code === 'ru')<x-landing.icon name="check" class="i" style="width: 22px; height: 22px" />@endif
            </div>
        @endforeach
    </div>
</div>
<div class="toast" style="left: 0; top: 150px">
    <span class="ic"><x-landing.icon name="sparkles" class="i" /></span>{{ __('One-click translation') }}
</div>
