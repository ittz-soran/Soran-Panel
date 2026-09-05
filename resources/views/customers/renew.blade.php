@extends('layouts.app')

@section('title', 'Renew '.$customer->name)
@section('heading', 'Renew '.$customer->name)
@section('subheading', $customer->host)

@section('actions')
    <a href="{{ route('customers.show', $customer) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to the shop
    </a>
@endsection

@section('content')
<div class="row g-3">
    <div class="col-12 col-xl-7">
        {{-- Step 1 — Section 6. The panel shows the command; it does not run it. --}}
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center gap-2">
                <span class="badge text-bg-secondary">1</span>
                Run this on your own computer
            </div>
            <div class="card-body">
                <p class="text-secondary small">
                    Not here. The private key never reaches this server, so a break-in on
                    soranstore.com can never forge a licence for anybody — which also means
                    this panel cannot sign one, on purpose.
                </p>

                <div class="position-relative">
                    <pre class="bg-body-tertiary border rounded p-3 mb-0 small text-break"
                         style="white-space: pre-wrap" id="issue-command">{{ $command }}</pre>
                    <button type="button" class="btn btn-sm btn-outline-secondary position-absolute top-0 end-0 m-2"
                            data-copy-from="issue-command">Copy</button>
                </div>

                <div class="form-text mt-2">
                    <code>--months=1</code> for a month. Use <code>--until=YYYY-MM-DD</code> for an exact
                    date, or <code>--forever</code> for a copy sold outright.
                </div>
            </div>
        </div>

        {{-- Step 1b — the same signing, done here, with the key never leaving
             this device. The browser reads the file, computes the RSA sum and
             hands over only the finished licence; nothing about the key is
             sent anywhere. It fills in step 2 below, so what happens after is
             exactly what happens to a paste — verified on the server before a
             single byte is written to the shop. --}}
        <div class="card mb-3 border-warning" id="sign-here" hidden>
            <div class="card-header d-flex align-items-center gap-2">
                <span class="badge text-bg-warning">1b</span>
                Or sign it here, on this device
            </div>
            <div class="card-body">
                <p class="text-secondary small">
                    Your key is read by this page and never sent — not to this server, not anywhere.
                    The panel says what the licence claims; your device does the signing.
                </p>

                <div class="row g-3">
                    <div class="col-12 col-sm-7">
                        <label for="key-file" class="form-label small">Your private key file</label>
                        <input type="file" class="form-control form-control-sm" id="key-file"
                               accept=".pem,.key,application/x-pem-file,text/plain">
                        <div class="form-text">The <code>.pem</code> that <code>licence:keys</code> made.</div>
                    </div>

                    <div class="col-12 col-sm-5">
                        <label for="sign-months" class="form-label small">How long</label>
                        <select id="sign-months" class="form-select form-select-sm">
                            <option value="1">1 month</option>
                            <option value="3">3 months</option>
                            <option value="6">6 months</option>
                            <option value="12" selected>12 months</option>
                            <option value="forever">No end date</option>
                        </select>
                    </div>
                </div>

                <button type="button" class="btn btn-sm btn-warning mt-3" id="sign-go">
                    <i class="bi bi-key me-1"></i>Sign it
                </button>

                <div class="small mt-2" id="sign-said" role="status" aria-live="polite"></div>
            </div>
        </div>

        <form method="POST" action="{{ route('customers.renew.store', $customer) }}" data-guard-submit>
            @csrf

            {{-- Step 2. --}}
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center gap-2">
                    <span class="badge text-bg-secondary">2</span>
                    Paste what it printed
                </div>
                <div class="card-body">
                    <label for="licence" class="form-label visually-hidden">The signed licence</label>
                    <textarea id="licence" name="licence" rows="5" required autofocus
                              class="form-control font-monospace @error('licence') is-invalid @enderror"
                              placeholder="eyJpZCI6IlBMQkYtOVFEMSIsInNob3AiOi… . K55R3FXRf87aDqZ…"
                              spellcheck="false">{{ old('licence') }}</textarea>
                    @error('licence')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">
                        Just the licence itself — everything after <code>LICENCE_KEY=</code>. If your
                        terminal wrapped it over several lines that is fine.
                    </div>
                </div>
            </div>

            {{-- Step 8, if asked. --}}
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center gap-2">
                    <span class="badge text-bg-secondary">3</span>
                    Record the payment, if there was one
                </div>
                <div class="card-body">
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="record_payment"
                               name="record_payment" value="1" @checked(old('record_payment'))>
                        <label class="form-check-label" for="record_payment">
                            They paid for this
                        </label>
                    </div>

                    <div class="row g-2" id="payment-fields">
                        <div class="col-12 col-sm-4">
                            <label for="amount" class="form-label small">Amount (IQD)</label>
                            <input type="number" min="1" step="1" id="amount" name="amount"
                                   class="form-control @error('amount') is-invalid @enderror"
                                   value="{{ old('amount', $customer->monthly_fee) }}">
                            @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6 col-sm-4">
                            <label for="covers_from" class="form-label small">Covers from</label>
                            <input type="date" id="covers_from" name="covers_from"
                                   class="form-control @error('covers_from') is-invalid @enderror"
                                   value="{{ old('covers_from', ($paidUpTo?->copy()->addDay() ?? now())->toDateString()) }}">
                            @error('covers_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6 col-sm-4">
                            <label for="covers_to" class="form-label small">Covers to</label>
                            <input type="date" id="covers_to" name="covers_to"
                                   class="form-control @error('covers_to') is-invalid @enderror"
                                   value="{{ old('covers_to', ($paidUpTo?->copy()->addMonth() ?? now()->addMonth())->toDateString()) }}">
                            @error('covers_to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label for="method" class="form-label small">How they paid</label>
                            <input type="text" id="method" name="method" class="form-control"
                                   value="{{ old('method', 'cash') }}" list="methods">
                            <datalist id="methods">
                                <option value="cash"><option value="FIB"><option value="FastPay"><option value="transfer">
                            </datalist>
                        </div>
                    </div>

                    @if ($paidUpTo)
                        <div class="form-text mt-2">
                            Paid up to {{ $paidUpTo->toDateString() }}, so the next period starts the day after.
                        </div>
                    @endif
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Verify and deliver</button>
            <a href="{{ route('customers.show', $customer) }}" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>

    <div class="col-12 col-xl-5">
        <div class="card mb-3">
            <div class="card-header">Where this shop stands now</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 fw-normal text-secondary">Licence</dt>
                    <dd class="col-7"><x-licence-state :licence="$customer->currentLicence" /></dd>

                    <dt class="col-5 fw-normal text-secondary">Runs until</dt>
                    <dd class="col-7">{{ $customer->currentLicence?->expires_on?->toDateString() ?? '—' }}</dd>

                    <dt class="col-5 fw-normal text-secondary">Domain</dt>
                    <dd class="col-7"><code>{{ $customer->host }}</code></dd>

                    <dt class="col-5 fw-normal text-secondary">Paid up to</dt>
                    <dd class="col-7">{{ $paidUpTo?->toDateString() ?? 'nothing recorded' }}</dd>

                    <dt class="col-5 fw-normal text-secondary">Standing</dt>
                    <dd class="col-7"><x-status-badge :status="$customer->status" /></dd>
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-header">What happens when you press it</div>
            <ol class="list-group list-group-flush list-group-numbered small">
                <li class="list-group-item">
                    The licence is checked against the public key <strong>here</strong>. If it is not
                    signed by your key, is for another domain, or has already run out, it is refused
                    and nothing is written.
                </li>
                <li class="list-group-item">It is saved as a new licence, marked not yet delivered.</li>
                <li class="list-group-item">
                    <code>LICENCE_KEY</code> is written into the shop's <code>.env</code>. The old file
                    is kept as <code>.env.bak</code>.
                </li>
                <li class="list-group-item">
                    A blank <code>LICENCE_PUBLIC_KEY</code> — what a trial leaves behind — is removed,
                    or the licence would never be checked at all.
                </li>
                <li class="list-group-item">The shop's cached config is cleared, so it takes effect at once.</li>
                <li class="list-group-item">
                    <strong>The shop is asked what it now thinks</strong>, and only if it answers that
                    the licence is working is it recorded as delivered.
                </li>
            </ol>
        </div>
    </div>
</div>

@push('scripts')
<script>
    document.querySelectorAll('[data-copy-from]').forEach((button) => {
        button.addEventListener('click', async () => {
            const source = document.getElementById(button.dataset.copyFrom);
            try {
                await navigator.clipboard.writeText(source.textContent.trim());
                button.textContent = 'Copied';
                setTimeout(() => (button.textContent = 'Copy'), 1500);
            } catch {
                // A page served over plain HTTP has no clipboard. Select it
                // instead, so the keyboard still works.
                const range = document.createRange();
                range.selectNodeContents(source);
                window.getSelection().removeAllRanges();
                window.getSelection().addRange(range);
                button.textContent = 'Press Ctrl+C';
            }
        });
    });

    /*
     * Signing here, with the key never leaving this device.
     *
     * The one rule of Section 6 is that the private key never reaches the
     * server. That rules out uploading it and letting PHP sign — an attacker
     * who had the panel could simply wait and take the key the next time it
     * was used, and then forge licences for every shop ever sold. It does NOT
     * rule out signing on the machine that already has the key, which is what
     * this does: the file is read by this page, the RSA sum is done by the
     * browser, and only the finished licence is posted.
     *
     * The panel still builds what the licence says. JavaScript's only job is
     * the signature — dates and identifiers stay in PHP, where they are worked
     * out by the same code `licence:issue` uses. Doing that arithmetic here
     * too would be a second version of the rule, free to drift from the first.
     *
     * Hidden unless the browser can actually do it. crypto.subtle exists only
     * in a secure context, so a panel served over plain HTTP shows step 1 and
     * nothing else, rather than a button that fails when pressed.
     */
    const canSign = window.isSecureContext && window.crypto && window.crypto.subtle;
    const signingCard = document.getElementById('sign-here');

    if (canSign) {
        signingCard.hidden = false;

        const said = document.getElementById('sign-said');
        const say = (text, kind) => {
            said.textContent = text;
            said.className = 'small mt-2 text-' + (kind || 'secondary');
        };

        const base64url = (bytes) => btoa(String.fromCharCode(...new Uint8Array(bytes)))
            .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

        const unBase64url = (text) => {
            const padded = text.replace(/-/g, '+').replace(/_/g, '/');
            const raw = atob(padded + '='.repeat((4 - padded.length % 4) % 4));

            return Uint8Array.from(raw, (c) => c.charCodeAt(0));
        };

        // A PEM is base64 with a header and footer. Web Crypto wants the DER
        // inside it, and only in PKCS#8 — the shape `licence:keys` writes.
        const derFrom = (pem) => {
            if (/BEGIN RSA PRIVATE KEY/.test(pem)) {
                throw new Error(
                    'That key is in the older PKCS#1 format, which browsers cannot read. Convert it once '
                    + 'with: openssl pkcs8 -topk8 -nocrypt -in old.pem -out new.pem'
                );
            }

            const body = /-----BEGIN [^-]+-----([\s\S]*?)-----END/.exec(pem);

            if (! body) {
                throw new Error('That file does not look like a key. It should start with -----BEGIN.');
            }

            const raw = atob(body[1].replace(/\s+/g, ''));

            return Uint8Array.from(raw, (c) => c.charCodeAt(0));
        };

        document.getElementById('sign-go').addEventListener('click', async () => {
            const file = document.getElementById('key-file').files[0];

            if (! file) {
                return say('Choose your private key file first.', 'danger');
            }

            const howLong = document.getElementById('sign-months').value;

            try {
                say('Signing…');

                // What the licence claims, from the panel — never invented here.
                const asked = await fetch(@json(route('customers.renew.payload', $customer)), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify(howLong === 'forever'
                        ? { forever: true }
                        : { months: Number(howLong) }),
                });

                if (! asked.ok) {
                    throw new Error('The panel would not say what this licence should contain.');
                }

                const { body, expires, id } = await asked.json();

                const key = await crypto.subtle.importKey(
                    'pkcs8',
                    derFrom(await file.text()),
                    { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' },
                    false,
                    ['sign'],
                );

                // Signed over exactly the bytes the shop will verify over —
                // the ones inside the token, not a re-encoding of them.
                const signature = await crypto.subtle.sign(
                    'RSASSA-PKCS1-v1_5', key, unBase64url(body),
                );

                document.getElementById('licence').value = body + '.' + base64url(signature);

                say('Signed ' + id + (expires ? ', good until ' + expires : ', with no end date')
                    + '. It is in the box below — check it and deliver it.', 'success');
            } catch (e) {
                say(e.message || 'That key could not be used to sign.', 'danger');
            }
        });
    }

    // The payment fields are only meaningful when the box is ticked.
    const tick = document.getElementById('record_payment');
    const fields = document.getElementById('payment-fields');
    const sync = () => fields.querySelectorAll('input').forEach((i) => (i.disabled = ! tick.checked));
    tick.addEventListener('change', sync);
    sync();
</script>
@endpush
@endsection
