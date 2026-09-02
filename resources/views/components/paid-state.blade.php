{{--
    Whether a customer owes money — and never whether their licence works.

    Section 9 keeps these apart on purpose. A shop can be trading perfectly on a
    licence that was delivered before the money arrived, and a screen that read
    the licence here would show them as settled.
--}}
@props(['customer'])
@php
    $paidUpTo = $customer->paidUpTo();
    $late = $customer->daysLate();
@endphp

@if (! $customer->isLive())
    <span class="badge text-bg-light">not trading</span>
@elseif ($paidUpTo === null)
    <span class="badge text-bg-danger">never paid</span>
@elseif ($late === null)
    <span class="badge text-bg-success">paid to {{ $paidUpTo->toDateString() }}</span>
@else
    <span class="badge text-bg-danger">{{ $late }} {{ Str::plural('day', $late) }} late</span>
@endif
