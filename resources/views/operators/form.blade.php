@extends('layouts.app')

@section('title', $operator->exists ? $operator->name : 'Add an operator')
@section('subheading', $operator->exists ? 'Change what this operator may do.' : 'Somebody else who may sign in to the panel.')

@section('content')
<div class="row">
    <div class="col-12 col-lg-7">
        <div class="card">
            <form method="POST" data-guard-submit
                  action="{{ $operator->exists ? route('operators.update', $operator) : route('operators.store') }}">
                @csrf
                @if ($operator->exists) @method('PUT') @endif

                <div class="card-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" id="name" name="name" required autofocus
                               class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $operator->name) }}">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" id="email" name="email" required autocomplete="username"
                               class="form-control @error('email') is-invalid @enderror"
                               value="{{ old('email', $operator->email) }}">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">What they sign in with. No mail is ever sent to it.</div>
                    </div>

                    <div class="mb-3">
                        <label for="role" class="form-label">Role</label>
                        <select id="role" name="role" class="form-select @error('role') is-invalid @enderror">
                            <option value="admin" @selected(old('role', $operator->role) === 'admin')>Admin — everything</option>
                            <option value="staff" @selected(old('role', $operator->role) === 'staff')>Staff — the same for now</option>
                        </select>
                        @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            Staff does nothing different yet. The shape is here so it can later,
                            without a migration on a live panel.
                        </div>
                    </div>

                    <hr>

                    <div class="mb-3">
                        <label for="password" class="form-label">
                            Password
                            @if ($operator->exists)<span class="text-secondary fw-normal">— leave empty to keep it</span>@endif
                        </label>
                        <input type="password" id="password" name="password" autocomplete="new-password"
                               class="form-control @error('password') is-invalid @enderror"
                               @required(! $operator->exists)>
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">At least 12 characters.</div>
                    </div>

                    <div class="mb-0">
                        <label for="password_confirmation" class="form-label">Password again</label>
                        <input type="password" id="password_confirmation" name="password_confirmation"
                               autocomplete="new-password" class="form-control"
                               @required(! $operator->exists)>
                    </div>
                </div>

                <div class="card-footer d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        {{ $operator->exists ? 'Save' : 'Add them' }}
                    </button>
                    <a href="{{ route('operators.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>

        @unless ($operator->exists)
            <div class="alert alert-info mt-3 d-flex align-items-start gap-2">
                <i class="bi bi-shield-check mt-1"></i>
                <div>
                    They will need to set up an authenticator themselves, from their own account
                    menu. There is no forgotten-password email on this hosting, so it is the only
                    way back in if they lose their password.
                </div>
            </div>
        @endunless
    </div>
</div>
@endsection
