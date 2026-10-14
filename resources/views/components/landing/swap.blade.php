@props(['values'])
{{-- A value that changes with the scene step: ['1-2' => '65', '3-' => '70'] stacks both in one cell. --}}
<span {{ $attributes->merge(['class' => 'inline-grid *:[grid-area:1/1]']) }}>@foreach ($values as $range => $value)<span data-show="{{ $range }}" class="sc-fade">{{ $value }}</span>@endforeach</span>
