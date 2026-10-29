{{-- Password field with a real "Show password" button (Orchid's eye icon is a div that keyboards cannot reach). --}}
<div class="dts-password-field">
    <input id="{{ $id }}" type="password" name="{{ $name ?? 'password' }}" @class(['form-control', 'is-invalid' => $invalid ?? false]) required autocomplete="{{ $autocomplete ?? 'current-password' }}" placeholder="{{ $placeholder ?? __('Enter your password') }}" @if ($autofocus ?? false) autofocus @endif @if ($ariaInvalid ?? (bool) ($describedBy ?? null)) aria-invalid="true" @endif @if ($describedBy ?? null) aria-describedby="{{ $describedBy }}" @endif>
    <button type="button" class="dts-password-toggle" data-password-toggle aria-controls="{{ $id }}" aria-pressed="false" aria-label="{{ __('Show password') }}">
        <x-orchid-icon path="bs.eye" class="dts-password-show" />
        <x-orchid-icon path="bs.eye-slash" class="dts-password-hide" />
    </button>
</div>
