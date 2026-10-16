<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tracking {{ $labels->count() === 1 ? 'label | '.$labels->first()['document']->tracking_number : 'labels | '.$labels->count().' documents' }}</title>
    <style>
        @page { size: 50mm 30mm; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; padding: 24px; background: #f8f4e7; color: #26332b; font-family: Arial, sans-serif; }
        .print-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 20px; }
        .print-toolbar a, .print-toolbar button { padding: 9px 13px; border: 1px solid #216e39; border-radius: 4px; background: #fff; color: #216e39; font: inherit; text-decoration: none; cursor: pointer; }
        .print-toolbar button { position: relative; display: inline-grid; place-items: center; width: 40px; height: 40px; padding: 0; background: #216e39; color: #fff; }
        .print-toolbar button svg { width: 20px; height: 20px; }
        /* Hover text for the icon-only Print button (this page does not load the app styles). */
        .print-toolbar button::after { content: attr(data-tooltip); position: absolute; top: calc(100% + 6px); left: 50%; padding: 4px 8px; border-radius: 4px; background: #26332b; color: #fff; font-size: 14px; font-weight: 600; white-space: nowrap; pointer-events: none; opacity: 0; transform: translateX(-50%); transition: opacity 120ms ease; }
        .print-toolbar button:is(:hover, :focus-visible)::after { opacity: 1; }
        .print-toolbar button:focus-visible { outline: 3px solid #f2c230; outline-offset: 2px; }
        .print-toolbar small { color: #4b5563; font-size: 14px; }
        .label-stack { display: grid; justify-content: start; gap: 12px; }
        /* QR on the left, the routing details beside it, and the tracking number across the bottom. */
        .dts-tracking-label { display: grid; grid-template-columns: 18mm minmax(0, 1fr); grid-template-rows: minmax(18mm, 1fr) auto; column-gap: 1.8mm; row-gap: 0.8mm; width: 50mm; height: 30mm; padding: 1.5mm 1.8mm; overflow: hidden; border: 1px solid #9ca3af; border-radius: 3px; background: #fff; color: #111827; }
        .dts-tracking-label img { display: block; align-self: center; width: 18mm; height: 18mm; }
        .dts-tracking-label-details { display: grid; grid-template-columns: auto minmax(0, 1fr); align-content: center; column-gap: 1.8mm; row-gap: 0.4mm; margin: 0; font-size: 6.5pt; line-height: 1.12; }
        .dts-tracking-label-details dt { color: #4b5563; font-size: 5pt; font-weight: 700; letter-spacing: 0.02em; line-height: 1.6; text-transform: uppercase; }
        .dts-tracking-label-details dd { display: -webkit-box; margin: 0; overflow: hidden; font-weight: 700; overflow-wrap: anywhere; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
        /* Status may need three lines ("Awaiting physical document"); a long name is cut at two. */
        .dts-tracking-label-details dd:first-of-type { -webkit-line-clamp: 3; }
        .dts-tracking-label-footer { display: grid; grid-column: 1 / -1; align-content: center; gap: 0.3mm; border-top: 0.3mm solid #111827; padding-top: 0.6mm; text-align: center; }
        /* The tracking number is the one line staff read off the sticker, so it is the largest text on it. */
        .dts-tracking-label-footer strong { font-family: "Consolas", "Courier New", monospace; font-size: 10pt; font-weight: 800; letter-spacing: -0.01em; line-height: 1.1; overflow-wrap: anywhere; }
        .dts-tracking-label-status { font-weight: 800 !important; letter-spacing: 0.03em; text-transform: uppercase; }
        @media screen {
            /* Show the sticker larger on screen so it is easy to check before printing; print stays at true size. */
            .label-stack { zoom: 2; }
        }
        @media print {
            body { min-height: 0; padding: 0; background: #fff; }
            .print-toolbar { display: none; }
            .label-stack { display: block; }
            .dts-tracking-label { border-color: #111827; border-radius: 0; }
            .dts-tracking-label:not(:last-child) { break-after: page; page-break-after: always; }
            .dts-tracking-label-details dt { color: #111827; }
        }
    </style>
</head>
<body>
    <div class="print-toolbar">
        <a href="{{ $backUrl }}">Back to {{ $labels->count() === 1 ? 'document' : 'registry' }}</a>
        <button type="button" data-print data-tooltip="Print {{ $labels->count() === 1 ? 'label' : 'labels' }}" aria-label="Print {{ $labels->count() === 1 ? 'label' : 'labels' }}"><x-orchid-icon path="bs.printer" /></button>
        <small>Sticker size 50 &times; 30 mm. Shown here at twice its size.</small>
    </div>
    <div class="label-stack">
        @foreach ($labels as $label)
            @include('orchid.partials.tracking-label', $label)
        @endforeach
    </div>
    <script nonce="{{ Vite::cspNonce() }}">
        document.querySelector('[data-print]').addEventListener('click', () => window.print());
    </script>
</body>
</html>
