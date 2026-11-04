@php($embedded = $embedded ?? false)
@if (! $embedded)
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Send a document | DocuTrackS</title>
    <link rel="icon" href="{{ asset('images/icon.png') }}" type="image/png">
    @fonts
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-white font-sans text-province-charcoal antialiased">
    <div class="fixed inset-x-0 top-0 grid h-1 grid-cols-[2fr_1fr_1fr]" aria-hidden="true">
        <span class="bg-province-green"></span>
        <span class="bg-province-gold"></span>
        <span class="bg-province-brown"></span>
    </div>

    <header class="border-b border-province-line bg-white">
        <div class="mx-auto flex min-h-20 w-full max-w-3xl items-center justify-between gap-4 px-5 py-4 sm:px-8">
            <a href="{{ route('tracking.index') }}" class="flex min-w-0 items-center gap-3 text-slate-900 no-underline">
                <img src="{{ asset('images/icon.png') }}" alt="" width="56" height="56" class="size-12 shrink-0 rounded-xl sm:size-14">
                <span class="min-w-0">
                    <strong class="block text-2xl font-bold leading-none tracking-tight text-province-charcoal sm:text-3xl">Docu<span class="text-province-brown">TrackS</span></strong>
                    <span class="block text-sm font-semibold leading-tight text-slate-600">SDO – Nueva Vizcaya</span>
                    <span class="block text-sm leading-tight text-slate-600">Document Tracking System</span>
                </span>
            </a>
            <a href="{{ route('tracking.index') }}" aria-label="Track a document" class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-2 text-sm font-semibold text-province-green hover:underline"><x-orchid-icon path="bs.search" class="size-5" /><span class="hidden sm:inline">Track a document</span></a>
        </div>
    </header>

    <main class="mx-auto w-full max-w-3xl px-5 py-10 sm:px-8">
        <h1 class="text-3xl font-semibold text-slate-900">Send a document</h1>
@else
    <dialog id="submission-dialog" class="m-auto max-h-[calc(100vh-2rem)] w-[min(48rem,calc(100%-2rem))] overflow-y-auto rounded-sm border border-province-line bg-white p-0 text-slate-800 shadow-2xl backdrop:bg-slate-950/60" aria-labelledby="submission-dialog-title">
        <div class="p-5 sm:p-8">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 id="submission-dialog-title" class="text-2xl font-semibold text-slate-900">Send a document</h2>
                </div>
                <button type="button" data-close-submission class="grid size-11 shrink-0 place-items-center rounded-sm border border-province-line-strong text-slate-600 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-province-green" aria-label="Close document form">
                    <x-orchid-icon path="bs.x-lg" class="size-4" />
                </button>
            </div>
