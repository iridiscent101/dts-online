@php
    // ?paper=a4 prints 40 smaller labels (49.5 x 28.5 mm) per A4 sheet, 4 across and 10 down, to cut out; otherwise one
    // 60 x 30 mm label per page for a label printer. ?copies repeats each label (up to a full sheet), e.g. to fill a sheet
    // with one document's label.
    $paper = request()->query('paper') === 'a4' ? 'a4' : 'label';
    $perSheet = $paper === 'a4' ? 40 : 27;
    $copies = max(1, min($perSheet, (int) request()->query('copies', 1)));
    // A4 only: ?start=N begins at space N (counted left to right, top to bottom), so a part-used sheet can go back in the
    // printer; the spaces before it print blank.
    $start = $paper === 'a4' ? max(1, min($perSheet, (int) request()->query('start', 1))) : 1;
    $labelCount = $labels->count() * $copies;
    // A4: the spaces in print order (null for a used space), 40 to a sheet.
    $sheets = $paper === 'a4'
        ? collect(array_fill(0, $start - 1, null))
            ->concat($labels->flatMap(fn (array $label): array => array_fill(0, $copies, $label)))
            ->chunk($perSheet)
            ->map(fn ($sheet) => $sheet->values())
        : collect();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tracking {{ $labels->count() === 1 ? 'label | '.$labels->first()['document']->tracking_number : 'labels | '.$labels->count().' documents' }}</title>
    <style>
        @if ($paper === 'a4')
            /* A4 with no page margin, so browsers print no URL or date header; each .label-sheet keeps its own 0.2 inch
               margin: 4 x 49.5 mm across and 10 x 28.5 mm down, from the top-left. */
            @page { size: A4; margin: 0; }
        @else
            @page { size: 60mm 30mm; margin: 0; }
        @endif
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
        .paper-choice { display: inline-flex; border: 1px solid #216e39; border-radius: 4px; overflow: hidden; }
        .print-toolbar .paper-choice a { border: 0; border-radius: 0; }
        .print-toolbar .paper-choice a[aria-current="true"] { background: #216e39; color: #fff; }
        .copies-form { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; }
        .copies-form input[type="number"] { width: 64px; min-height: 40px; padding: 4px 8px; border: 1px solid #b3843f; border-radius: 4px; font: inherit; }
        .print-toolbar .copies-form button { width: auto; padding: 0 12px; background: #fff; color: #216e39; }
        .label-stack { display: grid; justify-content: start; gap: 12px; }
        /* QR on the left, the routing details beside it, and the tracking number across the bottom. */
        .dts-tracking-label { display: grid; grid-template-columns: 18mm minmax(0, 1fr); grid-template-rows: minmax(18mm, 1fr) auto; column-gap: 1.8mm; row-gap: 0.8mm; width: 60mm; height: 30mm; padding: 1.5mm 1.8mm; overflow: hidden; border: 1px solid #9ca3af; border-radius: 3px; background: #fff; color: #111827; }
        .dts-tracking-label img { display: block; align-self: center; width: 18mm; height: 18mm; }
        .dts-tracking-label-details { display: grid; grid-template-columns: auto minmax(0, 1fr); align-content: center; column-gap: 1.6mm; row-gap: 0.7mm; margin: 0; font-size: 6.5pt; line-height: 1.12; }
        .dts-tracking-label-field { min-width: 0; }
        .dts-tracking-label-field.is-wide { grid-column: 1 / -1; }
        .dts-tracking-label-details dt { color: #4b5563; font-size: 4.6pt; font-weight: 700; letter-spacing: 0.03em; line-height: 1.25; text-transform: uppercase; }
        /* Values break only between words; a long name is cut at two lines. */
        .dts-tracking-label-details dd { display: -webkit-box; margin: 0; overflow: hidden; font-weight: 700; overflow-wrap: break-word; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
        .dts-tracking-label-details .is-wide:first-child dd { font-size: 7.5pt; -webkit-line-clamp: 1; }
        /* Names stay on one line; the script below shrinks a long name (down to 4.6pt, the caption size) until it fits,
           and only a name still too long at that size ends in "…". Release labels carry two names, so rows sit closer. */
        .dts-tracking-label-details .is-name dd { display: block; white-space: nowrap; text-overflow: ellipsis; }
        .dts-tracking-label-details.has-release { row-gap: 0.4mm; }
        .dts-tracking-label-footer { display: grid; grid-column: 1 / -1; align-content: center; gap: 0.3mm; border-top: 0.3mm solid #111827; padding-top: 0.6mm; text-align: center; }
        /* The tracking number is the one line staff read off the label, so it is the largest text on it. */
        .dts-tracking-label-footer strong { font-family: "Consolas", "Courier New", monospace; font-size: 10pt; font-weight: 800; letter-spacing: -0.01em; line-height: 1.1; overflow-wrap: anywhere; }
        .dts-tracking-label-status { font-weight: 800 !important; letter-spacing: 0.02em; text-transform: uppercase; }
        @media screen {
            /* Show the label larger on screen so it is easy to check before printing; print stays at true size. */
            .label-stack { zoom: 2; }
            /* A4: each sheet previewed at true size, laid out as it prints. */
            .label-sheet { background: #fff; box-shadow: 0 2px 10px rgb(0 0 0 / 0.15); }
            .label-sheet + .label-sheet { margin-top: 24px; }
            .label-slot-used { display: grid; place-items: center; outline: 1px dashed #cbd5e1; outline-offset: -2px; color: #94a3b8; font-size: 11px; }
        }
        /* A4 sheet: smaller 49.5 x 28.5 mm labels edge to edge in 4 columns. Each cut line is drawn once: every label cell
           draws its right and bottom edge, and its top or left edge only where no label is above or beside it. */
        .label-sheet { display: grid; grid-template-columns: repeat(4, 49.5mm); grid-auto-rows: 28.5mm; justify-content: start; align-content: start; width: 210mm; height: 297mm; padding: 0.2in; overflow: hidden; }
        .label-cell { border-right: 1px solid #9ca3af; border-bottom: 1px solid #9ca3af; }
        .label-cell.has-top { border-top: 1px solid #9ca3af; }
        .label-cell.has-left { border-left: 1px solid #9ca3af; }
        /* Spaces already used on a reused sheet: blank on paper, outlined faintly on screen. */
        .label-slot-used { width: 49.5mm; height: 28.5mm; }
        .is-a4 .dts-tracking-label { grid-template-columns: 16.5mm minmax(0, 1fr); grid-template-rows: minmax(16.5mm, 1fr) auto; column-gap: 1.5mm; width: 100%; height: 100%; padding: 1.1mm 1.4mm; border: 0; border-radius: 0; }
        .is-a4 .dts-tracking-label img { width: 16.5mm; height: 16.5mm; }
        .is-a4 .dts-tracking-label-footer { padding-top: 0.4mm; }
        .is-a4 .dts-tracking-label-footer strong { font-size: 9pt; }
        /* The shorter label: slightly tighter text so all five lines fit beside the QR. */
        .is-a4 .dts-tracking-label-details { row-gap: 0.25mm; font-size: 6pt; line-height: 1.05; }
        .is-a4 .dts-tracking-label-details dt { font-size: 4.2pt; line-height: 1.15; }
        .is-a4 .dts-tracking-label-details .is-wide:first-child dd { font-size: 7pt; }
        .is-a4 .dts-tracking-label-details.has-release { row-gap: 0.15mm; }
        @media print {
            body { min-height: 0; padding: 0; background: #fff; }
            .print-toolbar { display: none; }
            .label-stack { display: block; }
            .dts-tracking-label { border-color: #111827; border-radius: 0; }
            .is-label .dts-tracking-label:not(:last-child) { break-after: page; page-break-after: always; }
            .label-sheet { box-shadow: none; break-inside: avoid; page-break-inside: avoid; }
            .label-sheet:not(:last-child) { break-after: page; page-break-after: always; }
            .label-cell, .label-cell.has-top, .label-cell.has-left { border-color: #111827; }
            .label-slot-used { visibility: hidden; }
            .dts-tracking-label-details dt { color: #111827; }
        }
    </style>
</head>
<body class="is-{{ $paper }}">
    <div class="print-toolbar">
        <a href="{{ $backUrl }}">{{ $backLabel ?? 'Back to '.($labels->count() === 1 ? 'document' : 'registry') }}</a>
        <button type="button" data-print data-tooltip="Print {{ $labelCount === 1 ? 'label' : 'labels' }}" aria-label="Print {{ $labelCount === 1 ? 'label' : 'labels' }}"><x-orchid-icon path="bs.printer" /></button>
        <span class="paper-choice" role="group" aria-label="Paper">
            <a href="{{ request()->fullUrlWithQuery(['paper' => null]) }}" @if ($paper === 'label') aria-current="true" @endif>Label printer</a>
            <a href="{{ request()->fullUrlWithQuery(['paper' => 'a4']) }}" @if ($paper === 'a4') aria-current="true" @endif>A4 sheet</a>
        </span>
        <form class="copies-form" method="GET" action="{{ url()->current() }}">
            @foreach (request()->except(['copies', 'start']) as $name => $value)
                @foreach ((array) $value as $item)
                    <input type="hidden" name="{{ is_array($value) ? $name.'[]' : $name }}" value="{{ $item }}">
                @endforeach
            @endforeach
            <label for="label-copies">Copies</label>
            <input id="label-copies" type="number" name="copies" min="1" max="{{ $perSheet }}" value="{{ $copies }}">
            @if ($paper === 'a4')
                <label for="label-start">Start at space</label>
                <input id="label-start" type="number" name="start" min="1" max="{{ $perSheet }}" value="{{ $start }}" title="For a sheet that is already partly used: the first empty space, counted left to right, top to bottom">
            @endif
            <button type="submit">Apply</button>
        </form>
    </div>
    @if ($paper === 'a4')
        @foreach ($sheets as $sheet)
            @php($firstLabel = $sheet->search(fn ($item) => $item !== null))
            <div class="label-sheet">
                @foreach ($sheet as $position => $item)
                    @if ($item === null)
                        <div class="label-slot-used" aria-hidden="true">Used</div>
                    @else
                        {{-- Top edge on the first row or under a used space; left edge in the first column or beside one. --}}
                        <div @class(['label-cell', 'has-top' => $position - 4 < $firstLabel, 'has-left' => $position % 4 === 0 || $position - 1 < $firstLabel])>
                            @include('orchid.partials.tracking-label', $item)
                        </div>
                    @endif
                @endforeach
            </div>
        @endforeach
    @else
        <div class="label-stack">
            @foreach ($labels as $label)
                @for ($copy = 0; $copy < $copies; $copy++)
                    @include('orchid.partials.tracking-label', $label)
                @endfor
            @endforeach
        </div>
    @endif
    <script nonce="{{ Vite::cspNonce() }}">
        document.querySelector('[data-print]').addEventListener('click', () => window.print());

        // Fit each name on its one line: step the size down from 6.5pt to 4.6pt until it no longer overflows.
        const fitNames = () => document.querySelectorAll('.dts-tracking-label-details .is-name dd').forEach((name) => {
            name.style.fontSize = '';
            for (let size = 6.25; name.scrollWidth > name.clientWidth; size -= 0.25) {
                name.style.fontSize = `${Math.max(size, 4.6)}pt`;
                if (size <= 4.6) break;
            }
        });
        fitNames();
        document.fonts?.ready.then(fitNames);
    </script>
</body>
</html>
