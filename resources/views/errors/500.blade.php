<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Something went wrong | {{ config('app.name', 'DTS') }}</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f8f4e7; color: #26332b; }
        main { width: min(92vw, 560px); padding: 40px; background: #fff; border: 1px solid #d8c66f; border-radius: 8px; box-shadow: 0 18px 50px rgba(38, 51, 43, .08); }
        .code { margin: 0 0 12px; color: #7f1d1d; font-size: 14px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 0; font-size: clamp(28px, 5vw, 40px); line-height: 1.1; }
        p { margin: 16px 0 0; color: #86562d; line-height: 1.6; }
        a { display: inline-flex; margin-top: 28px; color: #216e39; font-weight: 700; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <main>
        <p class="code">500</p>
        <h1>Something went wrong</h1>
        <p>The system could not complete the request. Please try again, or contact an administrator if the problem continues.</p>
        <a href="{{ url('/admin') }}">Return to dashboard</a>
    </main>
</body>
</html>
