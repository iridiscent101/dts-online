<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>Signing kiosk | DocuTrackS</title>
    <link rel="icon" href="{{ asset('images/icon.png') }}" type="image/png">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/signature-pad.js'])
</head>
{{-- The laptop outside the Records Unit (KioskController). It waits for a clerk's "Send to kiosk", shows only the
     tracking number and type of that one document, and sends back the recipient's name and signature. --}}
<body class="min-h-screen bg-[#F8F4E7] font-sans text-[#26332B] antialiased" data-kiosk data-current-url="{{ route('kiosk.current') }}">
    <div class="fixed inset-x-0 top-0 grid h-1.5 grid-cols-[2fr_1fr_1fr]" aria-hidden="true">
        <span class="bg-[#216E39]"></span>
        <span class="bg-[#F2C230]"></span>
        <span class="bg-[#86562D]"></span>
    </div>

    <header class="border-b border-province-line bg-white">
        <div class="mx-auto flex min-h-20 w-full max-w-4xl items-center justify-between gap-4 px-6 py-4">
            <div class="flex min-w-0 items-center gap-3">
                <img src="{{ asset('images/icon.png') }}" alt="" width="56" height="56" class="size-14 shrink-0 rounded-xl">
                <span class="min-w-0">
                    <strong class="block text-3xl font-bold leading-none tracking-tight">Docu<span class="text-[#86562D]">TrackS</span></strong>
                    <span class="block text-sm font-semibold text-slate-600">SDO – Nueva Vizcaya · Records Unit</span>
                </span>
            </div>
            <p class="text-right text-base font-semibold text-[#216E39]">Document release<br>signing</p>
        </div>
    </header>

    <main class="mx-auto w-full max-w-4xl px-6 py-10">
        {{-- Waiting for the Records Unit --}}
        <section data-kiosk-idle class="grid place-items-center gap-5 rounded-md border border-province-line bg-white px-6 py-16 text-center shadow-sm" aria-live="polite">
            {{-- Waiting animation: a ring turning around the hourglass, and three dots under the text (still for reduced motion). --}}
            <span class="relative grid size-28 place-items-center" aria-hidden="true">
                <span class="absolute inset-0 rounded-full border-[6px] border-[#216E39]/15 border-t-[#216E39] motion-safe:animate-spin"></span>
                <span class="grid size-20 place-items-center rounded-full bg-[#E8F1E6] text-[#216E39]"><x-orchid-icon path="bs.hourglass-split" class="size-10" /></span>
            </span>
            <h1 class="text-3xl font-bold text-slate-900">Please wait</h1>
            <p class="max-w-xl text-xl leading-8 text-slate-700">The Records Unit staff will call your name. Your document will appear on this screen when it is ready for you to sign.</p>
            <span class="flex items-center gap-2 text-lg font-semibold text-[#216E39]">
                Waiting for your document
                <span class="flex gap-1.5 pt-2" aria-hidden="true">
                    <span class="size-2.5 rounded-full bg-[#216E39] motion-safe:animate-bounce"></span>
                    <span class="size-2.5 rounded-full bg-[#216E39] motion-safe:animate-bounce [animation-delay:150ms]"></span>
                    <span class="size-2.5 rounded-full bg-[#216E39] motion-safe:animate-bounce [animation-delay:300ms]"></span>
                </span>
            </span>
            <p data-kiosk-connection class="text-base font-semibold text-red-700" role="alert" hidden>Cannot reach the system. Checking again…</p>
        </section>

        {{-- Signing --}}
        <form data-kiosk-form class="grid gap-6 rounded-md border border-province-line bg-white p-6 shadow-sm sm:p-8" novalidate hidden aria-labelledby="kiosk-sign-heading">
            <div>
                <h1 id="kiosk-sign-heading" class="text-3xl font-bold text-slate-900">Sign for your document</h1>
                <p class="mt-2 text-lg text-slate-700">Check the tracking number, then sign inside the box. Typing your name is optional.</p>
            </div>

            <dl class="grid gap-4 rounded-sm border border-[#216E39]/25 bg-[#F8F4E7] p-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm font-semibold uppercase tracking-wide text-slate-600">Tracking number</dt>
                    <dd data-kiosk-tracking class="mt-1 font-mono text-2xl font-extrabold text-slate-900 [overflow-wrap:anywhere]"></dd>
                </div>
                <div>
                    <dt class="text-sm font-semibold uppercase tracking-wide text-slate-600">Type of document</dt>
                    <dd data-kiosk-type class="mt-1 text-xl font-semibold text-slate-900"></dd>
                </div>
            </dl>

            <div>
                <label for="kiosk-name" class="block text-lg font-semibold text-slate-800">Your full name <span class="text-base font-normal text-slate-600">(optional)</span></label>
                <input id="kiosk-name" name="recipient_name" maxlength="255" autocomplete="off" class="mt-2 block min-h-14 w-full rounded-sm border-2 border-[#B3843F] bg-white px-4 text-xl text-slate-900 focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-[#F2C230]">
            </div>

            <div class="grid gap-2">
                <span id="kiosk-signature-label" class="text-lg font-semibold text-slate-800">Your signature <span class="text-red-700">*</span></span>
                <dts-signature-pad class="dts-kiosk-signature" data-required-message="Please sign inside the box first.">
                    <div class="dts-signature-box">
                        <canvas aria-labelledby="kiosk-signature-label" aria-describedby="kiosk-signature-hint" role="img"></canvas>
                        <span class="dts-signature-line" aria-hidden="true">Sign above this line</span>
                        <input class="dts-signature-value" name="recipient_signature" data-signature-value tabindex="-1" aria-hidden="true" required>
                    </div>
                    <div class="dts-signature-footer">
                        <p class="dts-signature-hint text-base" id="kiosk-signature-hint" data-signature-hint>Hold the mouse button down and write your signature.</p>
                        <button type="button" class="inline-flex min-h-12 items-center rounded-sm border-2 border-[#216E39] bg-white px-5 text-lg font-semibold text-[#216E39] hover:bg-[#EEF5EC] disabled:opacity-50" data-signature-clear disabled>Clear</button>
                    </div>
                </dts-signature-pad>
            </div>

            <p data-kiosk-error class="rounded-sm border border-red-300 bg-red-50 px-4 py-3 text-lg font-semibold text-red-800" role="alert" hidden></p>

            <button type="submit" data-kiosk-submit class="inline-flex min-h-14 w-full items-center justify-center gap-3 rounded-sm bg-[#216E39] px-6 text-xl font-bold text-white hover:bg-[#18542B] focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-[#F2C230] disabled:cursor-wait disabled:opacity-80">
                <x-orchid-icon path="bs.pen" class="size-6" /> <span data-kiosk-submit-label>Submit signature</span>
            </button>
        </form>

        {{-- Done --}}
        <section data-kiosk-done class="grid place-items-center gap-5 rounded-md border border-emerald-300 bg-emerald-50 px-6 py-16 text-center" role="status" hidden>
            <span class="grid size-20 place-items-center rounded-full bg-[#216E39] text-white"><x-orchid-icon path="bs.check-lg" class="size-10" /></span>
            <h1 class="text-3xl font-bold text-emerald-900">Thank you</h1>
            <p class="max-w-xl text-xl leading-8 text-emerald-900">Your signature was sent to the Records Unit. Please wait for the staff to hand you your document.</p>
        </section>

        {{-- The clerk cancelled, or the request expired, while it was on screen --}}
        <section data-kiosk-cancelled class="grid place-items-center gap-4 rounded-md border border-amber-300 bg-amber-50 px-6 py-14 text-center" role="status" hidden>
            <h1 class="text-2xl font-bold text-amber-900">This signing was cancelled</h1>
            <p class="max-w-xl text-lg text-amber-900">Please ask the Records Unit staff.</p>
        </section>
    </main>

    <footer class="mx-auto flex w-full max-w-4xl justify-end px-6 pb-6">
        {{-- For the staff who set up the laptop. --}}
        <form method="POST" action="{{ route('platform.logout') }}">
            @csrf
            <button type="submit" class="min-h-11 px-2 text-sm text-slate-600 underline hover:text-slate-700">Sign out of the kiosk</button>
        </form>
    </footer>

    <script nonce="{{ Vite::cspNonce() }}">
        (() => {
            const body = document.body;
            const idle = document.querySelector('[data-kiosk-idle]');
            const form = document.querySelector('[data-kiosk-form]');
            const done = document.querySelector('[data-kiosk-done]');
            const cancelled = document.querySelector('[data-kiosk-cancelled]');
            const connection = document.querySelector('[data-kiosk-connection]');
            const error = form.querySelector('[data-kiosk-error]');
            const submit = form.querySelector('[data-kiosk-submit]');
            const submitLabel = form.querySelector('[data-kiosk-submit-label]');
            const nameInput = form.querySelector('[name="recipient_name"]');
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            let current = null;
            let pauseUntil = 0;

            const show = (section) => [idle, form, done, cancelled].forEach((item) => { item.hidden = item !== section; });

            const resetForm = () => {
                form.reset();
                form.querySelector('dts-signature-pad')?.clear?.();
                error.hidden = true;
                submit.disabled = false;
                submitLabel.textContent = 'Submit signature';
            };

            const open = (request) => {
                current = request;
                resetForm();
                form.querySelector('[data-kiosk-tracking]').textContent = request.tracking_number;
                form.querySelector('[data-kiosk-type]').textContent = request.document_type || '—';
                nameInput.value = request.recipient_name || '';
                show(form);
                nameInput.focus();
            };

            const backToIdle = (section, seconds) => {
                current = null;
                show(section);
                pauseUntil = Date.now() + seconds * 1000;
                setTimeout(() => { if (!current) show(idle); }, seconds * 1000);
            };

            async function poll() {
                if (Date.now() >= pauseUntil && !submit.disabled) {
                    try {
                        const response = await fetch(body.dataset.currentUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                        // Signed out (session ended): reload, which shows the sign-in page and comes back here afterwards.
                        if (response.status === 401 || response.status === 419 || response.redirected) { window.location.reload(); return; }
                        if (!response.ok) throw new Error();
                        connection.hidden = true;
                        const { request } = await response.json();
                        if (request && request.id !== current?.id) open(request);
                        else if (!request && current) backToIdle(cancelled, 8);
                    } catch {
                        connection.hidden = false;
                    }
                }
                setTimeout(poll, 3000);
            }

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (!current || submit.disabled) return;
                const invalid = [...form.querySelectorAll('input')].find((field) => !field.checkValidity());
                if (invalid) {
                    invalid.reportValidity?.();
                    if (invalid === nameInput) nameInput.focus();
                    return;
                }
                submit.disabled = true;
                submitLabel.textContent = 'Sending…';
                error.hidden = true;
                try {
                    const response = await fetch(current.sign_url, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                        body: new FormData(form),
                    });
                    const result = await response.json().catch(() => ({}));
                    if (response.ok) { submit.disabled = false; backToIdle(done, 10); return; }
                    if (response.status === 409) { submit.disabled = false; backToIdle(cancelled, 8); return; }
                    error.textContent = result.errors ? Object.values(result.errors).flat()[0] : (result.message || 'Could not send your signature. Please try again.');
                } catch {
                    error.textContent = 'Could not reach the system. Please try again.';
                }
                error.hidden = false;
                submit.disabled = false;
                submitLabel.textContent = 'Submit signature';
            });

            poll();
        })();
    </script>
</body>
</html>
