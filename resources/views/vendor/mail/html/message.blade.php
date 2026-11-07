{{-- The mail message is passed in by notifications/email.blade.php so sent emails can embed both logos. --}}
@props(['embedder' => null])
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<tr>
<td class="header">
<a href="{{ config('app.url') }}" style="display: inline-block;">
<span class="header-logos">
@if (is_file(public_path('images/qr-seal.jpg')))
<img src="{{ $embedder ? $embedder->embedData(file_get_contents(public_path('images/qr-seal.jpg')), 'qr-seal.jpg') : asset('images/qr-seal.jpg') }}" class="header-seal" width="64" height="64" alt="SDO Nueva Vizcaya seal">
@endif
@if (is_file(public_path('images/Deped.png')))
<img src="{{ $embedder ? $embedder->embedData(file_get_contents(public_path('images/Deped.png')), 'Deped.png') : asset('images/Deped.png') }}" class="header-deped" width="96" height="50" alt="DepEd logo">
@endif
</span>
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
