<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tracking {{ $labels->count() === 1 ? 'label | '.$labels->first()['document']->tracking_number : 'labels | '.$labels->count().' documents' }}</title>
    <style>
        @page { size: 70mm 30mm; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; padding: 24px; background: #f8f4e7; color: #26332b; font-family: Arial, sans-serif; }
        .print-toolbar { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
        .print-toolbar a, .print-toolbar button { padding: 9px 13px; border: 1px solid #216e39; border-radius: 4px; background: #fff; color: #216e39; font: inherit; text-decoration: none; cursor: pointer; }
        .print-toolbar button { background: #216e39; color: #fff; }
        .label-stack { display: grid; gap: 12px; }
        .dts-tracking-label { display: flex; align-items: center; gap: 2mm; width: 70mm; height: 30mm; padding: 2mm; border: 1px solid #9ca3af; border-radius: 3px; background: #fff; }
        .dts-tracking-label img { display: block; width: 24mm; height: 24mm; flex: 0 0 24mm; }
        .dts-tracking-label-copy { display: flex; min-width: 0; flex-direction: column; gap: 1mm; }
        .dts-tracking-label-copy span { color: #216e39; font-size: 10px; font-weight: 700; }
        .dts-tracking-label-copy strong { overflow-wrap: anywhere; color: #111827; font-size: 12px; line-height: 1.15; }
        .dts-tracking-label-copy small { color: #4b5563; font-size: 9px; }
        @media print {
            body { min-height: 0; padding: 0; background: #fff; }
            .print-toolbar { display: none; }
            .label-stack { display: block; }
            .dts-tracking-label { border-color: #111827; border-radius: 0; }
            .dts-tracking-label:not(:last-child) { break-after: page; page-break-after: always; }
            .dts-tracking-label-copy span { color: #111827; }
        }
    </style>
</head>
<body>
    <div class="print-toolbar">
        <a href="{{ $backUrl }}">Back to {{ $labels->count() === 1 ? 'document' : 'registry' }}</a>
        <button type="button" data-print>Print {{ $labels->count() === 1 ? 'label' : 'labels' }}</button>
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
