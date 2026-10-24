<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="DocuTrackS public document tracking portal for SDO – Nueva Vizcaya">
    <title>Track a document | DocuTrackS</title>
    <link rel="icon" href="{{ asset('images/icon.png') }}" type="image/png">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/floating-tooltip.js', 'resources/js/copy-button.js', 'resources/js/tracking-refresh.js', 'resources/js/timeline-reveal.js'])
</head>
<body class="dts-public-page flex min-h-screen flex-col bg-[#F8F4E7] font-sans text-[#26332B] antialiased">
    <div class="fixed inset-x-0 top-0 z-50 grid h-1 grid-cols-[2fr_1fr_1fr]" aria-hidden="true">
        <span class="bg-[#216E39]"></span>
        <span class="bg-[#F2C230]"></span>
        <span class="bg-[#86562D]"></span>
    </div>

    {{-- DocuTrackS branding and contact links on every public page (landing, tracking results, the send form). The
         links come from config/dts.php; empty ones are left out. --}}
    @php
        $contact = config('dts.contact');
    @endphp
    <header class="border-b border-province-line bg-white">
        <div class="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-x-6 gap-y-2 px-5 py-3 sm:px-8">
            <a href="{{ route('tracking.index') }}" class="flex min-w-0 items-center gap-3 rounded-lg text-inherit no-underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#216E39]">
                <img src="{{ asset('images/icon.png') }}" alt="" width="56" height="56" class="size-11 shrink-0 rounded-xl sm:size-14">
                <span class="min-w-0">
                    <span class="block text-2xl font-bold leading-none tracking-tight text-[#26332B] sm:text-3xl">Docu<span class="text-[#86562D]">TrackS</span></span>
                    <span class="mt-1 block text-xs font-semibold uppercase tracking-wide text-[#86562D] sm:text-sm">Document Tracking System · SDO – Nueva Vizcaya</span>
                </span>
            </a>
            <nav class="flex flex-wrap items-center gap-1" aria-label="Contact and social links">
                <a href="#contact" class="inline-flex min-h-10 items-center gap-2 rounded-sm px-3 text-sm font-semibold text-[#216E39] no-underline hover:bg-[#F8F4E7] focus-visible:outline-2 focus-visible:outline-[#216E39]"><x-orchid-icon path="bs.telephone" class="size-4" /> Contact us</a>
                @if (filled($contact['facebook']))
                    <a href="{{ $contact['facebook'] }}" target="_blank" rel="noopener" class="grid size-10 place-items-center rounded-sm text-[#216E39] hover:bg-[#F8F4E7] focus-visible:outline-2 focus-visible:outline-[#216E39]" aria-label="Facebook page (opens in a new tab)" data-tooltip="Facebook" data-tooltip-below><x-orchid-icon path="bs.facebook" class="size-5" /></a>
                @endif
                @if (filled($contact['website']))
                    <a href="{{ $contact['website'] }}" target="_blank" rel="noopener" class="grid size-10 place-items-center rounded-sm text-[#216E39] hover:bg-[#F8F4E7] focus-visible:outline-2 focus-visible:outline-[#216E39]" aria-label="Division website (opens in a new tab)" data-tooltip="Website" data-tooltip-below><x-orchid-icon path="bs.globe" class="size-5" /></a>
                @endif
                @if (filled($contact['email']))
                    <a href="mailto:{{ $contact['email'] }}" class="grid size-10 place-items-center rounded-sm text-[#216E39] hover:bg-[#F8F4E7] focus-visible:outline-2 focus-visible:outline-[#216E39]" aria-label="Email {{ $contact['email'] }}" data-tooltip="Email" data-tooltip-below><x-orchid-icon path="bs.envelope" class="size-5" /></a>
                @endif
            </nav>
        </div>
    </header>

    <main class="flex-1">
        <section class="bg-[#216E39] text-white">
            <div class="mx-auto w-full max-w-6xl px-5 py-6 sm:px-8 sm:py-8">
                {{-- Visually hidden: the search box already says what the page does, but screen readers need a page heading. --}}
                <h1 class="sr-only">Track a document</h1>

                <div class="grid gap-5 lg:grid-cols-[minmax(0,48rem)_auto] lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <form method="GET" action="{{ route('tracking.index') }}" class="flex max-w-3xl flex-col gap-3 sm:flex-row" role="search">
                            <div class="min-w-0 grow">
                                <label for="tracking" class="sr-only">Tracking number</label>
                                <div class="flex min-h-12 items-center gap-3 rounded-sm border border-white/40 bg-white px-4 text-slate-700 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-white">
                                    <x-orchid-icon path="bs.search" class="size-5 shrink-0 text-slate-600" />
                                    <input
                                        id="tracking"
                                        name="tracking"
                                        type="search"
                                        value="{{ $trackingNumber }}"
                                        maxlength="32"
                                        autocomplete="off"
                                        spellcheck="false"
                                        placeholder="Enter your tracking number"
                                        class="h-12 min-w-0 grow border-0 bg-transparent text-base font-medium uppercase text-slate-900 outline-none placeholder:font-normal placeholder:normal-case placeholder:text-slate-500"
                                    >
                                </div>
                            </div>
                            <button type="submit" class="inline-flex min-h-12 items-center justify-center gap-2 rounded-sm border-2 border-[#F2C230] bg-[#F2C230] px-6 text-base font-semibold text-slate-900 hover:bg-[#D9A91C] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                                Track document
                                <x-orchid-icon path="bs.arrow-right" class="size-4" />
                            </button>
                        </form>
                        @if ($trackingError)
                            <p class="mt-3 flex items-center gap-2 text-sm font-medium text-yellow-100" role="alert">
                                <x-orchid-icon path="bs.exclamation-circle" class="size-4 shrink-0" />
                                {{ $trackingError }}
                            </p>
                        @endif
                    </div>
                    <div class="border-t border-white/25 pt-5 lg:border-0 lg:pt-0">
                        <a href="{{ route('submission.create') }}" data-open-submission class="inline-flex min-h-12 w-full shrink-0 items-center justify-center gap-2 rounded-sm border-2 border-white bg-white px-6 text-base font-semibold text-[#216E39] no-underline hover:bg-[#F8F4E7] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white lg:w-auto">
                            <x-orchid-icon path="bs.send" class="size-4" /> Send a document
                        </a>
                    </div>
                </div>
            </div>
        </section>

        @unless ($searched && ! $trackingError)
            {{-- Landing photos: two gently tilted rows of large photos that drift in opposite directions (app.css .dts-photo-marquee). Photos
                 go in public/images/landing/ (.jpg or .webp, landscape about 4:3, about 1200 px wide); a placeholder card
                 shows for each one not added yet. --}}
            @php
                $landingPhotos = collect([
                    ['file' => 'office', 'caption' => 'SDO Nueva Vizcaya division office'],
                    ['file' => 'records', 'caption' => 'Records Unit counter'],
                    ['file' => 'receiving', 'caption' => 'Receiving documents'],
                    ['file' => 'lobby', 'caption' => 'Waiting area'],
                    ['file' => 'offices', 'caption' => 'Division offices'],
                    ['file' => 'staff', 'caption' => 'Our staff'],
                    ['file' => 'schools', 'caption' => 'Serving the schools of Nueva Vizcaya'],
                    ['file' => 'events', 'caption' => 'Division events'],
                ])->map(function (array $photo): array {
                    $file = collect(['jpg', 'webp', 'png'])->map(fn (string $extension): string => "images/landing/{$photo['file']}.{$extension}")
                        ->first(fn (string $path): bool => file_exists(public_path($path)));

                    return [...$photo, 'url' => $file ? asset($file) : null];
                });
                // Two rows: the second runs the other way and starts elsewhere, so the same photos do not line up.
                $photoRows = [$landingPhotos->all(), array_reverse([...$landingPhotos->slice(4), ...$landingPhotos->take(4)])];
            @endphp
            <section class="overflow-hidden" aria-labelledby="photos-heading">
                <div class="mx-auto w-full max-w-6xl px-5 pt-5 sm:px-8 sm:pt-6">
                    <p class="text-sm font-semibold uppercase tracking-wide text-[#86562D]">Our office</p>
                    <h2 id="photos-heading" class="mt-1 text-2xl font-semibold text-slate-900 sm:text-3xl">Serving the schools of Nueva Vizcaya</h2>
                    <p class="mt-2 max-w-3xl text-base leading-7 text-slate-700">Send your document to the Records Unit and follow it from office to office. Enter the tracking number on your receipt or scan its QR code to see where it is now.</p>
                    @if ($landingPhotos->whereNotNull('url')->isNotEmpty())
                        <ul class="sr-only">@foreach ($landingPhotos->whereNotNull('url') as $photo)<li>{{ $photo['caption'] }}</li>@endforeach</ul>
                    @endif
                </div>
                {{-- Decorative and repeated for the loop, so hidden from screen readers (the captions are listed above). --}}
                <div class="dts-photo-marquee" aria-hidden="true">
                    <div class="dts-photo-marquee-tilt">
                        @foreach ($photoRows as $row)
                            <div class="dts-photo-row">
                                <div @class(['dts-photo-track', 'is-reverse' => $loop->even])>
                                    {{-- The row twice over, twice: the track slides by half its width and starts again seamlessly. --}}
                                    @foreach ([...$row, ...$row, ...$row, ...$row] as $photo)
                                        <figure class="dts-photo-card">
                                            @if ($photo['url'])
                                                <img src="{{ $photo['url'] }}" alt="" loading="lazy" decoding="async">
                                            @else
                                                <span class="dts-photo-placeholder"><x-orchid-icon path="bs.image" /></span>
                                            @endif
                                            <figcaption>{{ $photo['caption'] }}</figcaption>
                                        </figure>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="mx-auto w-full max-w-6xl px-5 pt-5 pb-8 sm:px-8 sm:pt-6 sm:pb-10" aria-label="How it works">
                <ol class="grid gap-3 sm:grid-cols-3">
                    @foreach ([
                        ['bs.send', 'Send', 'Fill in the form online or bring your document to the Records Unit.'],
                        ['bs.inbox', 'Records Unit receives', 'Records staff receive it and send it to the right office.'],
                        ['bs.search', 'Track', 'Use your tracking number to see each office, status and remarks.'],
                    ] as [$icon, $title, $text])
                        <li class="flex gap-3 rounded-sm border border-province-line-strong bg-white p-4">
                            <span class="grid size-10 shrink-0 place-items-center rounded-sm bg-[#216E39] text-white"><x-orchid-icon :path="$icon" class="size-5" /></span>
                            <span><strong class="block text-base text-slate-900">{{ $loop->iteration }}. {{ $title }}</strong><span class="text-sm leading-6 text-slate-600">{{ $text }}</span></span>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endunless

        @if ($searched && ! $trackingError)
        <p class="sr-only" role="status" data-tracking-announcer></p>
        <section class="mx-auto w-full max-w-[90rem] px-5 py-6 sm:px-8 sm:py-8" data-tracking-results>
            @if (session('submitted'))
                <p class="mb-6 rounded-sm border border-emerald-300 bg-emerald-50 px-5 py-4 font-medium text-emerald-900" role="status">
                    Document submitted. Save the tracking number below to follow its progress.
                </p>
            @endif
            @if ($document)
                @php
                    $finishedOfficeCount = $assignedOffices->whereIn('status', \App\Models\DocumentMovement::FINISHED_STATUSES)->count();
                    $allOfficesFinished = $assignedOffices->isNotEmpty() && $finishedOfficeCount === $assignedOffices->count();
                    $displayStatus = $assignedOffices->count() > 1
                        ? ($allOfficesFinished ? 'All offices finished' : 'In progress')
                        : ($assignedOffices->first()['status'] ?? $document->status);
                    $statusMeanings = [
                        ...\App\Models\DocumentMovement::STATUS_MEANINGS,
                        \App\Models\Document::ROUTE_WAITING_FOR_PAPER => 'This office receives the document/s after you bring them to the Records Unit.',
                        // Same wording as the status guide.
                        \App\Models\DocumentMovement::STATUS_FORWARDED => 'The document was sent on to the next office. It waits there until that office receives it.',
                        \App\Models\DocumentMovement::STATUS_RELEASED => 'The Records Unit released the document. Processing is finished.',
                    ];
                    $routeStatuses = $routeOffices->pluck('status')->unique()->filter(fn (string $status): bool => array_key_exists($status, $statusMeanings));
                @endphp

                {{-- Wide screens: the status card sets the row height; the movement card grows with its content up to that height. --}}
                <div class="grid gap-6 lg:grid-cols-2">
                    <section class="min-w-0 rounded-sm border border-province-line bg-white p-5 shadow-sm sm:p-6 lg:self-start" aria-labelledby="document-summary-heading">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold uppercase text-[#216E39]">Current status</p>
                                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-2">
                                    <h2 id="document-summary-heading" class="break-all text-xl font-semibold text-slate-900 sm:text-2xl">{{ $document->tracking_number }}</h2>
                                    @include('orchid.partials.copy-button', ['text' => $document->tracking_number])
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                @include('partials.public-status-badge', ['status' => $displayStatus, 'class' => 'dts-public-status min-h-11 px-3'])
                                {{-- Updates the results in place (resources/js/tracking-refresh.js); without JavaScript the link reloads the page. --}}
                                <a href="{{ route('tracking.index', array_filter(['tracking' => $trackingNumber, 'office' => $selectedOffice])) }}" data-tracking-refresh class="dts-tracking-refresh inline-flex size-11 shrink-0 items-center justify-center rounded-sm border border-[#216E39] bg-white text-[#216E39] no-underline hover:bg-[#216E39]/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#216E39]" title="Refresh status" aria-label="Refresh status">
                                    <x-orchid-icon path="bs.arrow-clockwise" class="size-5" />
                                </a>
                            </div>
                        </div>
                        <p class="mt-2 text-sm text-slate-600">Last checked {{ now('Asia/Manila')->format('M d, Y · g:i A') }}</p>
                        {{-- The QR code sits with the tracking number at the top, where clients look for it first. --}}
                        @if ($qrCodeDataUri)
                            <div class="mt-4 flex items-center gap-4 rounded-sm border border-[#216E39]/25 bg-[#F8F4E7] p-3">
                                <img src="{{ $qrCodeDataUri }}" alt="QR code for {{ $document->tracking_number }}" width="176" height="176" class="size-36 shrink-0 rounded-sm bg-white p-1.5 sm:size-44">
                                <div>
                                    <strong class="text-base font-semibold text-slate-900">Save your tracking QR code</strong>
                                    <p class="mt-1 text-sm text-slate-600">Take a picture or screenshot, or print the label and stick it on your copy. Scan it later to open this tracking page.</p>
                                    <a href="{{ route('tracking.label', $document->tracking_number) }}" class="mt-3 inline-flex min-h-11 items-center gap-2 rounded-sm border border-[#216E39] bg-white px-3 text-sm font-semibold text-[#216E39] no-underline hover:bg-[#EEF5EC] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#216E39]"><x-orchid-icon path="bs.printer" class="size-4" /> Print label</a>
                                    {{-- Print receipt is hidden while the office checks whether clients need it; the receipt page (tracking.receipt) stays.
                                         To bring it back, restore this link:
                                    <a href="{{ route('tracking.receipt', $document->tracking_number) }}" class="mt-3 inline-flex min-h-10 items-center gap-2 rounded-sm border border-[#216E39] bg-white px-3 text-sm font-semibold text-[#216E39] no-underline hover:bg-[#EEF5EC] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#216E39]"><x-orchid-icon path="bs.printer" class="size-4" /> Print receipt</a> --}}
                                </div>
                            </div>
                        @endif

                        <div class="grid gap-4 border-t border-province-line pt-4">
                            <h3 class="text-lg font-semibold leading-7 text-slate-900 [overflow-wrap:anywhere]">{{ $document->client_submission_key ? 'Client document' : $document->subject }}</h3>
                            <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                                @php
                                    $officeCardsFilterTimeline = $timelineOffices->count() > 1;
                                @endphp
                                <div class="sm:col-span-2">
                                    <dt class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-sm font-medium text-slate-600">
                                        <span>{{ $routeOffices->count() > 1 ? 'Assigned offices' : 'Receiving unit' }}</span>
                                        @if ($assignedOffices->count() > 1)
                                            <span class="font-semibold text-slate-700">{{ $finishedOfficeCount }} of {{ $assignedOffices->count() }} offices finished</span>
                                        @endif
                                    </dt>
                                    <dd class="mt-2">
                                        @if ($assignedOffices->count() > 1)
                                            <div class="mb-3 h-2 overflow-hidden rounded-full bg-[#E8F1E6]" role="progressbar" aria-label="Offices finished" aria-valuemin="0" aria-valuemax="{{ $assignedOffices->count() }}" aria-valuenow="{{ $finishedOfficeCount }}">
                                                <div class="h-full rounded-full bg-[#216E39]" style="width: {{ round($finishedOfficeCount / $assignedOffices->count() * 100) }}%"></div>
                                            </div>
                                        @endif
                                        <ul class="grid gap-2 sm:grid-cols-2">
                                            @foreach ($routeOffices as $assignment)
                                                @php
                                                    $routeLinks = $officeCardsFilterTimeline;
                                                    $routeState = match (true) {
                                                        $assignment['pending'] => 'waiting',
                                                        in_array($assignment['status'], \App\Models\DocumentMovement::FINISHED_STATUSES, true) => 'done',
                                                        $assignment['status'] === \App\Models\DocumentMovement::STATUS_ON_HOLD => 'paused',
                                                        default => 'active',
                                                    };
                                                @endphp
                                                <li class="dts-route-office flex rounded-sm border border-province-line bg-white" data-route-state="{{ $routeState }}" data-route-office="{{ $assignment['office'] }}">
                                                    @if ($routeLinks)
                                                        <a href="{{ route('tracking.index', ['tracking' => $trackingNumber, 'office' => $assignment['office']]) }}#movement-heading" class="dts-route-link flex w-full items-center gap-3 px-3 py-2 text-inherit no-underline" data-timeline-office="{{ $assignment['office'] }}"@if ($selectedOffice === $assignment['office']) aria-current="true"@endif>
                                                    @else
                                                        <div class="flex w-full items-center gap-3 px-3 py-2">
                                                    @endif
                                                    <span class="dts-route-state" aria-hidden="true">
                                                        @if ($routeState === 'done')
                                                            <x-orchid-icon path="bs.check-lg" />
                                                        @elseif ($routeState === 'paused')
                                                            <x-orchid-icon path="bs.pause-fill" />
                                                        @endif
                                                    </span>
                                                    <span class="min-w-0 grow">
                                                        <span class="block text-base font-semibold text-slate-800">{{ $assignment['office'] }}</span>
                                                        <span class="block text-sm text-slate-600"><span class="font-semibold uppercase tracking-wide">{{ \App\Support\StatusLabel::for($assignment['status']) }}</span>@if ($pendingKind = \App\Support\StatusLabel::description($assignment['status']))<span class="text-slate-600"> · {{ $pendingKind }}</span>@endif<span class="sr-only">{{ match ($routeState) { 'done' => ', finished', 'paused' => ', paused', 'waiting' => ', not started yet', default => ', in progress' } }}</span></span>
                                                    </span>
                                                    @if ($routeLinks)
                                                        <span class="sr-only">, show history</span>
                                                        </a>
                                                    @else
                                                        </div>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                        @if ($officeCardsFilterTimeline)
                                            <p class="mt-2 text-sm text-slate-600">Select an office to see its history.</p>
                                        @endif
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-slate-600">Document type</dt>
                                    <dd class="mt-1 text-base font-semibold text-slate-800">{{ $document->document_type }}</dd>
                                </div>
                                {{-- Client submissions keep the sender private, as they do the subject. --}}
                                @if (filled($document->sender) && ! $document->client_submission_key)
                                    <div>
                                        <dt class="text-sm font-medium text-slate-600">Sender</dt>
                                        <dd class="mt-1 text-base font-semibold text-slate-800 [overflow-wrap:anywhere]">{{ $document->sender }}</dd>
                                    </div>
                                @endif
                                <div>
                                    <dt class="text-sm font-medium text-slate-600">{{ $document->client_submission_key ? 'Date submitted' : 'Date received' }}</dt>
                                    <dd class="mt-1 text-base font-semibold text-slate-800">{{ ($document->client_submission_key ? $document->created_at : $document->received_at)?->format('M d, Y') ?? 'Not received yet' }}</dd>
                                </div>
                            </dl>
                        </div>
                    </section>

                    {{-- The history is as tall as its content, up to nearly the window height on a computer (not just the height of
                         the status card), so more steps can be read at once before its list scrolls. --}}
                    <div class="min-w-0">
                    <section class="flex min-w-0 flex-col rounded-sm border border-province-line bg-white p-5 shadow-sm sm:p-6" aria-labelledby="movement-heading">
                        <div class="border-b border-province-line-strong pb-3">
                            <p class="text-sm font-semibold uppercase text-[#216E39]">Routing history</p>
                            <h2 id="movement-heading" class="mt-1 text-2xl font-semibold text-slate-900">Document movement</h2>
                        </div>
                        @include('partials.public-status-guide', ['class' => 'mt-4'])
                        @php
                            // Movements and office remarks, newest first; the registration step stays last.
                            $timelineEvents = $document->movements
                                ->map(fn (\App\Models\DocumentMovement $movement): array => [
                                    'movement' => $movement,
                                    'at' => $movement->created_at,
                                    'visible' => $selectedOffice === '' || $movement->from_office === $selectedOffice || $movement->to_office === $selectedOffice,
                                ])
                                ->concat($publicRemarks->map(fn (array $remark): array => [
                                    'movement' => null,
                                    'remark' => $remark,
                                    'at' => $remark['at'],
                                    'visible' => $selectedOffice === '' || $remark['office'] === $selectedOffice,
                                ]))
                                ->sortBy(fn (array $event): int => $event['at']->getTimestamp())
                                ->reverse()
                                ->values()
                                ->push(['movement' => null, 'at' => $document->created_at, 'visible' => $showRegistrationEvent])
                                ->map(fn (array $event, int $index): array => [...$event, 'index' => $index]);
                            $latestEventIndex = $timelineEvents->first(fn (array $event): bool => $event['visible'])['index'] ?? null;
                            $timelineDays = $timelineEvents->groupBy(fn (array $event): string => $event['at']->copy()->timezone('Asia/Manila')->toDateString());
                            $timelineToday = now('Asia/Manila');
                            $timelineIcons = ['moved' => 'bs.arrow-right', 'received' => 'bs.inbox', 'done' => 'bs.check-lg', 'hold' => 'bs.pause-fill', 'returned' => 'bs.arrow-return-left', 'cancelled' => 'bs.slash-circle', 'note' => 'bs.dot', 'remark' => 'bs.chat-left-text', 'signed' => 'bs.pen'];
                        @endphp
                        @if ($timelineOffices->count() > 1)
                            <form id="timeline-filter" method="GET" action="{{ route('tracking.index') }}#movement-heading" class="mt-4 flex flex-wrap items-end gap-3" data-registration-offices='@json($registrationOffices?->values()->all() ?? [])' data-initial-office="{{ $initialOffice }}">
                                <input type="hidden" name="tracking" value="{{ $trackingNumber }}">
                                <div class="min-w-48 grow">
                                    <label for="timeline-office" class="block text-sm font-semibold text-slate-800">View timeline by office</label>
                                    <select id="timeline-office" name="office" class="mt-2 w-full">
                                        <option value="">All offices (combined history)</option>
                                        @foreach ($timelineOffices as $office)
                                            <option value="{{ $office }}" @selected($selectedOffice === $office)>{{ $office }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-sm bg-[#216E39] px-4 text-sm font-semibold text-white hover:bg-[#18542B] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#216E39]">View timeline</button>
                            </form>
                        @endif

                        {{-- Inner padding on the left and top gives the step dots room for their pop-in and ring (timeline-reveal.js),
                             which the scrolling list would otherwise cut off. --}}
                        <div class="mt-4 max-h-[40rem] min-h-0 overflow-y-auto overscroll-contain pt-1 pr-2 pl-3 lg:max-h-[calc(100vh-9rem)]" tabindex="0" aria-label="Document movement history">
                            @foreach ($timelineDays as $day => $events)
                                @php($timelineDate = \Illuminate\Support\Carbon::parse($day, 'Asia/Manila'))
                                <section data-timeline-day @class(['hidden' => $events->doesntContain('visible', true)])>
                                    <h3 class="sticky top-0 z-10 bg-white py-2 text-sm font-semibold uppercase tracking-wide text-slate-600">{{ $timelineDate->isSameDay($timelineToday) ? 'Today' : ($timelineDate->isSameDay($timelineToday->copy()->subDay()) ? 'Yesterday' : $timelineDate->format('F j, Y')) }}</h3>
                                    <ol class="dts-step-timeline">
                                        @foreach ($events as $event)
                                            @php($movement = $event['movement'])
                                            @if (isset($event['remark']))
                                                @php($remark = $event['remark'])
                                                {{-- A remark an office added (staff "Add remark"), or the client signing for a released document;
                                                     filtered with that office's steps. --}}
                                                @php($isSigning = $remark['kind'] === 'signed')
                                                <li style="--i: {{ $event['index'] }}" data-kind="{{ $isSigning ? 'done' : 'remark' }}" data-movement data-from-office="{{ $remark['office'] }}" data-to-office="{{ $remark['office'] }}" class="dts-public-timeline-item {{ $event['visible'] ? 'grid' : 'hidden' }} grid-cols-[32px_minmax(0,1fr)] gap-3 pb-4">
                                                    <span class="dts-timeline-dot" aria-hidden="true"><x-orchid-icon :path="$timelineIcons[$isSigning ? 'signed' : 'remark']" /></span>
                                                    <div class="min-w-0 pt-1">
                                                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                                            <strong class="text-base font-semibold text-slate-900">{{ $isSigning ? ($remark['by'] ?: 'The recipient').' signed for the document/s' : ($remark['office'] ?: 'The office').' added a remark' }} <span class="dts-timeline-latest" data-timeline-latest @if ($event['index'] !== $latestEventIndex) hidden @endif>Latest</span></strong>
                                                        </div>
                                                        @unless ($isSigning)
                                                            {{-- Who wrote it comes first, as "Name: remark". --}}
                                                            <p class="mt-2 whitespace-pre-line rounded-sm border border-province-line-strong bg-[#F8F4E7]/60 px-3 py-2 text-sm leading-6 text-slate-800 [overflow-wrap:anywhere]"><strong class="font-semibold text-[#216E39]">{{ $remark['by'] ?: ($remark['office'] ?: 'Staff') }}:</strong> {{ $remark['remark'] }}</p>
                                                        @endunless
                                                        <dl class="dts-public-step-details mt-2 grid grid-cols-[6.5rem_minmax(0,1fr)] sm:grid-cols-[9rem_minmax(0,1fr)] [overflow-wrap:anywhere] gap-x-3 gap-y-1 text-sm">
                                                            <dt class="font-medium text-slate-600">Date / time</dt><dd class="text-slate-800"><time datetime="{{ $remark['at']->toIso8601String() }}">{{ $remark['at']->timezone('Asia/Manila')->format('M d, Y') }}<span class="mx-2 text-slate-400" aria-hidden="true">|</span>{{ $remark['at']->timezone('Asia/Manila')->format('g:i A') }}</time></dd>
                                                            @if ($isSigning)
                                                                <dt class="font-medium text-slate-600">Status</dt><dd class="text-slate-800">Signature received</dd>
                                                            @endif
                                                            {{-- A remark already names its writer ("Name: remark"), so only a signing lists who signed. --}}
                                                            @if ($isSigning && filled($remark['by']))
                                                                <dt class="font-medium text-slate-600">Signed by</dt><dd class="text-slate-800">{{ $remark['by'] }}</dd>
                                                            @endif
                                                        </dl>
                                                    </div>
                                                </li>
                                            @elseif ($movement === null)
                                                <li id="registration-event" class="dts-public-timeline-item {{ $event['visible'] ? 'grid' : 'hidden' }} grid-cols-[32px_minmax(0,1fr)] gap-3 pb-4" style="--i: {{ $event['index'] }}" data-kind="registered">
                                                    <span class="dts-timeline-dot" aria-hidden="true"><x-orchid-icon path="bs.file-earmark-check" /></span>
                                                    <div class="min-w-0 pt-1">
                                                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                                            <strong class="text-base font-semibold text-slate-900">{{ $document->client_submission_key ? 'Document submitted' : 'Document registered' }} <span class="dts-timeline-latest" data-timeline-latest @if ($event['index'] !== $latestEventIndex) hidden @endif>Latest</span></strong>
                                                        </div>
                                                        <p id="registration-office" class="mt-1 text-sm text-slate-600">{{ $document->status === \App\Models\DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT ? 'Awaiting document/s at ' : 'Assigned to ' }}{{ $selectedOffice ?: $initialOffice }}</p>
                                                        <dl class="dts-public-step-details mt-2 grid grid-cols-[6.5rem_minmax(0,1fr)] sm:grid-cols-[9rem_minmax(0,1fr)] [overflow-wrap:anywhere] gap-x-3 gap-y-1 text-sm">
                                                            <dt class="font-medium text-slate-600">Date / time</dt><dd class="text-slate-800"><time datetime="{{ $document->created_at->toIso8601String() }}">{{ $document->created_at->timezone('Asia/Manila')->format('M d, Y') }}<span class="mx-2 text-slate-400" aria-hidden="true">|</span>{{ $document->created_at->timezone('Asia/Manila')->format('g:i A') }}</time></dd>
                                                        </dl>
                                                        @php($clientInstructions = $document->clientInstructions())
                                                        @if ($clientInstructions)
                                                            {{-- What the client typed when submitting (stored on the first "sent" step, shown here instead). --}}
                                                            <div class="dts-public-remarks mt-2 rounded-sm border border-province-line-strong bg-[#F8F4E7]/60 px-3 py-2">
                                                                <p class="flex items-center gap-2 text-sm font-semibold text-[#216E39]"><x-orchid-icon path="bs.chat-left-text" class="size-4 shrink-0" /> Instructions for Records</p>
                                                                <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-800 [overflow-wrap:anywhere]">{{ $clientInstructions }}</p>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </li>
                                            @else
                                                <li style="--i: {{ $event['index'] }}" data-kind="{{ $movement->publicKind() }}" data-movement data-from-office="{{ $movement->from_office }}" data-to-office="{{ $movement->to_office }}" class="dts-public-timeline-item {{ $event['visible'] ? 'grid' : 'hidden' }} grid-cols-[32px_minmax(0,1fr)] gap-3 pb-4">
                                                    <span class="dts-timeline-dot" aria-hidden="true"><x-orchid-icon :path="$timelineIcons[$movement->publicKind()]" /></span>
                                                    <div class="min-w-0 pt-1">
                                                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                                            <strong class="text-base font-semibold text-slate-900">{{ $movement->publicSummary() }} <span class="dts-timeline-latest" data-timeline-latest @if ($event['index'] !== $latestEventIndex) hidden @endif>Latest</span></strong>
                                                        </div>
                                                        {{-- Originating office, destination office, status, remarks, date and time, and who processed it. --}}
                                                        <dl class="dts-public-step-details mt-2 grid grid-cols-[6.5rem_minmax(0,1fr)] sm:grid-cols-[9rem_minmax(0,1fr)] [overflow-wrap:anywhere] gap-x-3 gap-y-1 text-sm">
                                                            {{-- A step an office takes on its own copy (received, completed…) is already named in the title. --}}
                                                            @if (filled($movement->from_office) && $movement->from_office !== $movement->to_office)
                                                                <dt class="font-medium text-slate-600">From</dt><dd class="text-slate-800">{{ $movement->from_office }}</dd>
                                                            @endif
                                                            @if (filled($movement->to_office) && $movement->to_office !== $movement->from_office)
                                                                <dt class="font-medium text-slate-600">Office destination</dt><dd class="text-slate-800">{{ $movement->to_office }}</dd>
                                                            @endif
                                                            @if (filled($movement->to_status))
                                                                <dt class="self-center font-medium text-slate-600">Status</dt><dd>@include('partials.public-status-badge', ['status' => $movement->to_status])</dd>
                                                            @endif
                                                            @if ($document->client_submission_key && $movement->action === \App\Models\DocumentMovement::ACTION_SENT && $movement->from_office === null && filled($document->delivery_method))
                                                                <dt class="font-medium text-slate-600">Mode of submission</dt><dd class="text-slate-800">{{ $document->delivery_method }}</dd>
                                                            @endif
                                                            <dt class="font-medium text-slate-600">Date / time</dt><dd class="text-slate-800"><time datetime="{{ $movement->created_at->toIso8601String() }}">{{ $movement->created_at->timezone('Asia/Manila')->format('M d, Y') }}<span class="mx-2 text-slate-400" aria-hidden="true">|</span>{{ $movement->created_at->timezone('Asia/Manila')->format('g:i A') }}</time></dd>
                                                            @if ($movement->actor?->name)
                                                                <dt class="font-medium text-slate-600">Processed by</dt><dd class="text-slate-800">{{ $movement->actor->name }}</dd>
                                                            @endif
                                                            @if ($movement->released_to)
                                                                <dt class="font-medium text-slate-600">Released to</dt><dd class="text-slate-800">{{ $movement->released_to }}</dd>
                                                            @endif
                                                            @if ($movement->action === \App\Models\DocumentMovement::ACTION_RELEASED && $movement->has_recipient_signature)
                                                                <dt class="font-medium text-slate-600">Recipient signature</dt><dd class="text-slate-800">Signature received</dd>
                                                            @endif
                                                        </dl>
                                                        @if (filled($movement->remarks) && ! $document->isClientSubmissionStep($movement))
                                                            {{-- Shown in full, not folded away: the remarks are often the instruction the reader needs. --}}
                                                            <div class="dts-public-remarks mt-2 rounded-sm border border-province-line-strong bg-[#F8F4E7]/60 px-3 py-2">
                                                                <p class="flex items-center gap-2 text-sm font-semibold text-[#216E39]"><x-orchid-icon path="bs.chat-left-text" class="size-4 shrink-0" /> Remarks</p>
                                                                <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-800 [overflow-wrap:anywhere]">{{ $movement->remarks }}</p>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </li>
                                            @endif
                                        @endforeach
                                    </ol>
                                </section>
                            @endforeach
                        </div>
                        <p id="timeline-empty" class="{{ $showEmptyTimeline ? '' : 'hidden' }} mt-5 rounded-sm border border-province-line bg-white px-4 py-3 text-sm text-slate-600">No movements recorded for this office yet.</p>
                    </section>
                    </div>
                </div>
            @else
                <div class="flex min-h-48 items-center gap-4 border-y border-province-line bg-white px-5 py-8 sm:px-8">
                    <span class="grid size-12 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-600">
                        <x-orchid-icon path="bs.file-earmark-x" class="size-6" />
                    </span>
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">No matching document</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">Check the tracking number and try again.</p>
                    </div>
                </div>
            @endif
        </section>
        @endif

    </main>

    @include('client-document-submission', ['embedded' => true])

    <footer class="border-t border-province-line bg-white">
        {{-- "Contact us" in the header jumps here. --}}
        <section id="contact" class="mx-auto grid w-full max-w-6xl scroll-mt-4 gap-4 px-5 py-6 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-start sm:px-8" aria-labelledby="contact-heading">
            {{-- SDO Nueva Vizcaya seal, left of the contact details. --}}
            <img src="{{ asset('images/SDO_NV.png') }}" alt="Schools Division Office of Nueva Vizcaya seal" width="64" height="64" decoding="async" class="size-14 shrink-0 sm:size-16 sm:self-center" data-footer-logo>
            <div>
                <h2 id="contact-heading" class="text-base font-semibold text-slate-900">Contact us</h2>
                <p class="mt-1 text-sm font-medium text-slate-700">{{ $contact['office'] }}</p>
                <ul class="mt-2 grid gap-1.5 text-sm text-slate-700">
                    @if (filled($contact['address']))
                        <li class="flex gap-2"><x-orchid-icon path="bs.geo-alt" class="mt-0.5 size-4 shrink-0 text-[#216E39]" /> {{ $contact['address'] }}</li>
                    @endif
                    @if (filled($contact['phone']))
                        <li class="flex gap-2"><x-orchid-icon path="bs.telephone" class="mt-1.5 size-4 shrink-0 text-[#216E39]" />
                            <span class="flex flex-wrap gap-x-3 gap-y-1">@foreach (array_filter(array_map('trim', explode(',', $contact['phone']))) as $phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" class="inline-block py-1 text-inherit">{{ $phone }}</a>@endforeach</span>
                        </li>
                    @endif
                    @if (filled($contact['email']))
                        <li class="flex gap-2"><x-orchid-icon path="bs.envelope" class="mt-1.5 size-4 shrink-0 text-[#216E39]" /> <a href="mailto:{{ $contact['email'] }}" class="inline-block py-1 text-inherit">{{ $contact['email'] }}</a></li>
                    @endif
                    @if (filled($contact['hours']))
                        <li class="flex gap-2"><x-orchid-icon path="bs.clock" class="mt-0.5 size-4 shrink-0 text-[#216E39]" /> {{ $contact['hours'] }}</li>
                    @endif
                    @if (blank($contact['address']) && blank($contact['phone']) && blank($contact['email']))
                        <li class="flex gap-2"><x-orchid-icon path="bs.geo-alt" class="mt-0.5 size-4 shrink-0 text-[#216E39]" /> Visit the Records Unit counter at the division office.</li>
                    @endif
                </ul>
            </div>
            {{-- One footer: seal and contact details on the left; the system line on the right. The Facebook and website
                 links stay in the header only. --}}
            <div class="grid content-end gap-3 self-stretch sm:justify-items-end">
                <p class="text-sm text-slate-600">Department of Education &middot; Document Tracking System &middot; {{ now()->timezone('Asia/Manila')->year }}</p>
            </div>
        </section>
    </footer>
    @if ($document)
        {{-- Office timeline filter. Elements are looked up on each use and the office data sits on the form, so the
             results can be swapped in place by the Refresh button (resources/js/tracking-refresh.js). --}}
        <script nonce="{{ Vite::cspNonce() }}">
            function setVisible(element, visible) {
                element.classList.toggle('hidden', !visible);
                element.classList.toggle('grid', visible);
            }

            function showOfficeTimeline(office) {
                const filter = document.getElementById('timeline-filter');
                if (!filter) return;
                const registrationEvent = document.getElementById('registration-event');
                const registrationOffice = document.getElementById('registration-office');
                const registrationOffices = JSON.parse(filter.dataset.registrationOffices || '[]');

                document.getElementById('timeline-office').value = office;
                document.querySelectorAll('.dts-route-link[data-timeline-office]').forEach((card) => {
                    if (card.dataset.timelineOffice === office) card.setAttribute('aria-current', 'true');
                    else card.removeAttribute('aria-current');
                });

                setVisible(registrationEvent, office === '' || registrationOffices.includes(office));
                registrationOffice.textContent = registrationOffice.textContent.replace(/(Assigned to |Awaiting document\/s at ).*/, '$1') + (office || filter.dataset.initialOffice);

                document.querySelectorAll('[data-movement]').forEach((movement) => {
                    setVisible(movement, office === '' || movement.dataset.fromOffice === office || movement.dataset.toOffice === office);
                });

                document.querySelectorAll('[data-timeline-day]').forEach((day) => {
                    day.classList.toggle('hidden', !day.querySelector('li.grid'));
                });

                const visibleItems = document.querySelectorAll('[data-timeline-day] li.grid');
                document.querySelectorAll('[data-timeline-latest]').forEach((label) => { label.hidden = true; });
                const latest = visibleItems[0]?.querySelector('[data-timeline-latest]');
                if (latest) latest.hidden = false;
                document.getElementById('timeline-empty').classList.toggle('hidden', visibleItems.length > 0);

                const url = new URL(window.location.href);
                if (office) url.searchParams.set('office', office);
                else url.searchParams.delete('office');
                window.history.replaceState(null, '', url);
            }

            // The "View timeline" button is for pages without JavaScript; here the list filters as soon as it changes.
            function prepareTimelineFilter() {
                const button = document.querySelector('#timeline-filter button[type="submit"]');
                if (button) button.hidden = true;
            }

            prepareTimelineFilter();
            document.addEventListener('dts:tracking-updated', prepareTimelineFilter);
            document.addEventListener('change', (event) => {
                if (event.target.id === 'timeline-office') showOfficeTimeline(event.target.value);
            });
            document.addEventListener('submit', (event) => {
                if (event.target.id !== 'timeline-filter') return;
                event.preventDefault();
                showOfficeTimeline(document.getElementById('timeline-office').value);
            });

            // Office cards filter the history too; tapping the selected card again shows all offices.
            document.addEventListener('click', (event) => {
                const card = event.target.closest('.dts-route-link[data-timeline-office]');
                if (!card) return;
                event.preventDefault();
                showOfficeTimeline(card.getAttribute('aria-current') === 'true' ? '' : card.dataset.timelineOffice);
                const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                document.getElementById('movement-heading').scrollIntoView({ block: 'nearest', behavior: reduceMotion ? 'auto' : 'smooth' });
            });
        </script>
    @endif
</body>
</html>
