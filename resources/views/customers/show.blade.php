@extends('layouts.app')

@section('title', $customer->name)
@section('heading', $customer->name)
@section('subheading', $customer->host)

@section('actions')
    <a href="{{ route('customers.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>All customers
    </a>
@endsection

@section('content')
@php
    $latest = $customer->latestHealthCheck;

    // State from the newest check; every figure from the newest one that could
    // actually read the shop. Section 5 keeps snapshots so a failed check does
    // not wipe the last good reading — this is the screen honouring that.
    $check = $customer->lastGoodHealthCheck;
    $stale = $check && $latest && ! $latest->reachable;
    $licence = $customer->currentLicence;
@endphp

{{--
    The administrator's password, shown once and never again.

    Nothing stores it: it is a hash in the shop's own users table by the time
    this renders, and it is deliberately not in `actions` — a log that carries a
    password hands over every shop it describes.
--}}
@if (session('made'))
    @php
        $made = session('made');
    @endphp
    <div class="alert alert-success">
        <h2 class="h6 mb-2"><i class="bi bi-check-circle me-1"></i>The shop is ready. Write this down now.</h2>
        <p class="small mb-2">
            This is the only time this password is shown. Nothing here has kept a copy — it is already
            just a hash inside the shop's own database.
        </p>
        <dl class="row mb-2 small">
            <dt class="col-4 col-sm-3 fw-normal">Address</dt>
            <dd class="col-8 col-sm-9"><code>https://{{ $made['host'] }}</code></dd>
            <dt class="col-4 col-sm-3 fw-normal">Signs in as</dt>
            <dd class="col-8 col-sm-9"><code>{{ $made['email'] }}</code></dd>
            <dt class="col-4 col-sm-3 fw-normal">Password</dt>
            <dd class="col-8 col-sm-9"><code class="user-select-all fs-6">{{ $made['password'] }}</code></dd>
        </dl>
        <p class="small mb-0 text-body-secondary">
            Point the domain at <code>{{ $customer->public_path }}</code> in cPanel if you have not already.
        </p>
    </div>
@endif

@if ($latest && ! $latest->reachable)
    <div class="alert alert-danger">
        <div class="fw-semibold">
            <i class="bi bi-exclamation-octagon me-1"></i>The last check could not read this shop.
        </div>
        <div class="small mt-1">
            Tried {{ $latest->checked_at->diffForHumans() }}.
            @if ($check)
                Every figure below is from {{ $check->checked_at->diffForHumans() }}, the last
                reading that worked.
            @else
                This shop has never been read, so there are no figures below at all.
            @endif
        </div>
        <pre class="small mb-0 mt-2 text-body-secondary" style="white-space: pre-wrap">{{ $latest->error }}</pre>
    </div>
@endif

