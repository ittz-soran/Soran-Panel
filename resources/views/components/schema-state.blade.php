{{--
    Where Section 9 asked for "code version".

    Section 3 settled the day after those pages were designed that every shop
    reads one shared folder, so there is no per-shop code version left to show —
    it is the same string on every row by construction. What still differs is
    whether a shop has RUN that shared code's migrations, which is the question
    "code version" was there to answer.
--}}
@props(['check'])
@php $behind = $check?->migrationsPending(); @endphp

@if ($behind === null)
    <span class="text-secondary">unknown</span>
@elseif ($behind === 0)
    <span class="text-success"><i class="bi bi-check-circle me-1"></i>Up to date</span>
@else
    <span class="text-warning fw-semibold">
        <i class="bi bi-exclamation-triangle me-1"></i>{{ $behind }} {{ Str::plural('migration', $behind) }} behind
    </span>
@endif
