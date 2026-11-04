<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="How DocuTrackS collects, uses and protects the information you give when you send or track a document.">
    <title>Privacy notice | DocuTrackS</title>
    <link rel="icon" href="{{ asset('images/icon.png') }}" type="image/png">
    @fonts
    @vite('resources/css/app.css')
</head>
{{-- The full privacy notice as its own page: the send form's "Read the full privacy notice" link opens it in a dialog
     with JavaScript, and lands here without it. --}}
<body class="dts-public-page min-h-screen bg-white font-sans text-province-charcoal antialiased">
    <header class="border-b border-province-line bg-white">
        <div class="mx-auto flex min-h-20 w-full max-w-3xl items-center justify-between gap-4 px-5 py-4 sm:px-8">
            <a href="{{ route('tracking.index') }}" class="flex min-w-0 items-center gap-3 text-slate-900 no-underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-province-green">
                <img src="{{ asset('images/icon.png') }}" alt="" width="56" height="56" class="size-12 shrink-0 rounded-xl sm:size-14">
                <span class="min-w-0">
                    <strong class="block text-2xl font-bold leading-none tracking-tight text-province-charcoal sm:text-3xl">Docu<span class="text-province-brown">TrackS</span></strong>
                    <span class="block text-sm leading-tight text-slate-600">Document Tracking System</span>
                </span>
            </a>
            <a href="{{ route('submission.create') }}" class="inline-flex min-h-11 shrink-0 items-center gap-2 text-sm font-semibold text-province-green hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-province-green"><x-orchid-icon path="bs.arrow-left" class="size-4" /> Back to the form</a>
        </div>
    </header>

    <main class="mx-auto w-full max-w-3xl px-5 py-10 sm:px-8">
        <h1 class="text-3xl font-semibold text-slate-900">Privacy notice</h1>
        <div class="mt-6">
            @include('partials.privacy-notice', ['headingTag' => 'h2'])
        </div>
    </main>
</body>
</html>
