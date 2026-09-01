{{-- Section 9's eight pages. The ones not built yet say so — see App\Support\Navigation. --}}
<nav class="d-flex flex-column flex-shrink-0 bg-body border-end vh-100 position-sticky top-0"
     style="width: 15rem">
    <a href="{{ route('overview') }}"
       class="d-flex align-items-center gap-2 p-3 text-decoration-none border-bottom">
        <i class="bi bi-sliders fs-4 text-primary"></i>
        <span class="fw-semibold">{{ config('app.name') }}</span>
    </a>

    <ul class="nav nav-pills flex-column p-2 mb-0 gap-1">
        @foreach(\App\Support\Navigation::items() as $item)
            <li class="nav-item">
                @if($item['route'])
                    <a href="{{ route($item['route']) }}"
                       class="nav-link d-flex align-items-center gap-2 {{ request()->routeIs($item['route']) ? 'active' : 'link-body-emphasis' }}">
                        <i class="bi {{ $item['icon'] }}"></i>{{ $item['label'] }}
                    </a>
                @else
                    {{-- Dimmed, unclickable, and saying why. Section 7: the
                         reason belongs on the screen before the press. --}}
                    <span class="nav-link d-flex align-items-center gap-2 text-secondary opacity-50"
                          style="cursor: not-allowed"
                          title="Not built yet — {{ $item['step'] }}">
                        <i class="bi {{ $item['icon'] }}"></i>{{ $item['label'] }}
                    </span>
                @endif
            </li>
        @endforeach
    </ul>
</nav>
