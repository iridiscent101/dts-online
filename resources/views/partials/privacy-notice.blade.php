{{-- The full privacy notice (Data Privacy Act of 2012), shared by the "Read the full privacy notice" dialog on the send
     form and the /privacy page. $headingTag: the level of each section heading (h3 inside the dialog, h2 on the page).
     Contact details come from config/dts.php; empty ones are left out. --}}
@php
    $headingTag = $headingTag ?? 'h2';
    $contact = config('dts.contact');
    $headingClass = 'mt-8 text-lg font-semibold text-slate-900 first:mt-0';
@endphp
<div class="max-w-[65ch] text-base leading-7 text-slate-700">
    <{{ $headingTag }} class="{{ $headingClass }}">Who we are</{{ $headingTag }}>
    <p class="mt-2">DocuTrackS is the document tracking system of the {{ $contact['office'] }}. The Records Unit uses it to receive documents, send them to the right offices and show you where they are.</p>

    <{{ $headingTag }} class="{{ $headingClass }}">What we collect</{{ $headingTag }}>
    <ul class="mt-2 list-disc space-y-1 pl-5">
        <li>Your name and your school or office.</li>
        <li>Your email address, only if you give one for updates.</li>
        <li>The document’s type, title, mode of submission and your instructions for Records.</li>
        @if (config('app.document_uploads_enabled'))
            <li>The file you attach, if you attach one.</li>
        @endif
        <li>When the document is released: the name and signature of the person who receives it.</li>
        <li>If you turn on updates on your phone: a code from your browser that lets us send notifications for this one document.</li>
    </ul>

    <{{ $headingTag }} class="{{ $headingClass }}">Why we use it</{{ $headingTag }}>
    <p class="mt-2">Only to process and track your document: to register it, send it to the offices that act on it, tell you when it moves, and keep the official record of who handled it and who received it.</p>

    <{{ $headingTag }} class="{{ $headingClass }}">Who can see it</{{ $headingTag }}>
    <p class="mt-2">SDO staff who handle the document see its full record. Only signed-in staff can open it, and attached files are never shown on the public pages.</p>
    <p class="mt-2">Anyone who has the tracking number can see the document’s history on the tracking page: the offices it went to, the status and dates, the SDO staff who handled each step, their remarks, your instructions for Records, and the name of the person who received or signed for it. Your own name, email address and signature are not shown there, and neither is the title of a document you sent online. Keep your tracking number private.</p>

    <{{ $headingTag }} class="{{ $headingClass }}">Email and phone updates</{{ $headingTag }}>
    <p class="mt-2">Both are optional. If you give an email address, we email you each time your document is routed to an office. If you turn on updates on your phone, the notifications are delivered through Google’s Firebase Cloud Messaging; you can turn them off on the tracking page, and the code from your browser is then deleted.</p>

    <{{ $headingTag }} class="{{ $headingClass }}">Cookies</{{ $headingTag }}>
    <p class="mt-2">The site uses only the cookies it needs to work: one that keeps your visit together and one that protects the form from misuse. There are no advertising or tracking cookies.</p>

    <{{ $headingTag }} class="{{ $headingClass }}">How long we keep it</{{ $headingTag }}>
    <p class="mt-2">As long as government records rules require for the document. Your details stay with that record and are not used for anything else.</p>

    <{{ $headingTag }} class="{{ $headingClass }}">Your rights</{{ $headingTag }}>
    <p class="mt-2">Under the Data Privacy Act of 2012 (Republic Act No. 10173) you have the right to:</p>
    <ul class="mt-2 list-disc space-y-1 pl-5">
        <li>be told how your information is used;</li>
        <li>see the information we hold about you;</li>
        <li>have wrong or incomplete information corrected;</li>
        <li>object to its use, and ask for it to be blocked or deleted where the law allows;</li>
        <li>get a copy of it in a usable format;</li>
        <li>be paid damages if it is misused; and</li>
        <li>file a complaint with the National Privacy Commission.</li>
    </ul>

    <{{ $headingTag }} class="{{ $headingClass }}">Contact us</{{ $headingTag }}>
    <p class="mt-2">To use any of these rights, or to ask about your information, contact the Division’s Data Protection Officer through the Records Unit.</p>
    @if (filled($contact['email']) || filled($contact['phone']) || filled($contact['address']))
        <dl class="mt-3 grid grid-cols-[6rem_minmax(0,1fr)] gap-x-4 gap-y-1">
            @if (filled($contact['email']))
                <dt class="font-semibold text-slate-800">Email</dt>
                <dd><a href="mailto:{{ $contact['email'] }}" class="font-semibold text-province-green underline underline-offset-2 hover:text-province-green-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-province-green [overflow-wrap:break-word]">{{ $contact['email'] }}</a></dd>
            @endif
            @if (filled($contact['phone']))
                <dt class="font-semibold text-slate-800">Phone</dt>
                <dd>{{ $contact['phone'] }}</dd>
            @endif
            @if (filled($contact['address']))
                <dt class="font-semibold text-slate-800">Address</dt>
                <dd>{{ $contact['address'] }}</dd>
            @endif
        </dl>
    @endif
</div>
