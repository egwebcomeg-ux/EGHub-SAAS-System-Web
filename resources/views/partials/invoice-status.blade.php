{{-- a transfer the restaurant claimed is "awaiting confirmation"; an online checkout in progress is not a claim --}}
@php($pending = $invoice->status === 'open' && $invoice->payments->contains(fn ($p) => $p->status === 'pending' && ! $p->isOnline()))
@if ($invoice->status === 'paid')
    <span class="badge bg-emerald-100 text-emerald-800">{{ __('Paid') }}</span>
@elseif ($invoice->status === 'void')
    <span class="badge bg-navy-100 text-navy-500">{{ __('Void') }}</span>
@elseif ($pending)
    <span class="badge bg-amber-100 text-amber-800">{{ __('Awaiting confirmation') }}</span>
@else
    <span class="badge bg-brand-100 text-brand-700">{{ __('Unpaid') }}</span>
@endif