@endif

        {{-- The same form was sent again after the Records Unit removed that submission. --}}
        @if (session('submission_notice'))
            <div class="mt-6 rounded-sm border border-amber-300 bg-amber-50 px-5 py-4 text-amber-950" role="status">
                {{ session('submission_notice') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mt-6 rounded-sm border border-red-300 bg-red-50 px-5 py-4 text-red-900" role="alert">
                <strong>Please check your submission.</strong>
                <ul class="mt-2 list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('submission.store') }}" enctype="multipart/form-data" class="mt-6 grid gap-6 sm:grid-cols-2 {{ $embedded ? '' : 'rounded-sm border border-province-line bg-white p-5 shadow-sm sm:p-8' }}">
            @csrf
            <input type="hidden" name="submission_key" value="{{ old('submission_key', $submissionKey) }}">
            <p class="text-sm text-slate-600 sm:col-span-2">Fields marked <span class="text-red-700">*</span> are required.</p>
            {{-- Honeypot: hidden from people (and screen readers); bots that fill in every field are turned away. --}}
            <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
                <label for="contact-reference">Leave this field empty</label>
                <input id="contact-reference" type="text" name="contact_reference" value="" tabindex="-1" autocomplete="off">
            </div>

            <div>
                <label for="client-name" class="block text-sm font-semibold text-slate-800">Your name <span class="text-red-700">*</span></label>
                <input id="client-name" name="name" value="{{ old('name') }}" required maxlength="180" autocomplete="name" class="mt-2 min-h-11 w-full rounded-sm border border-province-line-strong px-3 py-2 focus:border-province-green focus:outline-2 focus:outline-province-green">
            </div>
            <div>
                <label for="client-office" class="block text-sm font-semibold text-slate-800">Your school or office <span class="text-red-700">*</span></label>
                <div class="relative" data-combo>
                    <input id="client-office" name="office" data-searchable data-options="origin-units" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="client-office-choices" value="{{ old('office') }}" required maxlength="180" placeholder="Search or enter your school or office" autocomplete="off" class="mt-2 min-h-11 w-full rounded-sm border border-province-line-strong bg-white px-3 py-2 focus:border-province-green focus:outline-2 focus:outline-province-green">
                    <div id="client-office-choices" data-suggestions role="listbox" tabindex="-1" hidden class="focus:outline-none absolute inset-x-0 top-full z-30 mt-1 max-h-60 overflow-y-auto rounded-sm border border-province-line-strong bg-white py-1 shadow-lg"></div>
                </div>
                <datalist id="origin-units">
                    @foreach ($originOptions as $originOption)
                        <option value="{{ $originOption }}"></option>
                    @endforeach
                </datalist>
            </div>

            {{-- Optional: the sender gets an email each time the document is routed to an office. --}}
            <div class="sm:col-span-2">
                <label for="client-email" class="block text-sm font-semibold text-slate-800">Email for updates <span class="font-normal text-slate-600">(optional)</span></label>
                <input id="client-email" name="email" type="email" value="{{ old('email') }}" maxlength="255" autocomplete="email" inputmode="email" aria-describedby="client-email-hint @error('email') client-email-error @enderror" @error('email') aria-invalid="true" @enderror @class(['mt-2 min-h-11 w-full rounded-sm border px-3 py-2 focus:border-province-green focus:outline-2 focus:outline-province-green', 'border-province-line-strong' => ! $errors->has('email'), 'border-2 border-red-700' => $errors->has('email')])>
                <p id="client-email-hint" class="mt-2 text-sm text-slate-600">We will email you each time your document is routed to an office. If our email is not in your Inbox, check your Spam folder and mark it “Report not spam”.</p>
                @error('email')<p id="client-email-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror
            </div>

            <div class="sm:col-span-2">
                <label for="document-type" class="block text-sm font-semibold text-slate-800">Type of document <span class="text-red-700">*</span></label>
                <div class="relative" data-combo>
                    <input id="document-type" name="type" data-searchable data-options="document-types" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="document-type-choices" value="{{ old('type') }}" required placeholder="Search document types" autocomplete="off" class="mt-2 min-h-11 w-full rounded-sm border border-province-line-strong bg-white px-3 py-2 focus:border-province-green focus:outline-2 focus:outline-province-green">
                    <div id="document-type-choices" data-suggestions role="listbox" tabindex="-1" hidden class="focus:outline-none absolute inset-x-0 top-full z-30 mt-1 max-h-60 overflow-y-auto rounded-sm border border-province-line-strong bg-white py-1 shadow-lg"></div>
                </div>
                <datalist id="document-types">
                    @foreach ($documentTypes as $documentType)
                        <option value="{{ $documentType }}"></option>
                    @endforeach
                </datalist>
            </div>

            <div class="sm:col-span-2">
                <label for="document-title" class="block text-sm font-semibold text-slate-800">Document title <span class="text-red-700">*</span></label>
                <input id="document-title" name="title" value="{{ old('title') }}" required maxlength="255" autocomplete="off" class="mt-2 min-h-11 w-full rounded-sm border border-province-line-strong px-3 py-2 focus:border-province-green focus:outline-2 focus:outline-province-green">
            </div>

            {{-- How the paper copy reaches the Records Unit; Records sees it when they look up the submission. --}}
            <fieldset class="sm:col-span-2" @error('delivery_method') aria-describedby="delivery-method-error" @enderror>
                <legend class="block text-sm font-semibold text-slate-800">Mode of submission <span class="text-red-700">*</span></legend>
                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                    @foreach ([
                        'Hand carry' => 'You or someone you send brings it to the Records Unit.',
                        'Official channel' => 'It comes through official mail or your school or office messenger.',
                    ] as $deliveryMethod => $deliveryHint)
                        @continue(! in_array($deliveryMethod, \App\Models\Document::DELIVERY_METHODS, true))
                        <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-sm border border-province-line-strong bg-white p-3 has-[:checked]:border-province-green has-[:checked]:bg-province-green/5">
                            <input type="radio" name="delivery_method" value="{{ $deliveryMethod }}" required @checked(old('delivery_method') === $deliveryMethod) class="mt-0.5 size-5 shrink-0 accent-province-green">
                            <span><span class="block font-semibold text-slate-900">{{ $deliveryMethod }}</span><span class="block text-sm text-slate-600">{{ $deliveryHint }}</span></span>
                        </label>
                    @endforeach
                </div>
                @error('delivery_method')<p id="delivery-method-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror
            </fieldset>

            <div class="sm:col-span-2">
                <label for="client-remarks" class="block text-sm font-semibold text-slate-800">Instructions for Records <span class="font-normal text-slate-600">(optional)</span></label>
                <p id="client-remarks-hint" class="mt-1 text-sm text-slate-600">Enter destination offices or routing instructions.</p>
                <textarea id="client-remarks" name="client_remarks" rows="3" maxlength="1000" autocomplete="off" aria-describedby="client-remarks-hint @error('client_remarks') client-remarks-error @enderror" class="mt-2 w-full rounded-sm border border-province-line-strong px-3 py-2 focus:border-province-green focus:outline-2 focus:outline-province-green">{{ old('client_remarks') }}</textarea>
                @error('client_remarks')<p id="client-remarks-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror
            </div>

            @if (config('app.document_uploads_enabled'))
            <div class="sm:col-span-2">
                <label for="document-file" class="block text-sm font-semibold text-slate-800">Document file <span class="font-normal text-slate-600">(optional)</span></label>
                <input id="document-file" name="attachment" type="file" accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx,.xls,.csv" class="mt-2 block w-full rounded-sm border border-province-line-strong bg-white p-3 text-sm file:mr-4 file:rounded-sm file:border-0 file:bg-province-green/10 file:px-3 file:py-2 file:font-semibold file:text-province-green">
            </div>
            @endif

            {{-- Privacy notice and consent (Data Privacy Act of 2012). --}}
            <div class="sm:col-span-2 rounded-sm border border-province-line bg-slate-50 p-4">
                <h2 class="text-sm font-semibold text-slate-800">Privacy notice</h2>
                {{-- The notice itself lives in its own dialog (script below); without JavaScript this is a link to /privacy. --}}
                <a href="{{ route('privacy.notice') }}" data-open-privacy class="mt-1 inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-province-green underline underline-offset-2 hover:text-province-green-dark active:text-province-charcoal focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-province-green"><x-orchid-icon path="bs.shield-lock" class="size-4" /> Read the full privacy notice</a>
                <label class="mt-3 flex items-start gap-3 text-sm font-medium text-slate-800">
                    <input type="checkbox" name="privacy_consent" value="1" required @checked(old('privacy_consent')) aria-describedby="privacy-consent-error" class="size-6 shrink-0 accent-province-green">
                    <span>I have read the privacy notice and agree to the collection and use of my information for this document. <span class="text-red-700">*</span></span>
                </label>
                @error('privacy_consent')<p id="privacy-consent-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror
            </div>

            <div class="sm:col-span-2">
                <button type="submit" data-submit-button class="inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-sm bg-province-green px-6 py-3 font-semibold text-white hover:bg-province-green-dark active:bg-province-charcoal focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-province-green disabled:cursor-wait disabled:opacity-80">
                    <span data-submit-idle class="inline-flex items-center gap-2"><x-orchid-icon path="bs.send" class="size-4" /> Send document</span>
                    <span data-submit-busy hidden class="inline-flex items-center gap-2"><span class="size-5 motion-safe:animate-spin rounded-full border-[3px] border-white/40 border-t-white" aria-hidden="true"></span> Sending… please wait</span>
                </button>
            </div>

            {{-- Shown when sending takes longer than a moment (large files, slow connection). --}}
            <div data-submit-overlay hidden class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" role="status" aria-live="assertive">
                <div class="flex max-w-sm flex-col items-center gap-4 rounded-sm bg-white p-6 text-center shadow-xl">
                    <span class="size-12 motion-safe:animate-spin rounded-full border-4 border-province-green/25 border-t-province-green" aria-hidden="true"></span>
                    <p class="text-lg font-semibold text-slate-900">Sending your document…</p>
                    <p class="text-base text-slate-700">This can take a moment for large files or a slow connection. Please don't close or refresh this page.</p>
                </div>
            </div>
        </form>

        {{-- The full privacy notice. Inside the send-a-document dialog it opens on top of it, and closing it returns there. --}}
        <dialog id="privacy-dialog" data-privacy-dialog class="m-auto max-h-[calc(100dvh-2rem)] w-[min(44rem,calc(100%-2rem))] overflow-y-auto rounded-sm border border-province-line bg-white p-0 text-slate-800 shadow-2xl backdrop:bg-slate-950/60" aria-labelledby="privacy-dialog-title">
            <div class="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-province-line bg-white px-5 py-4 sm:px-8">
                <h2 id="privacy-dialog-title" class="text-2xl font-semibold text-slate-900">Privacy notice</h2>
                <button type="button" data-close-privacy class="grid size-11 shrink-0 place-items-center rounded-sm border border-province-line-strong text-slate-600 hover:bg-slate-50 active:bg-province-green-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-province-green" aria-label="Close privacy notice">
                    <x-orchid-icon path="bs.x-lg" class="size-4" />
                </button>
            </div>
            <div class="px-5 py-6 sm:px-8">
                @include('partials.privacy-notice', ['headingTag' => 'h3'])
            </div>
            <div class="border-t border-province-line px-5 py-4 sm:px-8">
                <button type="button" data-close-privacy class="inline-flex min-h-11 items-center justify-center rounded-sm border border-province-green bg-white px-5 text-sm font-semibold text-province-green hover:bg-province-cream active:bg-province-green-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-province-green">Back to the form</button>
            </div>
        </dialog>
@if (! $embedded)
    </main>
    <footer class="mx-auto w-full max-w-3xl px-5 pb-8 text-center sm:px-8">
        <p class="text-sm text-slate-600">Department of Education</p>
        <p class="text-sm text-slate-600">Document Tracking System, developed by the SDO ICT Unit</p>
    </footer>
@else
        </div>
    </dialog>
@endif
    <script nonce="{{ Vite::cspNonce() }}">
        const closeSuggestions = (combo) => {
            if (!combo) return;
            combo.querySelector('[data-suggestions]').hidden = true;
            const input = combo.querySelector('[data-searchable]');
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        };
        const showSuggestions = (input, all = false) => {
            const panel = input.closest('[data-combo]').querySelector('[data-suggestions]');
            const query = all ? '' : input.value.trim().toLocaleLowerCase();
            const choices = [...document.getElementById(input.dataset.options).options]
                .map((option) => option.value)
                .filter((value) => value.toLocaleLowerCase().includes(query));
            const active = Math.max(choices.indexOf(input.value), 0);
            panel.replaceChildren(...choices.map((value, index) => {
                const option = document.createElement('button');
                option.type = 'button';
                option.id = `${panel.id}-${index}`;
                option.dataset.choice = value;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', String(index === active));
                option.className = 'block w-full whitespace-normal break-words px-3 py-2 text-left text-sm text-slate-800 hover:bg-province-green/10 focus:bg-province-green/10 focus:outline-none aria-selected:bg-province-green/10';
                option.textContent = value;
                return option;
            }));
            if (choices.length === 0) {
                const message = document.createElement('p');
                message.className = 'px-3 py-2 text-sm text-slate-600';
                message.textContent = 'No matching choices';
                panel.append(message);
            }
            panel.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            if (choices.length > 0) input.setAttribute('aria-activedescendant', `${panel.id}-${active}`);
            else input.removeAttribute('aria-activedescendant');
        };
        const chooseSuggestion = (option) => {
            const combo = option.closest('[data-combo]');
            const input = combo.querySelector('[data-searchable]');
            input.value = option.dataset.choice;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.focus();
            closeSuggestions(combo);
        };
        document.addEventListener('focusin', (event) => {
            if (event.target.matches('[data-searchable]')) showSuggestions(event.target, true);
        });
        document.addEventListener('input', (event) => {
            if (event.target.matches('[data-searchable]')) showSuggestions(event.target);
        });
        document.addEventListener('keydown', (event) => {
            if (!event.target.matches('[data-searchable]')) return;
            const input = event.target;
            const panel = input.closest('[data-combo]').querySelector('[data-suggestions]');
            const options = [...panel.querySelectorAll('[data-choice]')];
            if (event.key === 'Escape') {
                closeSuggestions(input.closest('[data-combo]'));
                return;
            }
            if (panel.hidden || options.length === 0) return;
            const active = options.findIndex((option) => option.getAttribute('aria-selected') === 'true');
            if (event.key === 'Enter') {
                event.preventDefault();
                chooseSuggestion(options[Math.max(active, 0)]);
            } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const next = (active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
                options[active]?.setAttribute('aria-selected', 'false');
                options[next].setAttribute('aria-selected', 'true');
                input.setAttribute('aria-activedescendant', options[next].id);
                options[next].scrollIntoView({ block: 'nearest' });
            }
        });
        document.addEventListener('click', (event) => {
            const option = event.target.closest('[data-choice]');
            if (option) chooseSuggestion(option);
            else if (event.target.matches('[data-searchable]')) showSuggestions(event.target, true);
            document.querySelectorAll('[data-combo]').forEach((combo) => {
                if (!combo.contains(event.target)) closeSuggestions(combo);
            });
        });
        // The lists are focusable (tabindex="-1") so clicking a list's scrollbar counts as staying inside it.
        document.addEventListener('focusout', (event) => {
            const combo = event.target.closest('[data-combo]');
            if (combo && !combo.contains(event.relatedTarget)) closeSuggestions(combo);
        });

        // Sending: lock the button against double-submits and, if it takes a while, show a loading overlay.
        document.querySelectorAll('form[action="{{ route('submission.store') }}"]').forEach((form) => {
            const button = form.querySelector('[data-submit-button]');
            const overlay = form.querySelector('[data-submit-overlay]');
            let overlayTimer = null;
            const setBusy = (busy) => {
                clearTimeout(overlayTimer);
                button.disabled = busy;
                button.setAttribute('aria-busy', String(busy));
                button.querySelector('[data-submit-idle]').hidden = busy;
                button.querySelector('[data-submit-busy]').hidden = !busy;
                if (busy) overlayTimer = setTimeout(() => { overlay.hidden = false; }, 800);
                else overlay.hidden = true;
            };
            form.addEventListener('submit', (event) => {
                if (button.disabled) {
                    event.preventDefault();
                    return;
                }
                setBusy(true);
            });
            // Coming back with the browser's Back button restores the page from cache: unlock it.
            window.addEventListener('pageshow', () => setBusy(false));
        });

        // "Read the full privacy notice": open the notice in its dialog instead of leaving the form.
        const privacyDialog = document.querySelector('[data-privacy-dialog]');
        document.querySelector('[data-open-privacy]')?.addEventListener('click', (event) => {
            event.preventDefault();
            privacyDialog.showModal();
            privacyDialog.scrollTop = 0;
        });
        privacyDialog.querySelectorAll('[data-close-privacy]').forEach((button) => button.addEventListener('click', () => privacyDialog.close()));
        privacyDialog.addEventListener('click', (event) => {
            if (event.target === privacyDialog) privacyDialog.close();
        });

        @if ($embedded)
            const submissionDialog = document.getElementById('submission-dialog');
            const submissionTrigger = document.querySelector('[data-open-submission]');

            submissionTrigger.addEventListener('click', (event) => {
                event.preventDefault();
                submissionDialog.showModal();
            });
            submissionDialog.querySelector('[data-close-submission]').addEventListener('click', () => submissionDialog.close());
            submissionDialog.addEventListener('click', (event) => {
                if (event.target === submissionDialog) submissionDialog.close();
            });

            @if ($errors->any() || request()->boolean('submit'))
                submissionDialog.showModal();
            @endif
        @endif
    </script>
@if (! $embedded)
</body>
</html>
@endif
