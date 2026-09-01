@extends('layouts.app')

@section('title', 'Payments · '.$customer->name)
@section('heading', $customer->name)
@section('subheading', 'Every payment ever recorded, and what they owe.')

@section('actions')
    <a href="{{ route('customers.show', $customer) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to the shop
    </a>
@endsection

@section('content')
<div class="row g-3">
    <div class="col-12 col-xl-5">
        <div class="card mb-3">
            <div class="card-header">Where they stand</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 fw-normal text-secondary">Standing</dt>
                    <dd class="col-7"><x-paid-state :customer="$customer" /></dd>

                    <dt class="col-5 fw-normal text-secondary">Paid up to</dt>
                    <dd class="col-7">{{ $paidUpTo?->toDateString() ?? 'nothing recorded' }}</dd>

                    <dt class="col-5 fw-normal text-secondary">A month</dt>
                    <dd class="col-7">{{ number_format($customer->monthly_fee) }} IQD</dd>

                    <dt class="col-5 fw-normal text-secondary">Owes now</dt>
                    <dd class="col-7">
                        @if ($customer->owes() > 0)
                            <span class="text-danger fw-semibold">{{ number_format($customer->owes()) }} IQD</span>
                            <span class="d-block text-secondary">{{ $customer->monthsOwed() }} {{ Str::plural('month', $customer->monthsOwed()) }}</span>
                        @else
                            <span class="text-success">nothing</span>
                        @endif
                    </dd>

                    <dt class="col-5 fw-normal text-secondary">Started</dt>
                    <dd class="col-7">{{ $customer->started_on?->toDateString() ?? '—' }}</dd>
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Record a payment</div>
            <form method="POST" action="{{ route('subscriptions.store', $customer) }}" data-guard-submit>
                @csrf
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-12 col-sm-6">
                            <label for="amount" class="form-label small">Amount (IQD)</label>
                            <input type="number" min="1" step="1" id="amount" name="amount" required
                                   class="form-control @error('amount') is-invalid @enderror"
                                   value="{{ old('amount', $customer->monthly_fee) }}">
                            @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="paid_on" class="form-label small">Arrived on</label>
                            <input type="date" id="paid_on" name="paid_on" required
                                   class="form-control @error('paid_on') is-invalid @enderror"
                                   value="{{ old('paid_on', now()->toDateString()) }}">
                            @error('paid_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6">
                            <label for="covers_from" class="form-label small">Covers from</label>
                            <input type="date" id="covers_from" name="covers_from" required
                                   class="form-control @error('covers_from') is-invalid @enderror"
                                   value="{{ old('covers_from', ($paidUpTo?->copy()->addDay() ?? now())->toDateString()) }}">
                            @error('covers_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6">
                            <label for="covers_to" class="form-label small">Covers to</label>
                            <input type="date" id="covers_to" name="covers_to" required
                                   class="form-control @error('covers_to') is-invalid @enderror"
                                   value="{{ old('covers_to', ($paidUpTo?->copy()->addMonth() ?? now()->addMonth()->subDay())->toDateString()) }}">
                            @error('covers_to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="method" class="form-label small">How</label>
                            <input type="text" id="method" name="method" class="form-control" list="methods"
                                   value="{{ old('method', 'cash') }}">
                            <datalist id="methods">
                                <option value="cash"><option value="FIB"><option value="FastPay"><option value="transfer">
                            </datalist>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="reference" class="form-label small">Reference <span class="text-secondary">(optional)</span></label>
                            <input type="text" id="reference" name="reference" class="form-control"
                                   value="{{ old('reference') }}">
                        </div>
                        <div class="col-12">
                            <label for="note" class="form-label small">Note <span class="text-secondary">(optional)</span></label>
                            <input type="text" id="note" name="note" class="form-control" value="{{ old('note') }}">
                        </div>
                    </div>

                    <div class="form-text mt-2">
                        Two dates, not one: when the money arrived, and which period it buys. A customer
                        who pays three months at once is not chased next week.
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">Record it</button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-12 col-xl-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Every payment</span>
                <span class="badge text-bg-secondary">{{ $payments->count() }}</span>
            </div>

            @if ($payments->isEmpty())
                <div class="card-body text-secondary">Nothing recorded yet.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Covers</th>
                                <th scope="col" class="text-end">Amount</th>
                                <th scope="col">Arrived</th>
                                <th scope="col">How</th>
                                <th scope="col">Recorded by</th>
                                <th scope="col" class="text-end">—</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($payments as $payment)
                                <tr>
                                    <td class="text-nowrap">
                                        {{ $payment->covers_from->toDateString() }}
                                        <span class="text-secondary">→</span>
                                        {{ $payment->covers_to->toDateString() }}
                                        <small class="d-block text-secondary">
                                            {{ $payment->monthsCovered() }} {{ Str::plural('month', $payment->monthsCovered()) }}
                                        </small>
                                    </td>
                                    <td class="text-end fw-semibold text-nowrap">{{ number_format($payment->amount) }}</td>
                                    <td class="text-nowrap">{{ $payment->paid_on->toDateString() }}</td>
                                    <td>
                                        {{ $payment->method ?? '—' }}
                                        @if ($payment->reference)
                                            <small class="d-block text-secondary">{{ $payment->reference }}</small>
                                        @endif
                                    </td>
                                    <td>{{ $payment->recordedBy?->name ?? '—' }}</td>
                                    <td class="text-end">
                                        <x-danger-form
                                            :action="route('subscriptions.destroy', [$customer, $payment])"
                                            method="DELETE"
                                            label="Remove"
                                            :confirm="(string) $payment->amount"
                                            :confirmLabel="'Type '.$payment->amount.' to remove it'" />
                                    </td>
                                </tr>
                                @if ($payment->note)
                                    <tr class="table-light">
                                        <td colspan="6" class="small text-secondary">{{ $payment->note }}</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @if ($removed->isNotEmpty())
            <div class="card mt-3">
                <div class="card-header">
                    Removed
                    <small class="text-secondary">— still on record, no longer counted</small>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 small">
                        <tbody>
                            @foreach ($removed as $payment)
                                <tr class="opacity-75">
                                    <td class="text-nowrap">
                                        {{ $payment->covers_from->toDateString() }} → {{ $payment->covers_to->toDateString() }}
                                    </td>
                                    <td class="text-end">{{ number_format($payment->amount) }}</td>
                                    <td class="text-secondary">removed {{ $payment->deleted_at->diffForHumans() }}</td>
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('subscriptions.restore', [$customer, $payment->id]) }}" class="m-0">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-secondary">Count it again</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
