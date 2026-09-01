@php($theme = auth()->user()?->theme ?? 'auto')
<!DOCTYPE html>
<html lang="en" @if($theme !== 'auto') data-bs-theme="{{ $theme }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name') }}</title>

    {{-- Section 10: the shop system's compiled build/, copied in at deploy time. --}}
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])

    @if($theme === 'auto')
        <script>
            document.documentElement.setAttribute(
                'data-bs-theme',
                window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
            );
        </script>
    @endif
</head>
<body class="bg-body-tertiary">
<div class="container min-vh-100 d-flex align-items-center justify-content-center py-5">
    <div class="w-100" style="max-width: 26rem">
        <div class="text-center mb-4">
            <i class="bi bi-sliders display-5 text-primary"></i>
            <h1 class="h4 mt-2 mb-0">{{ config('app.name') }}</h1>
            <div class="text-secondary small">Smart Soran Store System</div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-4">
                @include('partials.flash', ['inlineSuccess' => true])
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
</body>
</html>
