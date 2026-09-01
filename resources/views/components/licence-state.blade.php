{{--
    What the licence is doing, from the panel's own record.

    Deliberately not the shop's own `licence_state` from the health check: that
    is the cross-check, and showing one where the other is meant would hide the
    disagreement this panel exists to catch.
--}}
@props(['licence'])
@php
    $days = $licence?->daysLeft();

    [$variant, $text] = match (true) {
        $licence === null => ['danger', 'No licence'],
        $licence->isPerpetual() => ['success', 'No end date'],
        $days < 0 => ['danger', 'Expired ' . abs($days) . ' ' . Str::plural('day', abs($days)) . ' ago'],
        $days === 0 => ['danger', 'Ends today'],
        $days <= config('panel.attention.licence_days') => ['warning', $days . ' ' . Str::plural('day', $days) . ' left'],
        default => ['success', $days . ' days left'],
    };
@endphp
<span class="badge text-bg-{{ $variant }}">{{ $text }}</span>
