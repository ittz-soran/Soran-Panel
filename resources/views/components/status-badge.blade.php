{{-- A customer's standing with Soran, not their shop's health. Section 5. --}}
@props(['status'])
@php
    $look = match ($status) {
        'active' => ['success', 'Active'],
        'trial' => ['info', 'Trial'],
        'suspended' => ['warning', 'Suspended'],
        default => ['secondary', 'Ended'],
    };
@endphp
<span class="badge text-bg-{{ $look[0] }}">{{ $look[1] }}</span>
