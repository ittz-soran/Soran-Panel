@extends('layouts.app')

@section('title', 'Authenticator')
@section('subheading', 'The way back into the panel if the password is lost.')

@section('content')
    @if(session('recovery_codes'))
        {{-- Shown once, here, and never again — nothing holds them in the clear. --}}
        <div class="card border-warning mb-3">
            <div class="card-header bg-warning-subtle">
                <i class="bi bi-exclamation-triangle me-1"></i>Write these down now
            </div>
            <div class="card-body">
                <p class="text-secondary small">
                    Each one works once, and this is the only time they are shown.
                    They are what gets you in when the phone is lost.
                </p>
                <div class="row row-cols-2 row-cols-md-4 g-2 font-monospace">
                    @foreach(session('recovery_codes') as $code)
                        <div class="col"><div class="border rounded px-2 py-1 text-center">{{ $code }}</div></div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    @if($user->hasAuthenticator())
        <div class="card mb-3">
            <div class="card-body d-flex align-items-center gap-3">
                <i class="bi bi-shield-check fs-1 text-success"></i>
                <div>
                    <div class="fw-semibold">The authenticator is on.</div>
                    <div class="text-secondary small">
                        Turned on {{ $user->two_factor_confirmed_at->diffForHumans() }}.
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">New recovery codes</div>
                    <div class="card-body">
                        <p class="text-secondary small">
                            For when the list has been used up, or seen by the wrong
                            person. The old ones stop working at once.
                        </p>
                        <form method="POST" action="{{ route('authenticator.codes') }}">
                            @csrf
                            <div class="mb-2">
                                <label for="codes_password" class="form-label">Current password</label>
                                <input id="codes_password" name="password" type="password"
                                       autocomplete="current-password"
                                       class="form-control @error('password') is-invalid @enderror" required>
                                @error('password')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <button type="submit" class="btn btn-outline-secondary">Make a new set</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card h-100 border-danger">
                    <div class="card-header bg-danger-subtle">Danger zone</div>
                    <div class="card-body">
                        <p class="text-secondary small">
                            Turning it off leaves the password as the only way in. If
                            that is forgotten there is no way back into the panel at
                            all — and behind the panel is every customer's install.
                        </p>
                        <form method="POST" action="{{ route('authenticator.destroy') }}">
                            @csrf
                            @method('DELETE')
                            <div class="mb-2">
                                <label for="off_password" class="form-label">Current password</label>
                                <input id="off_password" name="password" type="password"
                                       autocomplete="current-password" class="form-control" required>
                            </div>
                            {{-- Section 7: a two-second hold on anything that cannot
                                 be undone. app.js (the shop system's, reused) reads
                                 data-hold. --}}
                            <button type="submit" class="btn btn-danger" data-hold="2000">
                                Turn the authenticator off
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="alert alert-warning d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle"></i>
            <div>
                There is no authenticator on this account. Forget the password and
                there is no way back in — nothing here can email you a link.
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-md-auto text-center">
                        <div class="bg-white d-inline-block p-2 rounded border">{!! $qr !!}</div>
                    </div>
                    <div class="col">
                        <h2 class="h6">1. Scan the square</h2>
                        <p class="text-secondary small">
                            With Google Authenticator, Microsoft Authenticator, or any
                            app that takes a QR code.
                        </p>

                        <h2 class="h6">Or type it in by hand</h2>
                        <p class="font-monospace user-select-all border rounded px-2 py-1 d-inline-block">{{ $readable }}</p>

                        <h2 class="h6 mt-3">2. Type back the six digits it shows</h2>
                        <p class="text-secondary small">
                            Nothing is saved until a code comes back correct. A secret
                            nobody has proved is worse than none at all — it looks
                            like a way in, on a screen, and is not one.
                        </p>

                        <form method="POST" action="{{ route('authenticator.confirm') }}" class="row g-2 align-items-start">
                            @csrf
                            <div class="col-auto">
                                <input name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                                       class="form-control font-monospace @error('code') is-invalid @enderror"
                                       style="max-width: 10rem" placeholder="000000" required autofocus>
                                @error('code')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary">Turn it on</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection
