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
                    @if($it['problem'])
                        <div class="alert alert-danger mb-0 small">{{ $it['problem'] }}</div>
                    @else
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
@endsection
