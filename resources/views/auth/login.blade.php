<x-guest-layout>
    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input id="email" name="email" type="email" inputmode="email" autocomplete="username"
                   class="form-control @error('email') is-invalid @enderror"
                   value="{{ old('email') }}" required autofocus>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password"
                   class="form-control @error('password') is-invalid @enderror" required>
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-check mb-3">
            <input id="remember" name="remember" type="checkbox" class="form-check-input">
            <label for="remember" class="form-check-label">Stay signed in</label>
        </div>

        <button type="submit" class="btn btn-primary w-100">Sign in</button>

        <div class="text-center mt-3">
            <a href="{{ route('password.recover') }}" class="small link-secondary">
                Forgotten the password?
            </a>
        </div>
    </form>
</x-guest-layout>
