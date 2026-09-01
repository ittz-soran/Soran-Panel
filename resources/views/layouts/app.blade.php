{{--
    The panel's shell — PANEL_DOC Section 9.

    Deliberately the shop system's shape (fixed sidebar, slim topbar, toasts
    top-right) so the two read as one product, and deliberately smaller: no
    language switch and no RTL, because Section 9 says English only — the panel
    has one reader. No brand block either: a shop's colours belong to the shop.
--}}
@php($theme = auth()->user()?->theme ?? 'auto')
<!DOCTYPE html>
<html lang="en" @if($theme !== 'auto') data-bs-theme="{{ $theme }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Overview') · {{ config('app.name') }}</title>

    {{-- Section 10: the look is the shop system's compiled build/, copied in at
         deploy time. The panel has no stylesheet of its own to keep in step
         with it, and no npm build. If build/ has not been copied, this throws
         by name — a loud failure, which is the right one: a panel serving
         unstyled HTML looks broken rather than undeployed. --}}
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])

    @if($theme === 'auto')
        {{-- Applied before first paint so the page never flashes the wrong theme. --}}
        <script>
            document.documentElement.setAttribute(
                'data-bs-theme',
                window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
            );
        </script>
    @endif
</head>
<body class="bg-body-tertiary" data-hold-hint="Hold to confirm">
<div class="d-flex">
    @include('layouts.sidebar')

    <div class="flex-grow-1 min-vw-0 d-flex flex-column">
        @include('layouts.topbar')

        <main class="flex-grow-1 p-3 p-lg-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <div>
                    <h1 class="h4 mb-0">@yield('heading', View::yieldContent('title'))</h1>
                    @hasSection('subheading')
                        <div class="text-secondary small">@yield('subheading')</div>
                    @endif
                </div>
                <div class="d-flex gap-2">@yield('actions')</div>
            </div>

            @include('partials.flash')

            @yield('content')
        </main>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1090">
    @foreach(['success' => 'success', 'warning' => 'warning'] as $key => $variant)
        @if(session($key))
            <div class="toast align-items-center text-bg-{{ $variant }} border-0" role="alert" aria-live="polite">
                <div class="d-flex">
                    <div class="toast-body">{{ session($key) }}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto"
                            data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        @endif
    @endforeach
</div>

@include('partials.confirm-word')

@stack('scripts')
</body>
</html>
