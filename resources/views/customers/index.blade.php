@extends('layouts.app')

@section('title', 'Customers')
@section('subheading', 'Every shop sold, and what it is doing.')

@section('content')
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        {{-- The one filter Section 9 names. Both counts on the buttons, so the
             number here and the Overview's three lists are visibly the same. --}}
        <div class="btn-group" role="group" aria-label="Which customers to show">
            <a href="{{ route('customers.index') }}"
               class="btn btn-sm {{ $chasing ? 'btn-outline-secondary' : 'btn-secondary' }}">
                All <span class="badge text-bg-light ms-1">{{ $allCount }}</span>
            </a>
            <a href="{{ route('customers.index', ['show' => 'chasing']) }}"
               class="btn btn-sm {{ $chasing ? 'btn-warning' : 'btn-outline-warning' }}">
                Needs chasing <span class="badge text-bg-light ms-1">{{ $chasingCount }}</span>
            </a>
        </div>
    </div>

    @if ($customers->isEmpty())
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi {{ $chasing ? 'bi-check-circle' : 'bi-shop' }} display-4 text-secondary opacity-50"></i>
                <h2 class="h5 mt-3">{{ $chasing ? 'Nothing needs chasing' : 'No customers yet' }}</h2>
                <p class="text-secondary mb-0">
                    {{ $chasing
                        ? 'Every live shop is licensed, has room, and is being used.'
                        : 'New customer arrives with build order step 7.' }}
                </p>
            </div>
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Shop</th>
                            <th scope="col">Licence</th>
                            <th scope="col">Expires</th>
                            <th scope="col">Storage</th>
                            <th scope="col">Last used</th>
                            <th scope="col">Schema</th>
                            <th scope="col" class="text-end">A month</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($customers as $customer)
                            @php
                                $latest = $customer->latestHealthCheck;
                                // The numbers come from the last check that
                                // actually read the shop. A shop that went down
                                // an hour ago still has real figures, and
                                // "unknown" in every column would throw away
                                // the reason Section 5 keeps snapshots at all.
                                $check = $customer->lastGoodHealthCheck;
                                $stale = $check && $latest && ! $latest->reachable;
                            @endphp
                            <tr>
                                <th scope="row" class="fw-normal">
                                    <a class="text-decoration-none fw-semibold d-block"
                                       href="{{ route('customers.show', $customer) }}">{{ $customer->name }}</a>
                                    <small class="text-secondary">{{ $customer->host }}</small>
                                </th>
                                <td>
                                    <x-status-badge :status="$customer->status" />
                                    @if ($latest && ! $latest->reachable)
                                        <span class="badge text-bg-danger" title="{{ Str::limit($latest->error, 200) }}">unreachable</span>
                                    @endif
                                </td>
                                <td>
                                    <x-licence-state :licence="$customer->currentLicence" />
                                    @if ($customer->currentLicence?->expires_on)
                                        <small class="d-block text-secondary">{{ $customer->currentLicence->expires_on->toDateString() }}</small>
                                    @endif
                                </td>
                                <td style="min-width: 9rem"><x-storage-bar :check="$check" /></td>
                                <td>
                                    <x-last-used :check="$check" />
                                    @if ($stale)
                                        <small class="d-block text-secondary"
                                               title="The shop could not be read at {{ $latest->checked_at->toDayDateTimeString() }}">
                                            as of {{ $check->checked_at->diffForHumans() }}
                                        </small>
                                    @endif
                                </td>
                                <td><x-schema-state :check="$check" /></td>
                                <td class="text-end text-nowrap">{{ number_format($customer->monthly_fee) }} <small class="text-secondary">IQD</small></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-secondary small mt-2 mb-0">
            Storage, last used and schema come from the hourly check — the last one that
            could read the shop, which for an unreachable shop is dated beside it.
            “Unknown” means it has never been read at all, which is not the same as a
            shop with nothing in it.
        </p>
    @endif
@endsection
