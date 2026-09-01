<header class="d-flex align-items-center justify-content-end gap-2 px-3 py-2 bg-body border-bottom">
    <div class="dropdown">
        <button class="btn btn-sm btn-outline-secondary dropdown-toggle d-flex align-items-center gap-2"
                data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-person-circle"></i>{{ auth()->user()->name }}
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <li>
                <a class="dropdown-item d-flex align-items-center gap-2"
                   href="{{ route('authenticator.show') }}">
                    <i class="bi bi-shield-check"></i>Authenticator
                    @unless(auth()->user()->hasAuthenticator())
                        {{-- The one thing worth interrupting for: without it
                             there is no way back into the panel at all. --}}
                        <span class="badge text-bg-warning ms-auto">off</span>
                    @endunless
                </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="dropdown-item d-flex align-items-center gap-2">
                        <i class="bi bi-box-arrow-right"></i>Sign out
                    </button>
                </form>
            </li>
        </ul>
    </div>
</header>
