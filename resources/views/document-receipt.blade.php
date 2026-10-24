<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Receipt {{ $document->tracking_number }} | DocuTrackS</title>
    <link rel="icon" href="{{ asset('images/icon.png') }}" type="image/png">
    @fonts
    @vite(['resources/css/app.css'])
</head>
<body class="dts-public-page dts-receipt-page bg-[#F8F4E7] font-sans text-[#26332B] antialiased">
    {{-- Screen only: back to tracking and Print. The receipt itself is one A4/Letter-friendly card. --}}
    <div class="dts-receipt-actions mx-auto flex w-full max-w-xl flex-wrap items-center justify-between gap-3 px-5 pt-6">
        <a href="{{ $trackingUrl }}" class="inline-flex min-h-11 items-center gap-2 rounded-sm border border-[#216E39] bg-white px-4 text-sm font-semibold text-[#216E39] no-underline hover:bg-[#F8F4E7]"><x-orchid-icon path="bs.arrow-left" class="size-4" /> Back to tracking</a>
        <button type="button" data-print-receipt class="inline-flex min-h-11 items-center gap-2 rounded-sm bg-[#216E39] px-5 text-sm font-semibold text-white hover:bg-[#18542B]"><x-orchid-icon path="bs.printer" class="size-4" /> Print receipt</button>
    </div>

    <main class="dts-receipt mx-auto my-6 w-full max-w-xl rounded-sm border border-province-line-strong bg-white p-6 sm:p-8">
        <header class="flex items-center gap-3 border-b border-province-line-strong pb-4">
            <img src="{{ asset('images/icon.png') }}" alt="" width="48" height="48" class="size-12 rounded-xl">
            <div>
                <p class="text-xl font-bold leading-none tracking-tight">Docu<span class="text-[#86562D]">TrackS</span></p>
                <p class="mt-1 text-xs font-semibold uppercase tracking-wide text-[#86562D]">{{ $contact['office'] }}</p>
            </div>
            <p class="ml-auto text-right text-xs font-semibold uppercase tracking-wide text-slate-600">Document<br>receipt</p>
        </header>

        <section class="mt-5 grid items-center gap-5 sm:grid-cols-[auto_minmax(0,1fr)]" aria-label="Tracking number">
            <img src="{{ $qrCodeDataUri }}" alt="QR code for {{ $document->tracking_number }}" width="176" height="176" class="size-44 justify-self-center rounded-sm border border-province-line bg-white p-1.5">
            <div class="text-center sm:text-left">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">Tracking number</p>
                <p class="mt-1 font-mono text-2xl font-extrabold tracking-tight text-slate-900 [overflow-wrap:anywhere] sm:text-3xl">{{ $document->tracking_number }}</p>
                <p class="mt-2 text-sm leading-6 text-slate-700">Scan the QR code or enter this number on the Fast Track page to see where your document is.</p>
            </div>
        </section>

        <dl class="mt-6 grid grid-cols-[9rem_minmax(0,1fr)] gap-x-4 gap-y-2 border-t border-province-line-strong pt-4 text-sm">
            {{-- Client submissions keep the title private, as on the public tracking page. --}}
            @unless ($document->client_submission_key)
                <dt class="font-medium text-slate-600">Document</dt><dd class="font-semibold text-slate-900 [overflow-wrap:anywhere]">{{ $document->subject }}</dd>
            @endunless
            <dt class="font-medium text-slate-600">Type of document</dt><dd class="font-semibold text-slate-900">{{ $document->document_type }}</dd>
            <dt class="font-medium text-slate-600">Date submitted</dt><dd class="font-semibold text-slate-900">{{ $document->created_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</dd>
            @if (filled($document->delivery_method))
                <dt class="font-medium text-slate-600">Mode of submission</dt><dd class="font-semibold text-slate-900">{{ $document->delivery_method }}</dd>
            @endif
        </dl>

        @if ($awaitsDocuments)
            <p class="mt-5 rounded-sm border border-[#F2C230] bg-[#FFF8E1] px-4 py-3 text-sm font-medium leading-6 text-[#5B4300]">
                {{ $document->delivery_method === 'Official channel'
                    ? 'Send the document/s through official mail or your school or office messenger. Processing starts once the Records Unit receives them.'
                    : 'Bring the document/s and this receipt to the Records Unit counter. Processing starts once the Records Unit receives them.' }}
            </p>
        @endif

        <footer class="mt-6 border-t border-province-line-strong pt-4 text-xs leading-5 text-slate-600">
            <p class="font-semibold text-slate-800">Track online: <span class="font-normal [overflow-wrap:anywhere]">{{ $trackingUrl }}</span></p>
            @if (filled($contact['address']))<p>{{ $contact['address'] }}</p>@endif
            <p>
                @if (filled($contact['phone'])){{ str_replace(',', ' ·', $contact['phone']) }}@endif
                @if (filled($contact['phone']) && filled($contact['email'])) · @endif
                @if (filled($contact['email'])){{ $contact['email'] }}@endif
            </p>
            <p class="mt-2 text-slate-600">Printed {{ now('Asia/Manila')->format('M j, Y · g:i A') }}</p>
        </footer>
    </main>

    <script nonce="{{ Vite::cspNonce() }}">
        document.querySelector('[data-print-receipt]')?.addEventListener('click', () => window.print());
    </script>
</body>
</html>
