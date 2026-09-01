{{-- Self-contained on purpose: every style on this page is inline, because the
     thing this page is reporting is that the stylesheet is not there. A screen
     that explains a missing stylesheet by failing to load a stylesheet has
     explained nothing. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>The panel has no assets yet</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0; padding: 2.5rem 1.5rem;
            font: 15px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #f6f7f9; color: #1f2328;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #16181c; color: #e6e6e6; }
            .card { background: #1f2226 !important; border-color: #34383e !important; }
            pre { background: #101215 !important; border-color: #34383e !important; }
            .muted { color: #9aa1aa !important; }
        }
        .wrap { max-width: 44rem; margin: 0 auto; }
        .card {
            background: #fff; border: 1px solid #e2e5e9; border-radius: 10px;
            padding: 1.5rem 1.75rem; margin-bottom: 1rem;
        }
        h1 { font-size: 1.3rem; margin: 0 0 .5rem; }
        h2 { font-size: .95rem; margin: 1.5rem 0 .5rem; }
        pre {
            background: #f0f2f4; border: 1px solid #e2e5e9; border-radius: 6px;
            padding: .75rem 1rem; overflow-x: auto; font-size: 13px; margin: .5rem 0;
        }
        .muted { color: #5b6570; }
        code { font-size: .9em; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>The panel has no assets yet</h1>
        <p class="muted" style="margin-top:0">
            Laravel says <code>Vite manifest not found</code>, which normally means
            “run <code>npm run build</code>”. Not here — <strong>the panel has no
            npm, no stylesheet and no build of its own.</strong> That is by design:
            PANEL_DOC Section 10 has it reuse the shop system’s compiled assets, so
            the two look like one product and there is nothing to keep in step.
        </p>
        <p style="margin-bottom:0">
            So the assets are <strong>copied in</strong>, from a checkout of
            <code>ittz-soran/SystemManagment</code>. Build them there once, then
            copy the folder here.
        </p>
    </div>

    {{-- The path is printed exactly as this install reports it, never rewritten:
         a first version flipped the slashes to look Windows-ish and produced
         "\home\user\..." on Linux, which is a path to nowhere. --}}
    @php($here = public_path('build'))
    @php($windows = PHP_OS_FAMILY === 'Windows')

    <div class="card">
        <h2 style="margin-top:0">{{ $windows ? 'Windows' : 'macOS and Linux' }}</h2>
        @if($windows)
            <pre>cd C:\path\to\SystemManagment
npm install
npm run build
xcopy /E /I /Y public\build "{{ $here }}"</pre>
        @else
            <pre>cd /path/to/SystemManagment
npm install &amp;&amp; npm run build
cp -a public/build/. "{{ $here }}/"</pre>
        @endif

        <p class="muted" style="margin-bottom:0">
            Then reload this page. Nothing needs restarting.
        </p>
    </div>

    <div class="card">
        <h2 style="margin-top:0">Why it is not committed</h2>
        <p class="muted" style="margin-bottom:0">
            <code>public/build</code> is deployed, not committed — it belongs to the
            shop system, and a copy kept in this repository would quietly stop
            matching it. <code>BorrowedStylesheetTest</code> checks the copy answers
            to what the layouts ask for, and skips when it is absent, which is why
            <code>php artisan test</code> still passes without it.
        </p>
    </div>
</div>
</body>
</html>
