<x-guest-layout>
    {{-- The way back in that does not go through the post: the address, the six
         digits off the enrolled phone, and the new password, in one step. --}}
    <h2 class="h6 mb-1">Back in with the authenticator</h2>
    <p class="text-secondary small">
        The six digits your authenticator app is showing right now, or one of the
        recovery codes written down when it was set up.
    </p>

    <form method="POST" action="{{ route('password.recover.update') }}">
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input id="email" name="email" type="email" autocomplete="username"
                   class="form-control @error('email') is-invalid @enderror"
                   value="{{ old('email') }}" required autofocus>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="code" class="form-label">Six digits, or a recovery code</label>
            <input id="code" name="code" type="text" autocomplete="one-time-code"
                   class="form-control @error('code') is-invalid @enderror" required>
            @error('code')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">New password</label>
            <input id="password" name="password" type="password" autocomplete="new-password"
                   class="form-control @error('password') is-invalid @enderror" required>
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password_confirmation" class="form-label">New password again</label>
            <input id="password_confirmation" name="password_confirmation" type="password"
                   autocomplete="new-password" class="form-control" required>
        </div>

        <button type="submit" class="btn btn-primary w-100">Set the new password</button>

        <div class="text-center mt-3">
            <a href="{{ route('login') }}" class="small link-secondary">Back to signing in</a>
        </div>
    </form>
</x-guest-layout>
