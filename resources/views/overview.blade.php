@extends('layouts.app')

@section('title', 'Overview')
@section('subheading', 'Only what needs you this week.')

@section('content')
@php
    $lists = [
        ['expiring', $expiring, 'Licences running out', 'bi-key',
         "Within {$days} days, and anything already past."],
        ['full', $full, 'Storage near its limit', 'bi-hdd',
         "At {$percent}% of what the shop is allowed."],
        ['unused', $unused, 'Nobody has used them', 'bi-moon-stars',
         "Nothing has happened in the shop for {$unusedDays} days."],
    ];
    // Distinct shops, from the controller — not the three lists added up. A
    // shop on two lists is one shop.
    $needing = $counts['needing'];
@endphp

@if ($counts['all'] === 0)
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-shop display-4 text-secondary opacity-50"></i>
            <h2 class="h5 mt-3">No customers yet</h2>
            <p class="text-secondary mb-0">
                Licences running out, storage near its limit and shops nobody has
                used will appear here once there is a customer to watch.
            </p>
        </div>
    </div>
@else
    {{-- The lists first: these are things to do something about. The numbers
         below are things to know, and a screen that gives them equal weight is
         a screen that gets skimmed. --}}
    @if ($needing === 0)
        <div class="alert alert-success d-flex align-items-center gap-2">
            <i class="bi bi-check-circle"></i>
            <div>Nothing needs you this week. Every live shop is licensed, has room, and is being used.</div>
        </div>
    @else
        <div class="row g-3">
            @foreach ($lists as [$key, $rows, $heading, $icon, $because])
                @continue($rows->isEmpty())
                <div class="col-12 col-xl-4">
                    <div class="card h-100">
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <span><i class="bi {{ $icon }} me-2"></i>{{ $heading }}</span>
                            <span class="badge text-bg-secondary">{{ $rows->count() }}</span>
                        </div>
                        <div class="list-group list-group-flush">
                            @foreach ($rows as $customer)
                                <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2"
                                   href="{{ route('customers.show', $customer) }}">
                                    <span class="min-w-0">
                                        <span class="d-block text-truncate">{{ $customer->name }}</span>
                                        <small class="text-secondary d-block text-truncate">{{ $customer->host }}</small>
                                    </span>
                                    <span class="text-nowrap">
                                        @if ($key === 'expiring')
                                            <x-licence-state :licence="$customer->currentLicence" />
                                        @elseif ($key === 'full')
                                            <span class="badge text-bg-danger">{{ $customer->latestHealthCheck?->storagePercent() }}%</span>
                                        @else
                                            <x-last-used :check="$customer->latestHealthCheck" />
                                        @endif
                                    </span>
                                </a>
                            @endforeach
                        </div>
                        <div class="card-footer text-secondary small">{{ $because }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Everything else is a number — Section 9. --}}
    <div class="row g-3 mt-1">
        @php
            $tiles = [
                ['Live shops', $counts['live'], $counts['all'] . ' in total', null],
                ['Needing you', $needing, 'shops on the lists above', $needing > 0 ? 'text-warning' : null],
                ['Unreachable', $counts['unreachable'], 'at the last hourly check', $counts['unreachable'] > 0 ? 'text-danger' : null],
                ['A month', number_format($monthly) . ' IQD', 'from the live shops', null],
            ];
        @endphp
        @foreach ($tiles as [$label, $value, $note, $colour])
            <div class="col-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-secondary small">{{ $label }}</div>
                        <div class="fs-4 fw-semibold {{ $colour }}">{{ $value }}</div>
                        <div class="text-secondary small">{{ $note }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if ($counts['never_checked'] > 0)
        <div class="alert alert-warning mt-3 d-flex align-items-center gap-2">
            <i class="bi bi-info-circle"></i>
            <div>
                {{ $counts['never_checked'] }} live
                {{ Str::plural('shop', $counts['never_checked']) }}
                {{ $counts['never_checked'] === 1 ? 'has' : 'have' }}
                never been checked. Storage and usage are unknown until
                <code>shops:check</code> has run once.
            </div>
        </div>
    @endif
@endif
@endsection
