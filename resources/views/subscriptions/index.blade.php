@extends('layouts.app')

@section('title', 'Subscriptions')
@section('subheading', 'Who has paid, who has not, and what the month is worth.')

@section('content')
    <div class="row g-3 mb-3">
        @php
            $tiles = [
                ['A month', number_format($monthly).' IQD', 'if every live shop pays', null],
                ['Owed now', number_format($outstanding).' IQD', $owingCount.' '.Str::plural('shop', $owingCount).' behind',
                    $outstanding > 0 ? 'text-danger' : null],
                ['Came in this month', number_format($thisMonth).' IQD', 'recorded against '.now()->format('F'), null],
                ['Last month', number_format($lastMonth).' IQD', now()->subMonthNoOverflow()->format('F'), null],
            ];
        @endphp
        @foreach ($tiles as [$label, $value, $note, $colour])
            <div class="col-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-secondary small">{{ $label }}</div>
                        <div class="fs-5 fw-semibold {{ $colour }}">{{ $value }}</div>
                        <div class="text-secondary small">{{ $note }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="btn-group mb-3" role="group" aria-label="Which customers to show">
        <a href="{{ route('subscriptions.index') }}"
           class="btn btn-sm {{ $owing ? 'btn-outline-secondary' : 'btn-secondary' }}">
            All <span class="badge text-bg-light ms-1">{{ $allCount }}</span>
        </a>
        <a href="{{ route('subscriptions.index', ['show' => 'owing']) }}"
           class="btn btn-sm {{ $owing ? 'btn-danger' : 'btn-outline-danger' }}">
            Owing <span class="badge text-bg-light ms-1">{{ $owingCount }}</span>
        </a>
    </div>

    @if ($customers->isEmpty())
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi {{ $owing ? 'bi-check-circle' : 'bi-cash-coin' }} display-4 text-secondary opacity-50"></i>
                <h2 class="h5 mt-3">{{ $owing ? 'Everybody is paid up' : 'No customers yet' }}</h2>
                <p class="text-secondary mb-0">
                    {{ $owing
                        ? 'Every live shop has paid for a period that reaches today.'
                        : 'Payments are recorded against a customer once there is one.' }}
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
                            <th scope="col">Standing</th>
                            <th scope="col">Paid up to</th>
                            <th scope="col">Last payment</th>
                            <th scope="col" class="text-end">A month</th>
                            <th scope="col" class="text-end">Owes</th>
                            <th scope="col" class="text-end">—</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($customers as $customer)
                            @php($last = $customer->payments->first())
                            <tr>
                                <th scope="row" class="fw-normal">
                                    <a class="text-decoration-none fw-semibold d-block"
                                       href="{{ route('customers.show', $customer) }}">{{ $customer->name }}</a>
                                    <small class="text-secondary">{{ $customer->host }}</small>
                                </th>
                                <td><x-paid-state :customer="$customer" /></td>
                                <td>
                                    {{ $customer->paidUpTo()?->toDateString() ?? '—' }}
                                    @if ($customer->monthsOwed() > 1)
                                        <small class="d-block text-danger">
                                            {{ $customer->monthsOwed() }} months
                                        </small>
                                    @endif
                                </td>
                                <td>
                                    @if ($last)
                                        {{ number_format($last->amount) }}
                                        <small class="d-block text-secondary">
                                            {{ $last->paid_on->toDateString() }}{{ $last->method ? ' · '.$last->method : '' }}
                                        </small>
                                    @else
                                        <span class="text-secondary">—</span>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">{{ number_format($customer->monthly_fee) }}</td>
                                <td class="text-end text-nowrap">
                                    @if ($customer->owes() > 0)
                                        <span class="text-danger fw-semibold">{{ number_format($customer->owes()) }}</span>
                                    @else
                                        <span class="text-secondary">—</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('subscriptions.show', $customer) }}"
                                       class="btn btn-sm btn-outline-secondary">Payments</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-secondary small mt-2 mb-0">
            This is money, and only money. A shop can be trading perfectly on a licence that was
            delivered before it was paid for, so nothing here reads a licence.
        </p>
    @endif
@endsection
