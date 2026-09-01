@extends('layouts.app')

@section('title', 'New customer')
@section('subheading', 'A shop from nothing: its database, its folder, its tables, and a way in.')

@section('content')
<div class="row g-3">
    <div class="col-12 col-xl-7">
        <form method="POST" action="{{ route('customers.store') }}" data-guard-submit>
            @csrf

            <div class="card mb-3">
                <div class="card-header">The shop</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Shop name</label>
                        <input type="text" id="name" name="name" required autofocus
                               class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name') }}" placeholder="Hawler Computer">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">As it should read on their own screen, and on their licence.</div>
                    </div>

                    <div class="mb-3">
                        <label for="short_name" class="form-label">Short name</label>
                        <input type="text" id="short_name" name="short_name" required
                               class="form-control font-monospace @error('short_name') is-invalid @enderror"
                               value="{{ old('short_name') }}" placeholder="hawler"
                               pattern="[a-z][a-z0-9]*" maxlength="20"
                               autocapitalize="off" autocomplete="off" spellcheck="false">
                        @error('short_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            Lower-case letters and numbers. It becomes the folder, the database
                            <code><span data-short-echo>hawler</span>_shop</code> and the database user
                            <code><span data-short-echo>hawler</span>_user</code>, so it cannot be changed later.
                        </div>
                    </div>

                    <div class="mb-0">
                        <label for="host" class="form-label">Domain</label>
                        <input type="text" id="host" name="host" required
                               class="form-control font-monospace @error('host') is-invalid @enderror"
                               value="{{ old('host') }}" placeholder="hawler.soranstore.com"
                               autocapitalize="off" autocomplete="off" spellcheck="false">
                        @error('host')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            Point it at the shop's public folder in cPanel first. The licence is bound to
                            this, so it has to be right.
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">Who to ring</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-sm-6">
                            <label for="contact_name" class="form-label">Contact</label>
                            <input type="text" id="contact_name" name="contact_name" class="form-control"
                                   value="{{ old('contact_name') }}">
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="text" id="phone" name="phone" class="form-control"
                                   value="{{ old('phone') }}" placeholder="0750…">
                        </div>
                        <div class="col-12">
                            <label for="email" class="form-label">Email <span class="text-secondary">(optional)</span></label>
                            <input type="email" id="email" name="email" class="form-control"
                                   value="{{ old('email') }}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">Money and room</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-sm-6">
                            <label for="monthly_fee" class="form-label">A month (IQD)</label>
                            <input type="number" min="0" step="1000" id="monthly_fee" name="monthly_fee" required
                                   class="form-control @error('monthly_fee') is-invalid @enderror"
                                   value="{{ old('monthly_fee', $defaultFee) }}">
                            @error('monthly_fee')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="storage_limit_mb" class="form-label">Storage limit (MB)</label>
                            <input type="number" min="64" step="64" id="storage_limit_mb" name="storage_limit_mb"
                                   class="form-control @error('storage_limit_mb') is-invalid @enderror"
                                   value="{{ old('storage_limit_mb', $defaultLimit) }}" placeholder="no limit">
                            @error('storage_limit_mb')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Empty means no ceiling at all.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">How they start</div>
                <div class="card-body">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="start" value="trial" id="start-trial"
                               @checked(old('start', 'trial') === 'trial')>
                        <label class="form-check-label" for="start-trial">
                            <strong>On a free trial</strong>
                            <span class="d-block small text-secondary">
                                Full function, no banner, nothing signed. The shop runs unlicensed, and the
                                panel chases the end date. This is the only thing the panel may issue without
                                a paste, because a trial is a standing, not a licence.
                            </span>
                        </label>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="radio" name="start" value="licence" id="start-licence"
                               @checked(old('start') === 'licence')>
                        <label class="form-check-label" for="start-licence">
                            <strong>With a licence you have already signed</strong>
                            <span class="d-block small text-secondary">
                                Run <code>licence:issue</code> on your own machine first, with
                                <code>--host=</code> set to the domain above.
                            </span>
                        </label>
                    </div>

                    <div id="licence-box" class="d-none">
                        <label for="licence" class="form-label small">The signed licence</label>
                        <textarea id="licence" name="licence" rows="4"
                                  class="form-control font-monospace @error('licence') is-invalid @enderror"
                                  spellcheck="false">{{ old('licence') }}</textarea>
                        @error('licence')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            Checked against the public key before it is written. The shop is made either way —
                            a licence that will not verify is a reason to paste a better one, not to throw the
                            shop away.
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">Anything worth remembering</div>
                <div class="card-body">
                    <textarea name="notes" rows="2" class="form-control" placeholder="Optional">{{ old('notes') }}</textarea>
                </div>
            </div>

            <div class="card border-danger">
                <div class="card-body">
                    <p class="mb-2">
                        This makes a database, a database user, two folders and a set of tables. If any step
                        fails, everything it made is taken back.
                    </p>
                    <label for="confirm-short" class="form-label small">
                        Type the short name to create the shop
                    </label>
                    <input type="text" class="form-control form-control-sm mb-2" id="confirm-short"
                           autocomplete="off" autocapitalize="off" spellcheck="false" style="max-width: 16rem">
                    <button type="submit" class="btn btn-danger" id="create-go" disabled>
                        Create this shop
                    </button>
                    <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </form>
    </div>

    <div class="col-12 col-xl-5">
        <div class="card">
            <div class="card-header">What this does, in order</div>
            <ol class="list-group list-group-flush list-group-numbered small">
                <li class="list-group-item">Makes the database and a user for it.</li>
                <li class="list-group-item">
                    Runs <code>shop:provision</code>: the folder, an <code>.env</code> with its own fresh
                    <code>APP_KEY</code>, its storage, its own <code>artisan</code>, and the public folder
                    the domain points at.
                </li>
                <li class="list-group-item">
                    Runs the shop's own <code>migrate</code> and <code>db:seed</code> — the tables, the
                    permissions, the settings, the counters, the Cash Customer, and one administrator.
                </li>
                <li class="list-group-item">Starts the trial, or delivers the licence you pasted.</li>
                <li class="list-group-item">Records the customer, and logs all of it.</li>
            </ol>
            <div class="card-footer small text-secondary">
                You still have to point the domain at the public folder in cPanel. The panel cannot do that
                part — Section 4: cPanel ignores a document root outside <code>public_html</code>.
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // The short name is three things at once, so show it becoming them.
    const short = document.getElementById('short_name');
    const echoes = document.querySelectorAll('[data-short-echo]');
    const go = document.getElementById('create-go');
    const confirm = document.getElementById('confirm-short');

    const tidy = (value) => value.trim().toLowerCase();

    const sync = () => {
        const value = tidy(short.value) || 'hawler';
        echoes.forEach((el) => (el.textContent = value));
        go.disabled = short.value === '' || tidy(confirm.value) !== tidy(short.value);
        confirm.classList.toggle('is-valid', ! go.disabled && confirm.value !== '');
        confirm.classList.toggle('is-invalid', go.disabled && confirm.value !== '');
    };

    short.addEventListener('input', sync);
    confirm.addEventListener('input', sync);

    // The licence box is only meaningful for one of the two choices.
    const box = document.getElementById('licence-box');
    document.querySelectorAll('input[name=start]').forEach((radio) => {
        radio.addEventListener('change', () => box.classList.toggle('d-none', radio.value !== 'licence'));
    });
    box.classList.toggle('d-none', document.getElementById('start-licence').checked === false);

    sync();
</script>
@endpush
@endsection
