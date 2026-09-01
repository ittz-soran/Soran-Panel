{{--
    How full a shop is. Section 9 wants this broken into its three parts on the
    customer's own page, so the same component draws one bar of three segments
    and captions it, rather than two components drifting apart.
--}}
@props(['check', 'detailed' => false])
@php
    $percent = $check?->storagePercent();
    $limit = $check?->storage_limit_mb ? $check->storage_limit_mb * 1024 * 1024 : null;
    $parts = [
        ['Database', $check?->database_bytes, 'bg-primary'],
        ['Backups',  $check?->backups_bytes,  'bg-info'],
        ['Uploads',  $check?->uploads_bytes,  'bg-secondary'],
    ];
    $over = $percent !== null && $percent >= config('panel.attention.storage_percent');
@endphp

@if ($check === null || ! $check->reachable)
    <span class="text-secondary">unknown</span>
@else
    <div class="d-flex align-items-baseline gap-2">
        <span class="fw-semibold">{{ \App\Support\Bytes::human($check->totalBytes()) }}</span>
        @if ($percent !== null)
            <span class="small {{ $over ? 'text-danger fw-semibold' : 'text-secondary' }}">{{ $percent }}%</span>
        @else
            <span class="small text-secondary">no limit set</span>
        @endif
    </div>

    @if ($limit)
        <div class="progress mt-1" style="height: {{ $detailed ? '.75rem' : '.35rem' }}"
             role="progressbar" aria-label="Storage used"
             aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
            @foreach ($parts as [$label, $bytes, $colour])
                @if ($bytes)
                    <div class="progress-bar {{ $colour }}" style="width: {{ min(100, $bytes / $limit * 100) }}%"
                         @if($detailed) title="{{ $label }}: {{ \App\Support\Bytes::human($bytes) }}" @endif></div>
                @endif
            @endforeach
        </div>
    @endif

    @if ($detailed)
        <div class="d-flex flex-wrap gap-3 mt-2 small">
            @foreach ($parts as [$label, $bytes, $colour])
                <span class="d-inline-flex align-items-center gap-1">
                    <span class="d-inline-block rounded {{ $colour }}" style="width:.6rem;height:.6rem"></span>
                    {{ $label }} <span class="text-secondary">{{ \App\Support\Bytes::human($bytes) }}</span>
                </span>
            @endforeach
            <span class="text-secondary">
                of {{ $check->storage_limit_mb ? \App\Support\Bytes::human($check->storage_limit_mb * 1024 * 1024) : 'no limit' }}
            </span>
        </div>
    @endif
@endif
