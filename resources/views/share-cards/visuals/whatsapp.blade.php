{{-- WhatsApp messages: the order's news on the guest's WhatsApp (the statuses are the module's own summary) --}}
@php
    $statuses = array_slice(preg_split('/\s*[،,]\s*/u', rtrim(\App\Support\SiteModules::find('whatsapp')['summary'], '.')), 0, 4);
@endphp
<div class="phone" style="left: 150px; top: 14px">
    <div class="scr" style="background: #ECE5DD">
        <div class="row" style="background: #075E54; color: #fff; padding: 28px 16px 14px; gap: 10px">
            <span class="ic" style="width: 36px; height: 36px; background: #fff"><x-landing.icon name="cup" class="i" style="width: 20px; height: 20px" /></span>
            <span class="t" style="font-size: 20px">{{ __('Rosto') }}</span>
        </div>
        @foreach ($statuses as $status)
            <div class="t" style="margin: 12px 14px 0; padding: 10px 14px; border-radius: 14px; background: {{ $loop->last ? '#DCF8C6' : '#fff' }}; font-size: 18px; box-shadow: 0 1px 1px rgba(0,0,0,.12)">
                {{ \Illuminate\Support\Str::ucfirst($status) }} <span style="color: #34B7F1; font-size: 15px">✓✓</span>
            </div>
        @endforeach
    </div>
</div>
<div class="toast" style="left: 0; top: 70px">
    <span class="ic green"><x-landing.icon name="chat" class="i" /></span>{{ __('WhatsApp') }}
</div>
