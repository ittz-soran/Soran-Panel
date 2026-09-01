@extends('layouts.app')

@section('title', 'Overview')
@section('subheading', 'Only what needs you this week.')

@section('content')
    {{-- Section 9: "licences running out, storage near its limit, shops nobody
         has used." None of that can be answered until customers, licences and
         health checks exist — build order steps 3 to 5. Saying so is the honest
         empty state; three zeroes would read as "nothing needs you", which is
         a different and untrue statement. --}}
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-clipboard-check display-4 text-secondary opacity-50"></i>
            <h2 class="h5 mt-3">Nothing to show yet</h2>
            <p class="text-secondary mb-0">
                No customers have been recorded. Licences running out, storage near
                its limit and shops nobody has used will appear here once the
                customer list exists.
            </p>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header">Where the build is</div>
        <ul class="list-group list-group-flush">
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span><i class="bi bi-check-circle text-success me-2"></i>Panel scaffold — auth, the authenticator, this shell</span>
                <span class="badge text-bg-success">done</span>
            </li>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span><i class="bi bi-check-circle text-success me-2"></i>Schema and models — the six tables in Section 5</span>
                <span class="badge text-bg-success">done</span>
            </li>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span><i class="bi bi-check-circle text-success me-2"></i>Reading a shop, and the hourly health check</span>
                <span class="badge text-bg-success">done</span>
            </li>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span><i class="bi bi-hourglass-split text-secondary me-2"></i>Customers, one customer, Overview — the screens that only read</span>
                <span class="badge text-bg-secondary">next</span>
            </li>
        </ul>
    </div>
@endsection
