@extends('layouts.app')

@section('title', 'What I changed')
@section('subheading', 'Everything the panel has done, and who told it to.')

@section('content')
    <form method="GET" class="card mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-sm-4">
                    <label for="customer" class="form-label small mb-1">Shop</label>
                    <select id="customer" name="customer" class="form-select form-select-sm">
                        <option value="">Any</option>
                        @foreach ($customers as $option)
                            <option value="{{ $option->id }}" @selected(($chosen['customer'] ?? null) == $option->id)>
                                {{ $option->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-sm-3">
                    <label for="user" class="form-label small mb-1">Who</label>
                    <select id="user" name="user" class="form-select form-select-sm">
                        <option value="">Anyone</option>
                        @foreach ($operators as $option)
                            <option value="{{ $option->id }}" @selected(($chosen['user'] ?? null) == $option->id)>
                                {{ $option->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-sm-3">
                    <label for="action" class="form-label small mb-1">What</label>
                    <select id="action" name="action" class="form-select form-select-sm">
                        <option value="">Anything</option>
                        @foreach ($kinds as $kind)
                            <option value="{{ $kind }}" @selected(($chosen['action'] ?? null) === $kind)>{{ $kind }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-sm-2 d-flex gap-2">
                    <button class="btn btn-sm btn-secondary flex-grow-1">Show</button>
                    <a href="{{ route('actions.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                </div>
            </div>
        </div>
    </form>

    @if ($actions->isEmpty())
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi bi-clock-history display-4 text-secondary opacity-50"></i>
                <h2 class="h5 mt-3">Nothing recorded yet</h2>
                <p class="text-secondary mb-0">
                    Every licence delivered, limit changed, shop suspended, payment recorded and operator
                    added will appear here, with the name of whoever did it.
                </p>
            </div>
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">What</th>
                            <th scope="col">Shop</th>
                            <th scope="col">Who</th>
                            <th scope="col">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($actions as $action)
                            <tr>
                                <td class="text-nowrap" title="{{ $action->created_at?->toDayDateTimeString() }}">
                                    {{ $action->created_at?->diffForHumans() ?? '—' }}
                                </td>
                                <td><code>{{ $action->action }}</code></td>
                                <td>
                                    @if ($action->customer)
                                        <a href="{{ route('customers.show', $action->customer) }}"
                                           class="text-decoration-none">{{ $action->customer->name }}</a>
                                    @else
                                        <span class="text-secondary">the panel itself</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $action->user?->name ?? 'an account since removed' }}
                                    @if ($action->ip_address)
                                        <small class="d-block text-secondary">{{ $action->ip_address }}</small>
                                    @endif
                                </td>
                                <td class="small">
                                    @if ($action->detail)
                                        <code class="text-body-secondary">{{ Str::limit(json_encode($action->detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 140) }}</code>
                                    @else
                                        <span class="text-secondary">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($actions->hasPages())
                <div class="card-footer">{{ $actions->links() }}</div>
            @endif
        </div>

        <p class="text-secondary small mt-2 mb-0">
            Nothing can be edited or deleted here, or anywhere else. The table has no
            <code>updated_at</code> — a log somebody can change is not a log.
        </p>
    @endif
@endsection
