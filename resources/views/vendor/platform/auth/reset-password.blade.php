@extends('platform::auth')
@section('title', __('Reset password'))

@section('content')
    <h1 class="dts-auth-title">{{ __('Reset your password') }}</h1>
    <p class="dts-auth-subtitle">{{ __('Choose a new password for your account.') }}</p>

    <div id="reset-errors" @class(['alert', 'alert-danger', 'd-flex', 'gap-2', 'align-items-start', 'mb-3', 'd-none' => ! $errors->any()]) role="alert" tabindex="-1">
        <x-orchid-icon path="bs.exclamation-circle" class="mt-1 shrink-0"/>
        <div data-reset-messages>
            @foreach ($errors->all() as $message)
                <p class="mb-0">{{ $message }}</p>
            @endforeach
        </div>
    </div>

    <form method="POST" action="{{ route('password.update') }}" data-dts-reset-form>
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <input type="hidden" name="email" value="{{ old('email', $request->query('email')) }}">

        @php($passwordInvalid = $errors->has('password'))
        <div class="mb-3">
            <label class="form-label" for="password">{{ __('New password') }}</label>
            @include('platform::partials.password-input', [
                'id' => 'password',
                'autocomplete' => 'new-password',
                'placeholder' => __('Type a new password'),
                'autofocus' => true,
                'invalid' => $passwordInvalid,
                'ariaInvalid' => $passwordInvalid,
                'describedBy' => ($errors->any() ? 'reset-errors ' : '').'password-help',
            ])
            <p class="form-text dts-forgot-hint" id="password-help">{{ \App\Models\User::PASSWORD_HINT }}</p>
        </div>

        <div class="mb-3">
            <label class="form-label" for="password_confirmation">{{ __('Confirm new password') }}</label>
            @include('platform::partials.password-input', [
                'id' => 'password_confirmation',
                'name' => 'password_confirmation',
                'autocomplete' => 'new-password',
                'placeholder' => __('Type it again'),
                'invalid' => $passwordInvalid,
                'ariaInvalid' => $passwordInvalid,
                'describedBy' => $errors->any() ? 'reset-errors' : null,
            ])
        </div>

        <div class="mb-3">
            <label class="form-label" for="totp_code">{{ __('Authenticator code') }}</label>
            <input id="totp_code" type="text" name="totp_code" class="form-control" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" aria-describedby="totp-help" @if ($errors->has('totp_code')) aria-invalid="true" @endif>
            <p class="form-text" id="totp-help">{{ __('Enter the six-digit code if you enabled an authenticator for password recovery in My account.') }}</p>
        </div>

        <button type="submit" class="btn btn-default dts-auth-submit" data-busy-label="{{ __('Resetting password…') }}">
            <x-orchid-icon path="bs.shield-lock"/>
            <span data-button-label>{{ __('Reset password') }}</span>
        </button>
    </form>

    <p class="dts-auth-help">
        <x-orchid-icon path="bs.arrow-left"/>
        <a href="{{ route('platform.login') }}" class="d-inline-block py-1">{{ __('Back to sign in') }}</a>
    </p>
@endsection
