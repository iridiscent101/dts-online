{{-- "Get updates on this phone" (resources/js/client-updates.js): push notifications for this one document, no sign-in.
     Hidden until the script knows this browser can receive them and push is set up on the server.
     $highlight: right after a client submits, a larger prompt just under the "Document submitted" message. --}}
@php($highlight = $highlight ?? false)
<div @class([
        'dts-client-updates rounded-sm bg-white print:hidden',
        'mb-6 border-2 border-[#D9A520] bg-[#FFFBEA] p-4 sm:p-5 shadow-sm' => $highlight,
        'mt-4 border border-[#216E39]/25 p-3' => ! $highlight,
     ])
     data-client-updates data-url="{{ route('tracking.updates.store', $trackingNumber) }}" data-tracking="{{ $trackingNumber }}" hidden>
    <div class="flex flex-wrap items-center gap-3">
        <span @class(['grid shrink-0 place-items-center rounded-full bg-[#216E39] text-white', 'size-11' => $highlight, 'size-8' => ! $highlight])>
            <x-orchid-icon path="bs.bell" @class(['size-5' => $highlight, 'size-4' => ! $highlight]) />
        </span>
        <div class="min-w-0 grow">
            <p data-client-updates-off-text>
                @if ($highlight)
                    <strong class="block text-lg font-bold text-slate-900">Want updates on this phone?</strong>
                    <span class="text-sm text-slate-700">We will send a notification when your document is routed, released or completed. No sign-up needed.</span>
                @else
                    <span class="text-sm text-slate-700">Get a notification on this phone when your document moves or is ready.</span>
                @endif
            </p>
            <p data-client-updates-on-text hidden>
                <strong class="text-[#216E39]">Updates are on for this phone.</strong>
                <span class="text-sm text-slate-700">We will notify you when your document moves or is ready.</span>
            </p>
        </div>
        <button type="button" data-client-updates-enable @class([
            'inline-flex items-center gap-2 rounded-sm bg-[#216E39] font-semibold text-white hover:bg-[#18542B]',
            'min-h-12 w-full justify-center px-5 text-base sm:w-auto' => $highlight,
            'min-h-11 px-4 text-sm' => ! $highlight,
        ])>
            Get updates on this phone
        </button>
        <button type="button" data-client-updates-disable class="inline-flex min-h-11 items-center rounded-sm px-3 text-sm font-semibold text-[#216E39] underline underline-offset-2 hover:bg-[#F8F4E7]" hidden>
            Turn off
        </button>
    </div>
    <p class="mt-2 text-sm text-red-800" data-client-updates-error role="alert" hidden></p>
</div>
