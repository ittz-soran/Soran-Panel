@extends('layouts.app')

@section('title', 'Updates')
@section('subheading', 'What version this server is running, and what is waiting on GitHub.')

@section('actions')
    <a href="{{ route('updates', ['check' => 1]) }}" class="btn btn-sm btn-primary">
        <i class="bi bi-arrow-repeat me-1"></i>Check GitHub
    </a>
@endsection

@section('content')
@unless($asked)
    <div class="alert alert-light border d-flex gap-2 align-items-start">
        <i class="bi bi-info-circle mt-1"></i>
        <div class="small">
            This shows what is installed here. Press <strong>Check GitHub</strong> to ask whether anything
            newer is waiting — that part goes over the network, so it is not done every time the page opens.
        </div>
    </div>
@endunless

<div class="row g-3">
    @foreach($checkouts as $key => $it)
        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>
                        <i class="bi {{ $key === 'panel' ? 'bi-sliders' : 'bi-boxes' }} me-2"></i>{{ $it['name'] }}
                    </span>
                    @if($it['ok'] && $it['asked'])
                        @if($it['waiting'] === [])
                            <span class="badge text-bg-success">Up to date</span>
                        @else
                            <span class="badge text-bg-warning">
                                {{ trans_choice(':count update|:count updates', count($it['waiting'])) }}
                            </span>
                        @endif
                    @endif
                </div>

                <div class="card-body">
                    {{--
                        A branch that was merged and deleted is not a broken
                        checkout, and drawing it in red as one sends somebody to
                        the server looking for damage that is not there. The
                        code here is fine — it is following something that has
                        gone — so it is amber, it keeps its details, and it
                        carries the one button that fixes it.
                    --}}
                    @if($it['problem'] && $it['stranded'])
                        <div class="alert alert-warning small">
                            <i class="bi bi-exclamation-triangle me-1"></i>{{ $it['problem'] }}

                            @if($it['default_branch'] && $it['clean'])
                                <form method="POST" action="{{ route('updates.branch') }}"
                                      data-guard-submit class="mt-2 mb-0">
                                    @csrf
                                    <input type="hidden" name="checkout" value="{{ $key }}">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                        Move it onto <span class="font-monospace">{{ $it['default_branch'] }}</span>
                                    </button>
                                </form>
                                <div class="text-secondary mt-1">
                                    Refused if anything here is uncommitted, or if this branch holds a commit
                                    that never reached <span class="font-monospace">{{ $it['default_branch'] }}</span>.
                                </div>
                            @elseif(! $it['clean'])
                                <div class="mt-1">
                                    There are uncommitted changes here, so the panel will not move it. Deal
                                    with them on the server first.
                                </div>
                            @endif
                        </div>
                    @elseif($it['problem'])
                        <div class="alert alert-danger mb-0 small">{{ $it['problem'] }}</div>
                    @endif

                    @if(! $it['problem'] || $it['stranded'])
                        <dl class="row mb-0 small">
                            <dt class="col-4 fw-normal text-secondary">Branch</dt>
                            <dd class="col-8 font-monospace">{{ $it['branch'] }}</dd>

                            <dt class="col-4 fw-normal text-secondary">Running</dt>
                            <dd class="col-8">
                                <span class="font-monospace">{{ $it['commit'] }}</span>
                                <span class="d-block text-secondary">{{ $it['subject'] }}</span>
                                @if($it['when'])
                                    <span class="d-block text-secondary">
                                        {{ \Illuminate\Support\Carbon::parse($it['when'])->diffForHumans() }}
                                    </span>
                                @endif
                            </dd>

                            <dt class="col-4 fw-normal text-secondary">Folder</dt>
                            <dd class="col-8 font-monospace text-secondary text-break">{{ $it['path'] }}</dd>
                        </dl>

                        @unless($it['clean'])
                            {{-- Anything uncommitted here was done on the server by
                                 hand, and a pull over it is how that gets lost. --}}
                            <div class="alert alert-warning mt-3 mb-0 small">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                There are changes here that are not committed. Updating is refused until they
                                are dealt with, because a pull would write over them.

                                @if($it['uncommitted'] !== [])
                                    <ul class="list-unstyled mt-2 mb-0 font-monospace">
                                        @foreach(array_slice($it['uncommitted'], 0, 20) as $change)
                                            <li>
                                                <span class="text-body-secondary">{{ $change['status'] }}</span>
                                                &middot; {{ $change['path'] }}
                                            </li>
                                        @endforeach
                                    </ul>

                                    @if(count($it['uncommitted']) > 20)
                                        <div class="mt-1">
                                            &hellip; and {{ count($it['uncommitted']) - 20 }} more.
                                        </div>
                                    @endif

                                    {{-- The overwhelmingly common cause, and the one the panel
                                         cannot safely fix for you: build/ is committed in the shop
                                         system now, so a build uploaded or extracted on the server
                                         shows up here as changed tracked files. --}}
                                    <div class="mt-2">
                                        If these are all under <code>public/build</code>, they are the
                                        compiled assets: those are committed now, so the copy in git is
                                        the one to keep. <code>git checkout -- public/build</code> in
                                        that folder puts it back and lets the update run.
                                    </div>
                                @endif
                            </div>
                        @endunless

                        @if($it['waiting'] !== [])
                            <hr>
                            <div class="small fw-semibold mb-2">Waiting on GitHub</div>
                            <ul class="list-unstyled small mb-0 d-flex flex-column gap-2">
                                @foreach($it['waiting'] as $commit)
                                    <li class="d-flex gap-2">
                                        <span class="font-monospace text-secondary flex-shrink-0">{{ $commit['commit'] }}</span>
                                        <span>{{ $commit['subject'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                </div>

                @if($it['ok'] && $it['clean'] && $it['waiting'] !== [])
                    <div class="card-footer">
                        @if($key === 'shop_system')
                            <p class="small mb-2">
                                This is the code every shop runs. If the update brings migrations, each shop's
                                database is still behind until it is migrated — Health will say which.
                            </p>
                        @else
                            <p class="small mb-2">
                                This updates the panel you are looking at. If it goes wrong you will need the
                                terminal to put it back, so the commit you are on now is written to
                                <strong>What I changed</strong> first.
                            </p>
                        @endif

                        {{-- Typed confirmation on the panel's own update: it changes
                             the code serving this page, and putting it back needs a
                             terminal. The shop system gets the hold alone. --}}
                        <x-danger-form :action="route('updates.store')"
                                       :label="'Update ' . \Illuminate\Support\Str::lower($it['name'])"
                                       :confirm="$key === 'panel' ? 'update' : null"
                                       confirm-label="Type update to confirm"
                                       variant="warning">
                            <input type="hidden" name="checkout" value="{{ $key }}">
                        </x-danger-form>
                    </div>
                @endif
            </div>
        </div>
    @endforeach
</div>

    {{--
        The same clearing an update does, offered on its own. Every shop keeps
        its own compiled copy of the shared code (Section 3), so code that
        changed without an update — a file edited on the server, an update that
        half-failed — leaves them serving something older than what is on disk.
    --}}
    <div class="card mt-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <span>
                <span class="d-block">Clear every shop’s compiled code</span>
                <small class="text-secondary">
                    <code>optimize:clear</code> in each shop — config, routes, views and the cache.
                    This runs by itself whenever the shop system is updated above; it is here for the
                    times that is not why it is needed. Nothing is destroyed.
                </small>
            </span>

            <form method="POST" action="{{ route('updates.clear-shops') }}" class="m-0">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-secondary">Clear them all</button>
            </form>
        </div>
    </div>

    {{--
        The look the panel borrows — Section 10.

        Its own card rather than a line in the one above, because it answers a
        different question. That one is "is my code current?"; this is "why does
        the panel look like a 1994 web page?" — and when that has happened, this
        card is the only thing on screen still able to say so, because the
        stylesheet that would have hidden it is the thing that is missing.
    --}}
    <div class="card mt-3 {{ $lookInPlace && $lookStale === [] ? '' : 'border-danger' }}">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <span>
                <span class="d-block">
                    The look borrowed from the shop system
                    @unless($lookInPlace)
                        <span class="badge text-bg-danger ms-1">missing</span>
                    @elseif($lookStale !== [])
                        <span class="badge text-bg-danger ms-1">half updated</span>
                    @endunless
                </span>
                <small class="text-secondary">
                    @if($lookInPlace && $lookStale !== [])
                        These folders are serving an older copy than the panel is asking for, so every
                        stylesheet on every screen is a 404 and the panel shows its real content with no
                        styling at all:
                        <span class="d-block mt-1">
                            @foreach($lookStale as $folder)
                                <code class="d-block">{{ $folder }}/build</code>
                            @endforeach
                        </span>
                        <span class="d-block mt-1">Taking the look again writes both copies at once.</span>
                    @elseif($lookInPlace)
                        The panel has no stylesheet of its own — it wears a copy of the shop system’s
                        compiled <code>public/build</code>. That copy is refreshed whenever the shop system is
                        updated above; this is here for the times that is not why it is needed.
                    @else
                        Every screen here is serving unstyled HTML because this copy is not there. Taking it
                        again is safe: the old one is only removed once a whole new one is on the disk.
                    @endif
                    <span class="d-block mt-1">Taken from <code>{{ $lookSource }}</code>.</span>
                </small>
            </span>

            <form method="POST" action="{{ route('updates.look') }}" class="m-0">
                @csrf
                <button type="submit" class="btn btn-sm {{ $lookInPlace && $lookStale === [] ? 'btn-outline-secondary' : 'btn-danger' }}">
                    Take the look again
                </button>
            </form>
        </div>
    </div>

@endsection
