@extends('layouts.app')

@section('title', 'Take on an existing shop')
@section('subheading', 'A shop whose database is already there: build the folder around it, and leave the data alone.')

@section('content')
<div class="row g-3">
    <div class="col-12 col-xl-7">
        <form method="POST" action="{{ route('customers.take-on.store') }}" data-guard-submit>
            @csrf

            <div class="card mb-3">
                <div class="card-header">The shop</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Shop name</label>
                        <input type="text" id="name" name="name" required autofocus
                               class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name') }}" placeholder="Halabja Phone">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="short_name" class="form-label">Short name</label>
                        <input type="text" id="short_name" name="short_name" required
                               class="form-control font-monospace @error('short_name') is-invalid @enderror"
                               value="{{ old('short_name') }}" placeholder="halabja"
                               pattern="[a-z][a-z0-9]*" maxlength="20"
                               autocapitalize="off" autocomplete="off" spellcheck="false">
                        @error('short_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            The folder this builds, and nothing more. Their database keeps the name it already
                            has — you give that below.
                        </div>
                    </div>

                    <div class="mb-0">
                        <label for="host" class="form-label">Domain</label>
                        <input type="text" id="host" name="host" required
                               class="form-control font-monospace @error('host') is-invalid @enderror"
                               value="{{ old('host') }}" placeholder="halabja.soranstore.com"
                               autocapitalize="off" autocomplete="off" spellcheck="false">
                        @error('host')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">The one they already use, if they have one. The licence binds to it.</div>
                    </div>
                </div>
            </div>

            <div class="card mb-3 border-warning">
                <div class="card-header">Their database</div>
                <div class="card-body">
                    <p class="small text-secondary">
                        Copy these out of cPanel exactly, prefix and all. Nothing is created — this database
                        already exists, and it is read before anything else happens.
                    </p>

                    <div class="row g-3">
                        <div class="col-12 col-sm-6">
                            <label for="database" class="form-label">Database</label>
                            <input type="text" id="database" name="database" required
                                   class="form-control font-monospace @error('database') is-invalid @enderror"
                                   value="{{ old('database') }}" placeholder="soran_halabja"
                                   autocapitalize="off" autocomplete="off" spellcheck="false">
                            @error('database')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="database_user" class="form-label">Database user</label>
                            <input type="text" id="database_user" name="database_user" required
                                   class="form-control font-monospace @error('database_user') is-invalid @enderror"
                                   value="{{ old('database_user') }}" placeholder="soran_halabja"
                                   autocapitalize="off" autocomplete="off" spellcheck="false">
                            @error('database_user')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label for="database_password" class="form-label">Its password</label>
                            <input type="password" id="database_password" name="database_password" required
                                   class="form-control font-monospace @error('database_password') is-invalid @enderror"
                                   value="{{ old('database_password') }}"
                                   autocomplete="off" spellcheck="false">
                            @error('database_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">
                                If you have lost it, set a new one in cPanel first — that changes the password,
                                not the data.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">Their old <code>APP_KEY</code> <span class="text-secondary">— usually blank</span></div>
                <div class="card-body">
                    <label for="app_key" class="form-label small">From the old install's <code>.env</code></label>
                    <input type="text" id="app_key" name="app_key"
                           class="form-control font-monospace @error('app_key') is-invalid @enderror"
                           value="{{ old('app_key') }}" placeholder="base64:…"
                           autocapitalize="off" autocomplete="off" spellcheck="false">
                    @error('app_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">
                        Only needed when their staff use an authenticator app. The shop system encrypts those
                        secrets with <code>APP_KEY</code>, and this builds the folder with a fresh one — which
                        would leave them as ciphertext nothing can read. Leave it empty: if it turns out to
                        matter, this screen refuses and asks for it, without having changed anything.
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
                        </div>
                    </div>
                    <div class="form-text mt-2">
                        The panel will not date them as starting today — they were trading before it existed,
                        and Subscriptions counts unpaid months from a start date. Record the first month they
                        owe as a payment instead, once they are on.
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">Where they stand</div>
                <div class="card-body">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="standing" value="active" id="standing-active"
                               @checked(old('standing', 'active') === 'active')>
                        <label class="form-check-label" for="standing-active">
                            <strong>A paying customer, licence to follow</strong>
                            <span class="d-block small text-secondary">
                                They go on as active and unlicensed. Sign their licence and use Renew when
                                you are ready — that is the same door every other renewal goes through.
                            </span>
                        </label>
                    </div>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="standing" value="licence" id="standing-licence"
                               @checked(old('standing') === 'licence')>
                        <label class="form-check-label" for="standing-licence">
                            <strong>With a licence you have already signed</strong>
                            <span class="d-block small text-secondary">
                                Run <code>licence:issue</code> on your own machine, with <code>--host=</code>
                                set to the domain above.
                            </span>
                        </label>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="radio" name="standing" value="trial" id="standing-trial"
                               @checked(old('standing') === 'trial')>
                        <label class="form-check-label" for="standing-trial">
                            <strong>On a trial while they decide</strong>
                        </label>
                    </div>

                    <div id="licence-box" class="d-none">
                        <label for="licence" class="form-label small">The signed licence</label>
                        <textarea id="licence" name="licence" rows="4"
                                  class="form-control font-monospace @error('licence') is-invalid @enderror"
                                  spellcheck="false">{{ old('licence') }}</textarea>
                        @error('licence')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="backup" value="1" id="backup"
                               @checked(old('backup', '1'))>
                        <label class="form-check-label" for="backup">
                            <strong>Back their database up first</strong>
                            <span class="d-block small text-secondary">
                                Through the shop's own <code>backup:run</code>, so a restore later finds the
                                shape it expects. Untick this only if you took one yourself minutes ago.
                            </span>
                        </label>
                    </div>

                    <p class="mb-2 small">
                        This runs <code>migrate</code> on a customer's real records. It never seeds, and if
                        anything fails it removes the folder it made and leaves their database exactly as it
                        found it.
                    </p>

                    <label for="confirm-short" class="form-label small">
                        Type the short name to take this shop on
                    </label>
                    <input type="text" class="form-control form-control-sm mb-2" id="confirm-short"
                           autocomplete="off" autocapitalize="off" spellcheck="false" style="max-width: 16rem">
                    <button type="submit" class="btn btn-danger" id="take-on-go" disabled>
                        Take this shop on
                    </button>
                    <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </form>
    </div>

    <div class="col-12 col-xl-5">
        <div class="card mb-3">
            <div class="card-header">What this does, in order</div>
            <ol class="list-group list-group-flush list-group-numbered small">
                <li class="list-group-item">
                    Reads their database, through a connection that cannot write to it, and counts what is
                    there. If it is not a shop's database, or has nobody in it, nothing else happens.
                </li>
                <li class="list-group-item">
                    Runs <code>shop:provision</code>: the folder, an <code>.env</code> pointed at their
                    database, its storage, its own <code>artisan</code>, and the public folder.
                </li>
                <li class="list-group-item">Backs their database up, through their own tooling.</li>
                <li class="list-group-item">
                    Runs <code>migrate</code> to bring an old schema up to the shared codebase —
                    <strong>never <code>db:seed</code></strong>.
                </li>
                <li class="list-group-item">Records the customer, and logs all of it.</li>
            </ol>
        </div>

        <div class="card border-warning">
            <div class="card-header">Why this screen is careful</div>
            <div class="card-body small">
                <p>
                    New customer makes a database and can take it back if a step fails. This one cannot:
                    the database is theirs, with years of trading in it, so <strong>a failure here removes
                    the folder and never the database</strong>.
                </p>
                <p class="mb-0">
                    It is what Halabja-phone needs. Their install folder was deleted and their database was
                    deliberately kept, because that is what a rebuilt install restores from.
                </p>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    const short = document.getElementById('short_name');
    const go = document.getElementById('take-on-go');
    const confirm = document.getElementById('confirm-short');

    const tidy = (value) => value.trim().toLowerCase();

    const sync = () => {
        go.disabled = short.value === '' || tidy(confirm.value) !== tidy(short.value);
        confirm.classList.toggle('is-valid', ! go.disabled && confirm.value !== '');
        confirm.classList.toggle('is-invalid', go.disabled && confirm.value !== '');
    };

    short.addEventListener('input', sync);
    confirm.addEventListener('input', sync);

    const box = document.getElementById('licence-box');
    document.querySelectorAll('input[name=standing]').forEach((radio) => {
        radio.addEventListener('change', () => box.classList.toggle('d-none', radio.value !== 'licence'));
    });
    box.classList.toggle('d-none', document.getElementById('standing-licence').checked === false);

    sync();
</script>
@endpush
@endsection
