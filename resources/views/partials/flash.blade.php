{{-- The shop system's rule (its Section 9b): validation errors are inline,
     never a toast, because a toast vanishes before the form can be fixed. A
     failure is inline for the same reason — it is the thing being read when
     something has gone wrong. Toasts carry what worked.

     Which is why nothing here overlaps the toast container in layouts/app: the
     first draft showed 'success' in both, and the same sentence appeared twice
     on the screen at once. --}}
@if($errors->any() && $errors->count() > 1)
    <div class="alert alert-danger">
        <div class="fw-semibold mb-1">Please fix the following:</div>
        <ul class="mb-0 ps-3">
            @foreach($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if(session('error'))
    <div class="alert alert-danger d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle"></i>
        <div>{{ session('error') }}</div>
    </div>
@endif

{{-- The guest screens have no toast container, so what worked is shown here
     instead: "the password is changed, sign in with the new one" has to survive
     the redirect to the login page. The signed-in shell passes nothing and gets
     a toast. --}}
@if(session('success') && ($inlineSuccess ?? false))
    <div class="alert alert-success d-flex align-items-center gap-2">
        <i class="bi bi-check-circle"></i>
        <div>{{ session('success') }}</div>
    </div>
@endif
