{{--
    A form that does something irreversible — PANEL_DOC Section 7, and the shop
    system's own Section 9b.

    Two rails, and both come from the shop system rather than being invented
    here. `data-guard-submit` is what its app.js watches for, and it turns the
    submit button into a two-second hold with a filling bar; the panel's layout
    sets the wording with data-hold-hint. Where a `confirm` word is given, the
    button also stays disabled until it has been typed exactly.

    The reason lives on the button before the press, never after it.
--}}
@props([
    'action',
    'method' => 'POST',
    'label',
    'confirm' => null,      // a word that must be typed, e.g. the shop's host
    'confirmLabel' => null,
    'disabled' => false,
    'reason' => null,       // why it is disabled, shown on the button itself
    'variant' => 'danger',
])

@php($id = 'danger-'.Str::random(8))

<form method="POST" action="{{ $action }}" @if(! $disabled) data-guard-submit @endif class="m-0">
    @csrf
    @if (! in_array($method, ['GET', 'POST'], true))
        @method($method)
    @endif

    {{ $slot ?? '' }}

    @if ($confirm && ! $disabled)
        <div class="mb-2">
            <label for="{{ $id }}" class="form-label small mb-1">
                {{ $confirmLabel ?? 'Type '.$confirm.' to confirm' }}
            </label>
            <input type="text" class="form-control form-control-sm" id="{{ $id }}"
                   autocomplete="off" autocapitalize="off" spellcheck="false"
                   data-confirm-word="{{ $confirm }}" data-confirm-target="{{ $id }}-go">
        </div>
    @endif

    <button type="submit" id="{{ $id }}-go"
            class="btn btn-sm btn-outline-{{ $variant }}"
            @if($disabled) disabled title="{{ $reason }}" @elseif($confirm) disabled @endif>
        {{ $disabled ? ($reason ?? 'Not available') : $label }}
    </button>
</form>
