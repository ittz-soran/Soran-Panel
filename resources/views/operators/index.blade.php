@extends('layouts.app')

@section('title', 'Operators')
@section('subheading', 'Who may sign in to the panel.')

@section('actions')
    <a href="{{ route('operators.create') }}" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Add an operator
    </a>
@endsection

@section('content')
    @if ($admins <= 1)
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle mt-1"></i>
            <div>
                <strong>There is only one admin.</strong>
                There is no sign-up page and no forgotten-password email — the authenticator is
                the only way back in. If this account is lost, the panel has to be opened with a
                database edit on the server. Add a second admin.
            </div>
        </div>
    @endif

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Role</th>
                        <th scope="col">Authenticator</th>
                        <th scope="col">Signs in</th>
                        <th scope="col" class="text-end">—</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($operators as $operator)
                        @php($isMe = $operator->is(auth()->user()))
                        <tr @class(['opacity-50' => $operator->trashed()])>
                            <th scope="row" class="fw-normal">
                                <span class="fw-semibold">{{ $operator->name }}</span>
                                @if ($isMe)<span class="badge text-bg-primary ms-1">you</span>@endif
                                <small class="d-block text-secondary">{{ $operator->email }}</small>
                            </th>
                            <td>
                                <span class="badge text-bg-{{ $operator->isAdmin() ? 'secondary' : 'light' }}">
                                    {{ $operator->isAdmin() ? 'Admin' : 'Staff' }}
                                </span>
                            </td>
                            <td>
                                @if ($operator->hasAuthenticator())
                                    <span class="text-success"><i class="bi bi-shield-check me-1"></i>on</span>
                                @else
                                    <span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>off</span>
                                @endif
                            </td>
                            <td>
                                @if ($operator->trashed())
                                    <span class="badge text-bg-secondary">removed</span>
                                @elseif ($operator->is_active)
                                    <span class="text-success">yes</span>
                                @else
                                    <span class="text-secondary">switched off</span>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex gap-1 justify-content-end flex-wrap">
                                    @if ($operator->trashed())
                                        <form method="POST" action="{{ route('operators.restore', $operator->id) }}" class="m-0">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-secondary">Bring back</button>
                                        </form>
                                    @else
                                        <a href="{{ route('operators.edit', $operator) }}"
                                           class="btn btn-sm btn-outline-secondary">Edit</a>

                                        @if ($operator->hasAuthenticator())
                                            <x-danger-form
                                                :action="route('operators.authenticator', $operator)"
                                                label="Clear authenticator"
                                                variant="warning"
                                                :confirm="$operator->email"
                                                confirmLabel="Type their email to clear it" />
                                        @endif

                                        <form method="POST" action="{{ route('operators.deactivate', $operator) }}" class="m-0">
                                            @csrf
                                            <input type="hidden" name="active" value="{{ $operator->is_active ? 0 : 1 }}">
                                            <button class="btn btn-sm btn-outline-secondary"
                                                    @disabled($isMe && $operator->is_active)
                                                    @if($isMe && $operator->is_active) title="You cannot switch off your own account." @endif>
                                                {{ $operator->is_active ? 'Switch off' : 'Switch on' }}
                                            </button>
                                        </form>

                                        <x-danger-form
                                            :action="route('operators.destroy', $operator)"
                                            method="DELETE"
                                            label="Remove"
                                            :disabled="$isMe"
                                            reason="Not your own account"
                                            :confirm="$isMe ? null : $operator->email"
                                            confirmLabel="Type their email to remove them" />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-secondary small mt-2 mb-0">
        Removing an operator hides them; their name stays on everything they did, because a
        record of who reached into a customer's shop is no use once the name can vanish.
    </p>
@endsection