<div class="row g-3">
    <div class="col-12 col-lg-6">
        {{-- The licence, and every licence before it. A renewal is a new row,
             never an edit — Section 5 — so this is the whole record. --}}
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="bi bi-key me-2"></i>Licence</span>
                <x-status-badge :status="$customer->status" />
            </div>
            <div class="card-body">
                <div class="d-flex align-items-baseline gap-2 mb-2">
                    <x-licence-state :licence="$licence" />
                    @if ($licence)
                        <code class="small">{{ $licence->licence_id }}</code>
                    @endif
                </div>

                @if ($licence === null)
                    <p class="text-secondary mb-0">
                        No licence has been delivered to this shop. Until one is, the shop
                        is read-only.
                    </p>
                @else
                    <dl class="row mb-0 small">
                        <dt class="col-5 fw-normal text-secondary">Issued</dt>
                        <dd class="col-7">{{ $licence->issued_on->toDateString() }}</dd>

                        <dt class="col-5 fw-normal text-secondary">Runs until</dt>
                        <dd class="col-7">{{ $licence->expires_on?->toDateString() ?? 'no end date' }}</dd>

                        <dt class="col-5 fw-normal text-secondary">Bound to</dt>
                        <dd class="col-7"><code>{{ $licence->host ?? 'anywhere' }}</code></dd>

                        <dt class="col-5 fw-normal text-secondary">Delivered</dt>
                        <dd class="col-7">{{ $licence->delivered_at?->toDayDateTimeString() ?? '—' }}</dd>

                        @if ($licence->issuedBy)
                            <dt class="col-5 fw-normal text-secondary">Issued by</dt>
                            <dd class="col-7">{{ $licence->issuedBy->name }}</dd>
                        @endif
                    </dl>

                    {{-- The cross-check Section 8 exists to make: what the shop
                         itself says, beside what the panel believes. --}}
                    @php
                        /*
                         * Only a reading taken AFTER the licence was delivered
                         * can disagree with it.
                         *
                         * The hourly check runs on its own schedule, so right
                         * after a renewal the newest reading is usually older
                         * than the licence — and comparing the two then reports
                         * "the shop says unlicensed" about a shop that was
                         * asked, answered `valid`, and has been fine since. A
                         * false alarm on this screen is worse than no alarm:
                         * the whole point of it is that a real disagreement
                         * gets noticed.
                         */
                        $comparable = $check?->licence_state
                            && $customer->status !== 'suspended'
                            && (
                                $licence?->delivered_at === null
                                || $check->checked_at->gte($licence->delivered_at)
                            );
                    @endphp

                    @if ($comparable)
                        @php
                            $agrees = match ($check->licence_state) {
                                'valid', 'expiring' => $licence->daysLeft() === null || $licence->daysLeft() >= 0,
                                'expired', 'grace' => $licence->daysLeft() !== null && $licence->daysLeft() < 0,
                                default => false,
                            };
                        @endphp
                        <div class="alert {{ $agrees ? 'alert-light' : 'alert-warning' }} mt-3 mb-0 py-2 small">
                            <i class="bi {{ $agrees ? 'bi-check2' : 'bi-exclamation-triangle' }} me-1"></i>
                            The shop itself reports <strong>{{ $check->licence_state }}</strong>.
                            @unless ($agrees)
                                That does not match what the panel believes — the shop may
                                not have the licence this page is describing.
                            @endunless
                        </div>
                    @endif
                @endif
            </div>

            @if ($customer->licences->isNotEmpty())
                <div class="card-footer p-0">
                    <details>
                        <summary class="px-3 py-2 small text-secondary" style="cursor: pointer">
                            Every licence ever issued ({{ $customer->licences->count() }})
                        </summary>
                        <div class="table-responsive border-top">
                            <table class="table table-sm mb-0 small">
                                <thead>
                                    <tr>
                                        <th scope="col">Reference</th>
                                        <th scope="col">Issued</th>
                                        <th scope="col">Until</th>
                                        <th scope="col">State</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($customer->licences as $past)
                                        <tr @class(['opacity-50' => $past->isRevoked() || ! $past->wasDelivered()])>
                                            <td><code>{{ $past->licence_id }}</code></td>
                                            <td>{{ $past->issued_on->toDateString() }}</td>
                                            <td>{{ $past->expires_on?->toDateString() ?? '—' }}</td>
                                            <td>
                                                @if ($past->isRevoked())
                                                    <span class="badge text-bg-secondary"
                                                          title="{{ $past->revoked_reason }}">revoked</span>
                                                @elseif (! $past->wasDelivered())
                                                    <span class="badge text-bg-warning"
                                                          title="Issued, but never confirmed as reaching the shop">never delivered</span>
                                                @elseif ($licence && $past->is($licence))
                                                    <span class="badge text-bg-success">running now</span>
                                                @else
                                                    <span class="badge text-bg-light">replaced</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                </div>
            @endif
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-hdd me-2"></i>Storage</div>
            <div class="card-body">
                <x-storage-bar :check="$check" :detailed="true" />

                @if ($check?->reachable && ! $check->storage_limit_mb)
                    <p class="small text-secondary mt-3 mb-0">
                        No limit is set in this shop's <code>.env</code>, so nothing stops it
                        filling the account's disk.
                    </p>
                @endif
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        {{-- "Whether they are actually using it" — Section 9. --}}
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-people me-2"></i>Are they using it?</div>
            <div class="card-body">
                <div class="row g-3 text-center">
                    @foreach ([
                        ['Users', $check?->users_count],
                        ['Products', $check?->products_count],
                        ['Sales', $check?->sales_count],
                    ] as [$label, $value])
                        <div class="col-4">
                            <div class="fs-4 fw-semibold">
                                {{ $value === null ? '—' : number_format($value) }}
                            </div>
                            <div class="small text-secondary">{{ $label }}</div>
                        </div>
                    @endforeach
                </div>

                <hr>

                <div class="d-flex justify-content-between align-items-baseline">
                    <span class="text-secondary small">Last thing that happened</span>
                    <x-last-used :check="$check" />
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-layers me-2"></i>Code and data</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-6 fw-normal text-secondary">Schema</dt>
                    <dd class="col-6"><x-schema-state :check="$check" /></dd>

                    <dt class="col-6 fw-normal text-secondary">Migrations run</dt>
                    <dd class="col-6">
                        {{ $check?->migrations_run === null ? 'unknown' : $check->migrations_run.' of '.$check->migrations_total }}
                    </dd>

                    <dt class="col-6 fw-normal text-secondary">Data check</dt>
                    <dd class="col-6">
                        @php($passed = $check?->dataCheckPassed())
                        @if ($passed === null)
                            <span class="text-secondary">unknown</span>
                        @elseif ($passed)
                            <span class="text-success">
                                <i class="bi bi-check-circle me-1"></i>{{ $check->data_check_total }} agree
                            </span>
                        @else
                            <span class="text-danger fw-semibold">
                                <i class="bi bi-exclamation-triangle me-1"></i>{{ $check->data_check_total - $check->data_check_passed }} disagree
                            </span>
                        @endif
                    </dd>

                    <dt class="col-6 fw-normal text-secondary">Read</dt>
                    <dd class="col-6">
                        {{ $check?->checked_at?->diffForHumans() ?? 'never' }}
                        @if ($stale)
                            <span class="text-danger d-block">could not be read {{ $latest->checked_at->diffForHumans() }}</span>
                        @endif
                    </dd>
                </dl>

                {{-- Section 3 settled that every shop reads one shared folder, so
                     there is no per-shop code version to show: it would be the
                     same string on every row. What differs is whether a shop has
                     run that shared code's migrations, which is above. --}}
                <p class="small text-secondary mt-3 mb-0">
                    Every shop runs the one shared codebase, so there is no version of its
                    own — what can differ is whether it has run the migrations that come
                    with it.
                </p>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-archive me-2"></i>Where it lives</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    @foreach ([
                        'Shop folder' => $customer->shop_home,
                        'Public folder' => $customer->public_path,
                        'Database' => $customer->database_name,
                        'Database user' => $customer->database_user,
                    ] as $label => $value)
                        <dt class="col-4 fw-normal text-secondary">{{ $label }}</dt>
                        <dd class="col-8 text-break"><code>{{ $value ?? '—' }}</code></dd>
                    @endforeach
                </dl>
                <p class="small text-secondary mt-3 mb-0">
                    The panel keeps no copy of this shop's database password. It is read
                    from the shop's own <code>.env</code> at the moment of connecting.
                </p>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-cash-coin me-2"></i>Money</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-6 fw-normal text-secondary">A month</dt>
                    <dd class="col-6">{{ number_format($customer->monthly_fee) }} IQD</dd>

                    <dt class="col-6 fw-normal text-secondary">Paid up to</dt>
                    <dd class="col-6">
                        @if ($paidUpTo === null)
                            <span class="text-warning">nothing recorded</span>
                        @else
                            <span @class(['text-danger fw-semibold' => $paidUpTo->isPast()])>
                                {{ $paidUpTo->toDateString() }}
                            </span>
                        @endif
                    </dd>

                    <dt class="col-6 fw-normal text-secondary">Started</dt>
                    <dd class="col-6">{{ $customer->started_on?->toDateString() ?? '—' }}</dd>
                </dl>
                <a href="{{ route('subscriptions.show', $customer) }}" class="btn btn-sm btn-outline-secondary mt-3">
                    <i class="bi bi-cash-coin me-1"></i>Payments
                </a>
            </div>
        </div>
    </div>
