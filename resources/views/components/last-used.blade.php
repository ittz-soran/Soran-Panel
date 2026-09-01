@props(['check'])
@php $at = $check?->last_activity_at; @endphp

@if ($check === null || ! $check->reachable)
    <span class="text-secondary">unknown</span>
@elseif ($at === null)
    <span class="text-warning">never</span>
@else
    <span title="{{ $at->toDayDateTimeString() }}"
          class="{{ $at->lt(now()->subDays(config('panel.attention.unused_days'))) ? 'text-warning fw-semibold' : '' }}">
        {{ $at->diffForHumans() }}
    </span>
@endif
