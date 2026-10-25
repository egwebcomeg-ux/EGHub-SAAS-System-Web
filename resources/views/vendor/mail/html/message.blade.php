<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="platform_url(null)">
<span style="font-size: 24px; font-weight: 800; color: #F26522;">eg<span style="color: #0A2540;">hub</span></span>
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} EgHub · {{ __('by') }} EgWeb
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
