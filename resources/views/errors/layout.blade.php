{{-- Shared error page. Staff (signed in) go back to the dashboard; everyone else goes to the public tracking page, so
     clients are never sent to the staff area. Plain CSS: error pages must render even when built assets are missing. --}}
@php
    $signedIn = rescue(fn (): bool => auth()->check(), false, false);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | {{ config('app.name', 'DocuTrackS') }}</title>
    <link rel="icon" href="{{ asset('images/icon.png') }}" type="image/png">
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; box-sizing: border-box; background: #f8f4e7; color: #26332b; }
        main { width: min(100%, 560px); box-sizing: border-box; padding: 40px; background: #fff; border: 1px solid #d8c66f; border-radius: 8px; box-shadow: 0 18px 50px rgba(38, 51, 43, .08); }
        .brand { display: flex; align-items: center; gap: 10px; margin: 0 0 24px; color: #26332b; font-weight: 700; }
        .brand img { width: 36px; height: 36px; border-radius: 8px; }
        .brand span span { color: #86562d; }
        .code { margin: 0 0 12px; color: #86562d; font-size: 14px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 0; font-size: clamp(28px, 5vw, 40px); line-height: 1.1; }
        p { margin: 16px 0 0; color: #3f4a43; font-size: 17px; line-height: 1.6; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 28px; }
        a.button { display: inline-flex; align-items: center; min-height: 44px; padding: 0 18px; border: 1px solid #216e39; border-radius: 5px; background: #216e39; color: #fff; font-weight: 700; text-decoration: none; }
        a.button:hover { background: #18542b; }
        a.button:focus-visible { outline: 3px solid #216e39; outline-offset: 2px; }
        @media (max-width: 480px) { main { padding: 28px 20px; } }
    </style>
</head>
<body>
    <main>
        <p class="brand"><img src="{{ asset('images/icon.png') }}" alt=""><span>Docu<span>TrackS</span></span></p>
        <p class="code">@yield('code')</p>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <div class="actions">
            @if ($signedIn)
                <a class="button" href="{{ url('/admin') }}">Return to dashboard</a>
            @else
                <a class="button" href="{{ url('/') }}">Go to the tracking page</a>
            @endif
        </div>
    </main>
</body>
</html>
