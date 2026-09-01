@extends('layouts.app')

@section('title', 'Health')
@section('subheading', 'What each shop says about itself, asked every hour.')

@section('content')
    <div class="row g-3 mb-3">
        @php
            $tiles = [
                ['Live shops', $counts['live'], 'being watched', null],
                ['Unreachable', $counts['unreachable'], 'at the last check', $counts['unreachable'] > 0 ? 'text-danger' : null],
                ['Behind on migrations', $counts['behind'], 'running old schema', $counts['behind'] > 0 ? 'text-warning' : null],
                ['Contradicting themselves', $counts['contradicting'], 'the data check disagrees', $counts['contradicting'] > 0 ? 'text-danger' : null],
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

    @if ($lastRun)
        <p class="text-secondary small">
            Last run {{ \Illuminate\Support\Carbon::parse($lastRun)->diffForHumans() }}.
            The schedule asks every hour; <code>shops:check</code> does the asking.
        </p>
    @else
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle mt-1"></i>
            <div>
                <strong>No shop has ever been checked.</strong>
                Nothing below can say anything until <code>php artisan shops:check</code> has run once —
                on a server that is cron calling <code>schedule:run</code>; locally you can run it by hand.
            </div>
        </div>
    @endif

    @if ($customers->isEmpty())
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi bi-graph-up display-4 text-secondary opacity-50"></i>
                <h2 class="h5 mt-3">Nothing to watch yet</h2>
                <p class="text-secondary mb-0">Shops appear here once there is a customer.</p>
            </div>
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Shop</th>
                            <th scope="col">Read</th>
                            <th scope="col">Its own licence verdict</th>
                            <th scope="col">Schema</th>
                            <th scope="col">Data check</th>
                            <th scope="col">Storage</th>
                            <th scope="col">Last used</th>
                            <th scope="col" class="text-end">—</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($customers as $customer)
                            @php
                                $latest = $customer->latestHealthCheck;
                                $check = $customer->lastGoodHealthCheck;
                                $passed = $check?->dataCheckPassed();
                            @endphp
                            <tr @class(['opacity-50' => ! $customer->isLive()])>
                                <th scope="row" class="fw-normal">
                                    <a class="text-decoration-none fw-semibold d-block"
                                       href="{{ route('customers.show', $customer) }}">{{ $customer->name }}</a>
                                    <small class="text-secondary">{{ $customer->host }}</small>
                                </th>
                                <td>
                                    @if ($latest === null)
                                        <span class="text-secondary">never</span>
                                    @elseif ($latest->reachable)
                                        <span class="text-success" title="{{ $latest->checked_at->toDayDateTimeString() }}">
                                            <i class="bi bi-check-circle me-1"></i>{{ $latest->checked_at->diffForHumans() }}
                                        </span>
                                    @else
                                        <span class="text-danger" title="{{ Str::limit($latest->error, 300) }}">
                                            <i class="bi bi-x-lg me-1"></i>could not be read
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    @if ($check?->licence_state)
                                        @php($good = in_array($check->licence_state, ['valid', 'expiring', 'unlicensed'], true))
                                        <code class="{{ $good ? '' : 'text-danger fw-semibold' }}">{{ $check->licence_state }}</code>
                                    @else
                                        <span class="text-secondary">unknown</span>
                                    @endif
                                </td>
                                <td><x-schema-state :check="$check" /></td>
                                <td>
                                    @if ($passed === null)
                                        <span class="text-secondary">unknown</span>
                                    @elseif ($passed)
                                        <span class="text-success">{{ $check->data_check_total }} agree</span>
                                    @else
                                        <span class="text-danger fw-semibold">
                                            {{ $check->data_check_total - $check->data_check_passed }} disagree
                                        </span>
                                    @endif
                                </td>
                                <td style="min-width: 9rem"><x-storage-bar :check="$check" /></td>
                                <td><x-last-used :check="$check" /></td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('health.recheck', $customer) }}" class="m-0">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-secondary">Look now</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-secondary small mt-2 mb-0">
            These are the shops' own answers, not the panel's opinion of them. The data check reports and
            never repairs: a contradiction is evidence, and repairing it before it has been read destroys
            the record of what went wrong.
        </p>
    @endif
@endsection
