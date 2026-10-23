{{-- DTS email layout: green header with the SDO seal, the gold and brown stripe, the message card, a short footer.
     The seal is attached to the email (not linked), so it shows even where outside images are blocked; $embedder is
     the mail message, passed in by notifications/email.blade.php (a component cannot see the outer $message). --}}
@props(['embedder' => null])
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<tr>
<td class="header">
<a href="{{ config('app.url') }}" style="display: inline-block;">
@if ($embedder && is_file(public_path('images/qr-seal.jpg')))
<img src="{{ $embedder->embed(public_path('images/qr-seal.jpg')) }}" class="header-seal" width="64" height="64" alt="SDO Nueva Vizcaya seal">
@endif
<span class="header-title">{{ config('mail.from.name') }}</span>
<span class="header-subtitle">Document Tracking System</span>
</a>
</td>
</tr>
<tr>
<td class="header-stripe">&nbsp;</td>
</tr>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{ config('dts.contact.office') }} · {{ config('mail.from.name') }}
@if (filled(config('dts.contact.email')))
<br>Questions? Email {{ config('dts.contact.email') }}
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