</div>

@if ($recentChecks->count() > 1)
    <div class="card mt-3">
        <div class="card-header"><i class="bi bi-clock-history me-2"></i>The last {{ $recentChecks->count() }} checks</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Read</th>
                        <th scope="col">Storage</th>
                        <th scope="col">Last used</th>
                        <th scope="col">Data check</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentChecks as $past)
                        <tr>
                            <td class="text-nowrap" title="{{ $past->checked_at->toDayDateTimeString() }}">
                                {{ $past->checked_at->diffForHumans() }}
                            </td>
                            <td>
                                @if ($past->reachable)
                                    <span class="text-success"><i class="bi bi-check-circle"></i></span>
                                @else
                                    <span class="text-danger" title="{{ Str::limit($past->error, 200) }}">
                                        <i class="bi bi-x-lg"></i>
                                    </span>
                                @endif
                            </td>
                            <td>{{ \App\Support\Bytes::human($past->totalBytes()) }}</td>
                            <td>{{ $past->last_activity_at?->diffForHumans() ?? '—' }}</td>
                            <td>
                                {{ $past->data_check_total === null
                                    ? '—'
                                    : $past->data_check_passed.'/'.$past->data_check_total }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

{{--
    The danger zone — Section 9.

    Every one of Section 7's destructive actions is named here now, disabled,
    with the reason on the button. That is Section 7's own guard rail — "the
    reason shown on a disabled button rather than discovered after pressing
    it" — applied to a build that has not reached them yet, and it is the same
    treatment the sidebar gives the pages that do not exist. A section left out
    until step 6 would hide the shape of what this screen becomes.
--}}
@if ($customer->trashed())
    {{--
        A removed shop. The page stays at the same address because the licence
        history and the payments below it outlive the shop — Section 5 — and
        every control is gone because there is nothing left to control.
    --}}
    <div class="alert alert-secondary mt-3">
        <h2 class="h6 mb-1"><i class="bi bi-trash3 me-1"></i>This shop was removed
            {{ $customer->deleted_at?->diffForHumans() }}.</h2>
        <p class="small mb-1">
            Its folders, its DNS record and its database are gone. What you are reading is the record:
            what it was, every licence it ran on, and everything it paid.
        </p>

        {{--
            The dump taken on the way out, which is the only thing left that
            holds their data. It is the one download on this page that can
            never be taken again.
        --}}
        @foreach ($backups as $taken)
            @php($path = $taken->detail['backup'] ?? $taken->detail['path'] ?? null)
            @if ($path && $taken->action === 'shop.removed')
                <p class="small mb-0">
                    <a href="{{ route('customers.backup.download', [$customer, $taken]) }}">
                        <i class="bi bi-download me-1"></i>Download the backup it was dumped to
                    </a>
                    — <code>{{ basename($path) }}</code>
                </p>
            @endif
        @endforeach
    </div>

    @if ($leftBehind !== [])
        {{-- The half that did not finish, still worth doing. --}}
        <div class="alert alert-warning">
            <h2 class="h6 mb-1"><i class="bi bi-exclamation-triangle me-1"></i>These were left behind</h2>
            <ul class="small mb-0 ps-3">
                @foreach ($leftBehind as $thing)
                    <li>{{ $thing }}</li>
                @endforeach
            </ul>
        </div>
    @endif
@else
<div class="card border-danger mt-3">
    <div class="card-header bg-danger-subtle text-danger-emphasis">
        <i class="bi bi-exclamation-triangle me-2"></i>Danger zone
    </div>
    <ul class="list-group list-group-flush">
        <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>
                <span class="d-block">Renew the licence</span>
                <small class="text-secondary">
                    You run the signing command on your own machine and paste the result back.
                    Checked here before anything is written.
                </small>
            </span>
            <a href="{{ route('customers.renew', $customer) }}" class="btn btn-sm btn-outline-danger">
                Renew…
            </a>
        </li>

        {{-- Section 7: logged, from → to. --}}
        <li class="list-group-item">
            <form method="POST" action="{{ route('customers.storage', $customer) }}" data-guard-submit
                  class="d-flex flex-wrap justify-content-between align-items-end gap-2 m-0">
                @csrf
                <div>
                    <label for="storage_limit_mb" class="form-label mb-1">Change the storage limit</label>
                    <div class="input-group input-group-sm" style="max-width: 16rem">
                        <input type="number" min="64" max="1048576" step="64"
                               class="form-control @error('storage_limit_mb') is-invalid @enderror"
                               id="storage_limit_mb" name="storage_limit_mb"
                               value="{{ old('storage_limit_mb', $customer->storage_limit_mb) }}"
                               placeholder="no limit">
                        <span class="input-group-text">MB</span>
                    </div>
                    @error('storage_limit_mb')<div class="text-danger small">{{ $message }}</div>@enderror
                    <small class="text-secondary d-block mt-1">
                        Written into the shop's .env, where the shop reads it. Empty means no ceiling at
                        all — nothing then stops it filling the account's disk.
                    </small>
                </div>
                <button type="submit" class="btn btn-sm btn-outline-danger">Set the limit</button>
            </form>
        </li>

        {{-- Section 7: hold to confirm, typed shop name. --}}
        <li class="list-group-item d-flex flex-wrap justify-content-between align-items-start gap-2">
            <span>
                @if ($customer->status === 'suspended')
                    <span class="d-block">Let this shop trade again</span>
                    <small class="text-secondary">
                        Puts back the licence it already had — nothing new is signed. If that licence has
                        since run out, renew it instead.
                    </small>
                @else
                    <span class="d-block">Suspend this shop</span>
                    <small class="text-secondary">
                        Takes the licence out of its .env, which makes the shop read-only. They can still
                        read and print their own records — a shop locked out of its records never pays.
                    </small>
                @endif
            </span>

            @if ($customer->status === 'suspended')
                <x-danger-form
                    :action="route('customers.resume', $customer)"
                    label="Let them trade"
                    variant="success" />
            @else
                <x-danger-form
                    :action="route('customers.suspend', $customer)"
                    label="Suspend"
                    :confirm="$customer->host"
                    :confirmLabel="'Type '.$customer->host.' to suspend'">
                    <input type="text" name="why" class="form-control form-control-sm mb-2"
                           placeholder="Why, for the record (optional)" maxlength="255">
                </x-danger-form>
            @endif
        </li>

        {{--
            Section 3's other half. Updating the shared code once is the whole
            point of one codebase; every shop's database is behind until this
            is run for it, and until now there was nothing to press.
        --}}
        @php($behind = $check?->migrationsPending())
        <li class="list-group-item d-flex flex-wrap justify-content-between align-items-start gap-2">
            <span>
                <span class="d-block">Run this shop’s migrations</span>
                <small class="text-secondary">
                    Their own <code>migrate</code>, through their own artisan — the panel writes nothing to
                    their tables. A backup is taken first, and if it fails nothing runs.
                    @if ($behind === null)
                        The last check could not count them, so this may have nothing to do.
                    @elseif ($behind > 0)
                        <strong>{{ $behind }} {{ Str::plural('migration', $behind) }} pending</strong>
                        as of the last check.
                    @else
                        Up to date as of the last check.
                    @endif
                </small>
            </span>

            <x-danger-form
                :action="route('customers.migrate', $customer)"
                :label="$behind > 0 ? 'Run '.$behind.' '.Str::plural('migration', $behind) : 'Run them anyway'"
                variant="warning" />
        </li>

        <li class="list-group-item">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <span>
                    <span class="d-block">Run a backup, and download it</span>
                    <small class="text-secondary">
                        Their own <code>backup:run</code>, kept where their backups go. Downloading is the
                        only copy that leaves this server — one on the disk dies with the disk.
                    </small>
                </span>

                <form method="POST" action="{{ route('customers.backup', $customer) }}"
                      data-guard-submit class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Back up now</button>
                </form>
            </div>

            @if ($backups->isNotEmpty())
                <ul class="list-unstyled small mt-2 mb-0">
                    @foreach ($backups as $taken)
                        @php($path = $taken->detail['path'] ?? $taken->detail['backup'] ?? null)
                        @continue(! $path)
                        <li class="d-flex flex-wrap justify-content-between gap-2 border-top py-1">
                            <span class="text-secondary">
                                <code>{{ basename($path) }}</code>
                                — {{ $taken->created_at->diffForHumans() }}
                                @if ($taken->action === 'shop.migrated')
                                    <span class="badge text-bg-light">before migrating</span>
                                @elseif ($taken->action === 'shop.removed')
                                    <span class="badge text-bg-light">before removing</span>
                                @endif
                            </span>
                            <a href="{{ route('customers.backup.download', [$customer, $taken]) }}"
                               class="text-decoration-none">
                                <i class="bi bi-download me-1"></i>Download
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </li>

        {{--
            The one thing on this page that nothing can undo.

            The rule is in ShopRemover and the reason lives on the button, per
            Section 7: a trading shop cannot be removed at all, and the button
            says why rather than the press finding out.
        --}}
        <li class="list-group-item d-flex flex-wrap justify-content-between align-items-start gap-2">
            <span>
                <span class="d-block">Remove this shop</span>
                <small class="text-secondary">
                    Dumps their database and copies it to
                    <code>{{ $removedShopsGoTo }}</code> first — if that fails, nothing is touched.
                    Then the DNS record, the subdomain, both folders, and the database and its user.
                    This customer, every licence and every payment stay on record.
                    <strong>Nothing here can be undone.</strong>
                </small>
            </span>

            <x-danger-form
                :action="route('customers.remove', $customer)"
                method="DELETE"
                label="Remove it for ever"
                :disabled="(bool) $removalBlocked"
                :reason="$removalBlocked"
                :confirm="$customer->host"
                :confirmLabel="'Type '.$customer->host.' to remove it'">
                <input type="text" name="why" class="form-control form-control-sm mb-2"
                       placeholder="Why, for the record (optional)" maxlength="255">
            </x-danger-form>
        </li>
    </ul>
    <div class="card-footer small text-secondary">
        The panel may never write to this shop’s business tables or hold the private key — Section 7.
        It may drop this shop’s database, and only through Remove, and only after a dump it has
        checked landed somewhere that survives.
    </div>
</div>
@endif
@endsection
